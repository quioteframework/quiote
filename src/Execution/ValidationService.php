<?php

namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Util\AgaviToolkit;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Tiny immutable description of what we validated (for debugging/parity tests).
 */
final class ValidationTrace
{
    public function __construct(
        public readonly string $module,
        public readonly string $action,
        public readonly array $validatorsLoaded = [],
        public readonly ?string $configFile = null,
    ) {}
}

/**
 * Adapter around legacy validation logic to enable container-less execution.
 * Phase 1: call AgaviAction::validate directly (manual validators unsupported without container).
 */
class ValidationService
{
    private $currentContext = null; // holds AgaviContext for compiled validator config
    public function __construct(private ?AgaviValidationManager $manager = null) {}

    public function getValidationManager(): ?AgaviValidationManager
    {
        return $this->manager;
    }

    // Expose context to compiled validator config (expects $this->getContext())
    public function getContext()
    {
        return $this->currentContext;
    }

    /**
     * Perform validation similar to AgaviExecutionContainer::performValidation but without a container.
     * Steps:
     * 1. Load XML validation config (validators, dependencies) if present.
     * 2. Allow action to register manual validators via register[Method]Validators().
     * 3. Execute validator manager then action validate[Method]().
     * 4. Return ValidationResult with collected error messages (if retrievable) and a ValidationTrace meta object.
     */
    public function validate(AgaviAction $action, AgaviWebRequest $request, string $moduleName = '', string $actionName = '', string $method = ''): ValidationResult
    {
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();

        // Normalize method tokens:
        //  - $xmlMethod (lowercase) is what compiled validator config compares against (if($method == 'read')).
        //  - $normalizedMethod (Ucfirst) is used to construct register/validate method names (validateRead, registerReadValidators).
        $xmlMethod = strtolower($method ?: 'read');
        $normalizedMethod = ucfirst($xmlMethod);
        // Overwrite local $method variable so included compiled config sees lowercase variant.
        $method = $xmlMethod; // variable name intentionally preserved for compiled config scope
        $validationManager = $this->manager;
        if (!$validationManager) {
            // Build a lightweight manager via context from action (container may be ActionInitContext or full container)
            $ctx = $action->getContext();
            $validationManager = $ctx->createInstanceFor('validation_manager');
        } else {
            $validationManager->clear();
        }
        // Inject the VM into the action's init context so that manual validate*()
        // methods (which call $this->getInitContext()->getValidationManager()) see
        // the same errors and exports that XML validators produce.
        $initCtx = $action->getInitContext();
        if ($initCtx !== null && method_exists($initCtx, 'setValidationManager')) {
            $initCtx->setValidationManager($validationManager);
        }
        $validatorsLoaded = [];
        $configFile = null;
        // 1. Load XML validation config if we have module/action names
        if ($moduleName && $actionName) {
            // Convert dots to slashes for file system paths (e.g., Resources.Data -> Resources/Data)
            $actionNamePath = str_replace('.', '/', $actionName);
            $configFile = AgaviToolkit::evaluateModuleDirective($moduleName, 'agavi.validate.path', [
                'moduleName' => $moduleName,
                'actionName' => $actionNamePath,
            ]);
            if (\Agavi\Util\DebugFlags::$validation) {
                try { $logger?->debug('[ValidationService][probe] resolve configFile=' . $configFile . ' readable=' . (is_readable($configFile)?'1':'0') . ' methodToken=' . $method . ' module=' . $moduleName . ' action=' . $actionName); } catch(\Throwable) {}
            }
            if (is_readable($configFile)) {
                // Provide expected variables & context for compiled config file
                $this->currentContext = $action->getContext();
                if (\Agavi\Util\DebugFlags::$validation) { $logger?->debug('[ValidationService][probe] including compiled validators (pre-checkCache)'); }
                if (\Agavi\Util\DebugFlags::$validation) {
                    try { $logger?->debug('[ValidationService][probe] pre-checkCache methodHex=' . bin2hex((string)$method) . ' type=' . gettype($method)); } catch(\Throwable) {}
                }
                if (defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
                    $incFile = AgaviAPCuConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if (\Agavi\Util\DebugFlags::$validation) { $logger?->debug('[ValidationService][probe] APCu checkConfig returned ' . (str_starts_with($incFile, 'APCU:') ? 'APCU:...' : $incFile)); }
                    if (str_starts_with($incFile, 'APCU:')) {
                        eval('?>' . substr($incFile, 5));
                    } else {
                        require($incFile);
                    }
                    if (\Agavi\Util\DebugFlags::$validation) { 
                        try { 
                            $statLine = '[ValidationService][probe] post-require APCu childCount=' . (is_array($validationManager->getChilds())?count($validationManager->getChilds()):'na');
                            if (file_exists($incFile)) { $statLine .= ' real=' . realpath($incFile) . ' mtime=' . filemtime($incFile) . ' size=' . filesize($incFile); }
                            $logger?->debug($statLine);
                        } catch(\Throwable $e) {}
                    }
                } else {
                    $incFile = AgaviConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if (\Agavi\Util\DebugFlags::$validation) { $logger?->debug('[ValidationService][probe] disk checkConfig returned ' . $incFile . ' exists=' . (file_exists($incFile)?'1':'0')); }
                    require($incFile);
                    if (\Agavi\Util\DebugFlags::$validation) { 
                        try { 
                            $statLine = '[ValidationService][probe] post-require disk childCount=' . (is_array($validationManager->getChilds())?count($validationManager->getChilds()):'na');
                            if (file_exists($incFile)) { 
                                $real = realpath($incFile); $mtime = @filemtime($incFile); $size = @filesize($incFile);
                                $contents = @file_get_contents($incFile);
                                $hash = $contents !== false ? sha1($contents) : 'no-read';
                                $snippet = $contents !== false ? substr($contents, 0, 180) : '';
                                $snippet = str_replace(["\n","\r"], ['\\n',''], $snippet);
                                $statLine .= ' real=' . $real . ' mtime=' . $mtime . ' size=' . $size . ' sha1=' . $hash . ' head=' . $snippet;
                            }
                            $logger?->debug($statLine);
                        } catch(\Throwable $e) {}
                    }
                }
                $validatorsLoaded = array_map(fn($v) => $v->getName(), $validationManager->getChilds());
                if (\Agavi\Util\DebugFlags::$validation) {
                    try {
                        $logger?->debug('[ValidationService][validate] loadedValidators=' . (empty($validatorsLoaded) ? 'none' : implode(',', $validatorsLoaded)) . ' file=' . $configFile . ' method=' . $method);
                    } catch(\Throwable $e) {}
                }
            } else {
                if (\Agavi\Util\DebugFlags::$validation) {
                    try { $logger?->debug('[ValidationService][validate] no readable config file at ' . $configFile); } catch(\Throwable) {}
                }
            }
        }
        // 2. Manual validator registration method on action (mirrors container logic)
        $registerMethod = 'register' . $normalizedMethod . 'Validators';
        if (!is_callable([$action, $registerMethod])) {
            $registerMethod = 'registerValidators';
        }
        if (is_callable([$action, $registerMethod])) {
            $action->$registerMethod();
            $validatorsLoaded = array_map(fn($v) => $v->getName(), $validationManager->getChilds());
        }

        // NOTE: We intentionally do NOT relax strict mode when no validators are present.
        // Legacy semantics: with zero validators in strict mode, request parameters are cleared
        // before manual validate*() runs. This enforces that XML validators (or manual registrations)
        // must define every parameter intended for use in manual validation.

        // 3. Execute validators
        $ok = true;
        if (\Agavi\Util\DebugFlags::$validation) {
            try {
                $logger?->debug('[ValidationService][validate] About to execute validators, childCount=' . count($validationManager->getChilds()));
            } catch(\Throwable) {}
        }
        try {
            $ok = (bool)$validationManager->execute($request);
            if (\Agavi\Util\DebugFlags::$validation) {
                try {
                    $logger?->debug('[ValidationService][validate] Validators execute() returned: ' . ($ok ? 'true' : 'false'));
                } catch(\Throwable) {}
            }
        } catch (\Throwable $e) {
            if (\Agavi\Util\DebugFlags::$validation) {
                try {
                    $logger?->debug('[ValidationService][validate] Validators execute() threw exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                } catch(\Throwable) {}
            }
            return ValidationResult::failure(['exception' => $e->getMessage()]);
        }
        if (\Agavi\Util\DebugFlags::$validation) {
            try {
                $childs = $validationManager->getChilds();
                $names = [];
                foreach ($childs as $cv) { $names[] = method_exists($cv,'getName') ? $cv->getName() : 'unknown'; }
                $logger?->debug('[ValidationService][validate] executeResult=' . ($ok?'1':'0') . ' childCount=' . count($names) . ' names=' . implode(',', $names));
            } catch(\Throwable $e) {}
        }
        // 4. Manual action validation (validate[Method])
        // Use the context's request which may have been updated by pruneParametersToValidated()
        // during VM execute(). This ensures the action's validate method sees the post-prune
        // request and any parameters it sets via setParameter() propagate correctly.
        $currentRequest = $action->getContext()->getRequest();
        $validateMethod = 'validate' . $normalizedMethod;
        if (!is_callable([$action, $validateMethod])) {
            $validateMethod = 'validate';
        }
        if (\Agavi\Util\DebugFlags::$validation) {
            try {
                $logger?->debug('[ValidationService][validate] About to call action->' . $validateMethod . '() on ' . get_class($action));
            } catch(\Throwable) {}
        }
        try {
            $manualOk = (bool)$action->$validateMethod($currentRequest);
            if (\Agavi\Util\DebugFlags::$validation) {
                try {
                    $logger?->debug('[ValidationService][validate] action->' . $validateMethod . '() returned ' . ($manualOk ? 'true' : 'false'));
                } catch(\Throwable) {}
            }
        } catch (\Throwable $e) {
            if (\Agavi\Util\DebugFlags::$validation) {
                try {
                    $logger?->debug('[ValidationService][validate] action->' . $validateMethod . '() threw exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                    $logger?->debug('[ValidationService][validate] Stack trace: ' . $e->getTraceAsString());
                } catch(\Throwable) {}
            }
            if (getenv('DEBUG_TESTS')) {
                error_log('[TestDebug][ValidationService] exception in ' . $validateMethod . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            }
            return ValidationResult::failure(['exception' => $e->getMessage()]);
        }
        $final = $ok && $manualOk;
        $errors = [];
        if (!$final) {
            try {
                $report = $validationManager->getReport();
                if ($report) {
                    $errors = $report->getErrorMessages();
                }
            } catch (\Throwable) { /* ignore */
            }
        }
        // Embed trace metadata for debugging (caller can ignore)
        $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
        return new ValidationResult($final, ['errors' => $errors, 'trace' => $trace]);
    }

    /** Execute only XML + registered validators (skip action validate* methods). */
    public function xmlOnlyValidate(AgaviAction $action, AgaviWebRequest $request, string $moduleName, string $actionName, string $method = ''): ValidationResult
    {
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();
        // NOTE: Compiled validator configuration files gate validator registration using
        //   if($method == 'write') { ... }
        // Historically AgaviExecutionContainer set a local $method variable before including
        // the compiled config. The validate() method already reproduces this behavior, but
        // xmlOnlyValidate previously forgot to normalize and overwrite the local $method.
        // As a result, all validators with method attributes (e.g. method="write") were skipped
        // because the condition evaluated against an empty or incorrect value.
        // We replicate the logic from validate(): lowercase token for XML gating while preserving
        // the passed argument semantics.
        $xmlMethod = strtolower($method ?: 'read');
        $method = $xmlMethod; // expose lowercase token to included compiled config scope

        $vd = \Agavi\Util\DebugFlags::$validation;
        if ($vd) {
            $logger?->debug("[ValidationService] xmlOnlyValidate for " . ($moduleName ?: 'no_module') . "/" . ($actionName ?: 'no_action') . " method=" . ($method ?: 'no_method'));
        }
        $validationManager = $this->manager;
        if (!$validationManager) {
            $ctx = $action->getContext();
            $validationManager = $ctx->createInstanceFor('validation_manager');
        } else {
            $validationManager->clear();
        }
        $validatorsLoaded = [];
        $configFile = null;
        if ($moduleName && $actionName) {
            // Convert dots to slashes for file system paths (e.g., Resources.Data -> Resources/Data)
            $actionNamePath = str_replace('.', '/', $actionName);
            $configFile = \Agavi\Util\AgaviToolkit::evaluateModuleDirective($moduleName, 'agavi.validate.path', ['moduleName' => $moduleName, 'actionName' => $actionNamePath]);
            if ($vd) {
                $logger?->debug("[ValidationService] Validation config file = " . $configFile . ", is_readable=" . (is_readable($configFile) ? "1":"0"));
            }
            if (is_readable($configFile)) {
                $this->currentContext = $action->getContext();
                
                if (defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
                    $logger?->debug("[ValidationService] Loading " . $method . " validators from APCu");
                    $cacheResult = \Agavi\Config\AgaviAPCuConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if (str_starts_with($cacheResult, 'APCU:')) {
                        eval('?>' . substr($cacheResult, 5));
                    } else {
                        require($cacheResult);
                    }
                } else {
                    if ($vd) {
                        $logger?->debug("[ValidationService] Loading " . $method . " validators from disk");
                    }
                    require(\Agavi\Config\AgaviConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                }
                $validatorsLoaded = array_map(fn($v) => $v->getName(), $validationManager->getChilds());
                if ($vd) {
                    AgaviDebugLogger::debug('[ValidationService] Loaded validators: ');
                    AgaviDebugLogger::debug(count($validatorsLoaded) > 0 ? implode(', ', $validatorsLoaded) : 'none');
                }
            }
        }
        // Execute validators only
        $ok = true;
        try {
            /** @var AgaviValidationManager $validationManager */
            if ($vd) {
                $modeDbg = method_exists($validationManager, 'getParameter') ? $validationManager->getParameter('mode', 'strict') : 'n/a';
                $logger?->debug("[ValidationService] Running validation (mode=" . $modeDbg . ")");
            }
            $ok = (bool)$validationManager->execute($request);
        } catch (\Throwable $e) {
            if ($vd) {
                $logger?->debug('[ValidationService] execute() threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
            $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
            return ValidationResult::failure(['errors' => ['xml_exception: ' . $e->getMessage()], 'trace' => $trace]);
        }
        if ($vd) {
            // Emit a compact report snapshot for diagnostics
            try {
                $report = $validationManager->getReport();
                $resultSev = $report?->getResult();
                $incidents = $report?->getIncidents() ?? [];
                $childCount = is_array($validationManager->getChilds()) ? count($validationManager->getChilds()) : 0;
                $mode = method_exists($validationManager, 'getParameter') ? $validationManager->getParameter('mode') : 'n/a';
                $logger?->debug('[ValidationService] summary ok=' . ($ok ? '1' : '0') . ' childValidators=' . $childCount . ' mode=' . $mode . ' reportSeverity=' . (is_null($resultSev) ? 'null' : $resultSev) . ' incidents=' . count($incidents));
                foreach ($incidents as $i => $incident) {
                    try {
                        $vName = method_exists($incident->getValidator(), 'getName') ? $incident->getValidator()->getName() : 'null';
                    } catch (\Throwable) { $vName = 'null'; }
                    $sev = method_exists($incident, 'getSeverity') ? $incident->getSeverity() : 'n/a';
                    $errs = [];
                    try { foreach($incident->getErrors() as $e) { $errs[] = $e->getMessage(); } } catch (\Throwable) {}
                    $args = [];
                    try { foreach($incident->getArguments() as $a) { $args[] = $a->getName(); } } catch (\Throwable) {}
                    AgaviDebugLogger::debug('[ValidationService] incident#' . $i . ' validator=' . $vName . ' severity=' . $sev . ' args=' . implode(',', $args) . ' messages=' . json_encode($errs));
                }
                // Also print a quick view of validator config (name -> key params)
                foreach ($validationManager->getChilds() as $v) {
                    try {
                        $name = method_exists($v, 'getName') ? $v->getName() : 'unknown';
                        $source = method_exists($v, 'getParameter') ? $v->getParameter('source') : 'n/a';
                        $required = method_exists($v, 'getParameter') ? var_export($v->getParameter('required', true), true) : 'n/a';
                        $base = method_exists($v, 'getParameter') ? (string)$v->getParameter('base', '') : '';
                        AgaviDebugLogger::debug('[ValidationService] validator cfg name=' . $name . ' source=' . $source . ' required=' . $required . ' base=' . $base);
                    } catch (\Throwable) { /* ignore */ }
                }
            } catch (\Throwable) { /* ignore */ }
        }
        // Collect detailed error messages from the validation report when available
        $errors = [];
    if (!$ok) {
            try {
                $report = $validationManager->getReport();
                if ($report) {
                    $errors = $report->getErrorMessages();
                    // Fallback: if there are no incidents/messages, synthesize a brief summary
                    if (empty($errors)) {
                        $failedArgs = [];
                        try {
                            // List failed arguments (fields) and the highest severities per argument
                            foreach ($report->getFailedArguments() as $arg) {
                                $failedArgs[] = $arg->getName();
                            }
                            // Also attempt to infer failing validators from argument results
                            $failedValidators = [];
                            foreach ($report->getArgumentResults() as $results) {
                                foreach ($results as $res) {
                                    // consider > NOTICE as a failure contributing to decision
                                    if (isset($res['severity']) && is_int($res['severity']) && $res['severity'] > \Agavi\Validator\AgaviValidator::NOTICE) {
                                        $v = $res['validator'] ?? null;
                                        if ($v && method_exists($v, 'getName')) {
                                            $failedValidators[$v->getName()] = true;
                                        }
                                    }
                                }
                            }
                            $fv = array_keys($failedValidators);
                            if (!empty($failedArgs)) {
                                $errors[] = 'failed_fields: ' . implode(',', array_unique($failedArgs));
                            }
                            if (!empty($fv)) {
                                $errors[] = 'failed_validators: ' . implode(',', $fv);
                            }
                        } catch (\Throwable) {
                            // ignore synthesis failure
                        }
                    }
                }
            } catch (\Throwable $te) {
                // Fallback to a generic marker if report extraction fails
                if ($vd) { AgaviDebugLogger::debug('[ValidationService] report extraction failed: ' . $te->getMessage()); }
                $errors = ['xml_failed'];
            }
            if (empty($errors)) {
                // Keep a stable, non-empty fallback so callers can surface a reason
                $errors = ['xml_failed'];
            }
            // Always emit a concise failure summary for tracing
            try {
                $report = $report ?? $validationManager->getReport();
                $sev = $report?->getResult();
                $failedArgs = [];
                try { foreach(($report?->getFailedArguments() ?? []) as $arg) { $failedArgs[] = $arg->getName(); } } catch (\Throwable) {}
                $vNames = [];
                try { foreach($validationManager->getChilds() as $v) { $vNames[] = method_exists($v, 'getName') ? $v->getName() : 'unknown'; } } catch (\Throwable) {}
                $logger?->debug('[ValidationService] FAIL module=' . $moduleName . ' action=' . $actionName . ' method=' . ($method ?: 'n/a') . ' severity=' . (is_null($sev) ? 'null' : $sev) . ' failedArgs=' . implode(',', $failedArgs) . ' validators=' . implode(',', $vNames) . ' errors=' . json_encode($errors));
            } catch (\Throwable) { /* ignore */ }
            if ($vd) {
                try {
                    $resSev = method_exists($report ?? null, 'getResult') ? ($report->getResult() ?? 'null') : 'no-report';
                } catch (\Throwable) {
                    $resSev = 'exception';
                }
                $logger?->debug('[ValidationService] xmlOnlyValidate failed: resultSeverity=' . $resSev . ' errors=' . json_encode($errors));
            }
        }
        $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
        return new ValidationResult($ok, ['errors' => $errors, 'trace' => $trace]);
    }
}
