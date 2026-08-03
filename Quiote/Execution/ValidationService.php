<?php

namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\Validator\ValidationManager;
use Quiote\Util\Toolkit;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Request\WebRequest;
use Quiote\Exception\QuioteException;
use Quiote\Context;
use Quiote\Validator\Validator;

/**
 * Tiny immutable description of what we validated (for debugging/parity tests).
 */
final readonly class ValidationTrace
{
    /**
     * @param string[] $validatorsLoaded
     */
    public function __construct(
        public string $module,
        public string $action,
        public array $validatorsLoaded = [],
        public ?string $configFile = null,
    ) {}
}

/**
 * Adapter around legacy validation logic to enable container-less execution.
 * Calls Action::validate directly (manual validators unsupported without container).
 */
class ValidationService
{
    /**
     * @var ?\Quiote\Context holds Context for compiled validator config
     */
    private $currentContext = null;

    /**
     * The validation manager actually used by the most recent validate() /
     * xmlOnlyValidate() call. When the service is constructed without a manager
     * (the common pipeline case — ValidationMiddleware does `new ValidationService()`),
     * the real manager is created lazily inside those methods via
     * createInstanceFor('validation_manager') and holds all the incidents/errors.
     * We capture it here so getValidationManager() can hand that populated instance
     * to the JSON error view; otherwise the view receives null and serializes an
     * empty error set. The promoted $manager is readonly and cannot be reassigned.
     */
    private ?ValidationManager $activeManager = null;

    /**
     * Per-worker cache of compiled validator-registration closures, keyed by
     * "(configFile)|(context)". A validators.xml file compiles to a snippet of
     * plain PHP statements (see RuntimeArrayEmitter) meant to run inline in the
     * caller's scope, referencing the free variables $validationManager/$method
     * and calling $this->getContext(). The APCu path used to re-eval() that raw
     * source on every single dispatch -- eval()'d code is never opcache-cached,
     * so every request paid a full lex/parse/compile of the same source. Wrapping
     * it in a closure once and reusing the already-compiled Closure (rebinding
     * $this per call via Closure::call() instead of relying on however it was
     * bound when first compiled) keeps the per-request cost to just invoking an
     * already-compiled closure.
     * @var array<string, \Closure>
     */
    private static array $compiledApcuValidatorClosures = [];

    /**
     * Per-instance cache for Log::for($this) -- avoids repeating the
     * class-name normalization + category cache lookup on every call within
     * the same validate()/xmlOnlyValidate() invocation.
     */
    private ?\Quiote\Logging\CategoryLogger $loggerCache = null;

    public function __construct(private readonly ?ValidationManager $manager = null) {}

    private function getLogger(): \Quiote\Logging\CategoryLogger
    {
        return $this->loggerCache ??= \Quiote\Logging\Log::for($this);
    }

    /**
     * Run a compiled validator-registration snippet fetched from APCu (the raw
     * PHP source following the 'APCU:' marker stripped by the caller), caching
     * the compiled Closure per $cacheKey so only the first call for that key
     * pays eval()'s lex/parse/compile cost.
     */
    private function runCachedApcuValidatorSnippet(string $apcuContent, string $cacheKey, ValidationManager $validationManager, string $method): void
    {
        $closure = self::$compiledApcuValidatorClosures[$cacheKey] ?? null;
        if (!$closure instanceof \Closure) {
            $built = eval('return function($validationManager, $method) { ?>' . $apcuContent . ' };');
            if (!$built instanceof \Closure) {
                throw new QuioteException('Compiled validator config for "' . $cacheKey . '" did not evaluate to a closure.');
            }
            $closure = $built;
            self::$compiledApcuValidatorClosures[$cacheKey] = $closure;
        }
        // Rebind to $this on every call: the closure is cached across requests
        // (and across ValidationService instances), but the compiled snippet's
        // $this->getContext() call must always resolve against the instance
        // actually running *this* validation, not whichever instance happened
        // to trigger the first, cache-populating eval().
        $closure->call($this, $validationManager, $method);
    }

    public function getValidationManager(): ?ValidationManager
    {
        return $this->activeManager ?? $this->manager;
    }

    // Expose context to compiled validator config (expects $this->getContext())
    public function getContext(): ?\Quiote\Context
    {
        return $this->currentContext;
    }

    /**
     * Retrieve the Action's Context, failing loudly if it has not been set.
     * Validation always runs against an already-initialized action, so a
     * missing context here indicates a caller invoked validate()/xmlOnlyValidate()
     * before Action::initialize() ran, rather than a legitimately optional case.
     * @throws QuioteException if the action has no Context.
     */
    private function requireActionContext(Action $action): Context
    {
        $context = $action->getContext();
        if ($context === null) {
            throw new QuioteException(sprintf('Cannot validate: Action "%s" has not been initialized with a Context yet.', $action::class));
        }
        return $context;
    }

    /**
     * A compact multi-line snapshot of the validation report and the validator configuration
     * behind it, for a debug line.
     *
     * Assembled in one place and invoked through {@see \Quiote\Logging\CategoryLogger::debugWith()},
     * which is where the "diagnostics never affect the request" rule is enforced -- so the
     * traversals below can read whatever they need without each one guarding itself.
     */
    private function reportSnapshot(\Quiote\Validator\ValidationManager $validationManager, bool $ok): string
    {
        $report = $validationManager->getReport();
        $incidents = $report->getIncidents();
        $lines = [
            '[ValidationService] summary ok=' . ($ok ? '1' : '0')
                . ' childValidators=' . count($validationManager->getChilds())
                . ' mode=' . $this->scalarToString($validationManager->getParameter('mode'))
                . ' reportSeverity=' . $report->getResult()
                . ' incidents=' . count($incidents),
        ];

        foreach ($incidents as $i => $incident) {
            $validator = $incident->getValidator();
            $messages = array_map(
                static fn($error): string => (string) $error->getMessage(),
                array_values($incident->getErrors())
            );
            $args = array_map(
                static fn($argument): string => (string) $argument->getName(),
                array_values($incident->getArguments())
            );
            $lines[] = '[ValidationService] incident#' . $i
                . ' validator=' . ($validator === null ? 'null' : $validator->getName())
                . ' severity=' . $incident->getSeverity()
                . ' args=' . implode(',', $args)
                . ' messages=' . json_encode($messages);
        }

        foreach ($validationManager->getChilds() as $v) {
            $lines[] = '[ValidationService] validator cfg name=' . $v->getName()
                . ' source=' . $this->scalarToString($v->getParameter('source'))
                . ' required=' . var_export($v->getParameter('required', true), true)
                . ' base=' . $this->scalarToString($v->getParameter('base', ''));
        }

        return implode("\n", $lines);
    }

    /**
     * Coerce a mixed value (validator/manager config parameter) to string for
     * debug-log interpolation, using the same scalar rule PHP's own (string)
     * cast uses, falling back to '' for values that can't be meaningfully
     * stringified (arrays, non-Stringable objects).
     */
    private function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Extract the loaded validators' names as a plain string list, dropping
     * any unnamed validator instead of letting a null name leak into the
     * ValidationTrace (which is meant to be a simple debugging aid, not a
     * strict record, so silently skipping an unnamed validator is safe here).
     * @param iterable<Validator> $childs
     * @return string[]
     */
    private function validatorNames(iterable $childs): array
    {
        $names = [];
        foreach ($childs as $child) {
            $name = $child->getName();
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Perform validation for an action execution.
     * Steps:
     * 1. Load XML validation config (validators, dependencies) if present.
     * 2. Allow action to register manual validators via register[Method]Validators().
     * 3. Execute validator manager then action validate[Method]().
     * 4. Return ValidationResult with collected error messages (if retrievable) and a ValidationTrace meta object.
     */
    public function validate(Action $action, WebRequest $request, string $moduleName = '', string $actionName = '', string $method = ''): ValidationResult
    {
        $logger = $this->getLogger();
        $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);

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
            $ctx = $this->requireActionContext($action);
            $validationManager = $ctx->createInstanceFor('validation_manager');
        } else {
            $validationManager->clear();
        }
        // Expose the manager actually used so the error view can read its incidents.
        $this->activeManager = $validationManager;
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
            $configFile = Toolkit::evaluateModuleDirective($moduleName, 'quiote.validate.path', [
                'moduleName' => $moduleName,
                'actionName' => $actionNamePath,
            ]);
            $configReadable = is_readable($configFile);
            if ($vd) {
                try { $logger->debug('[ValidationService][probe] resolve configFile=' . $configFile . ' readable=' . ($configReadable?'1':'0') . ' methodToken=' . $method . ' module=' . $moduleName . ' action=' . $actionName); } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
            }
            if ($configReadable) {
                // Provide expected variables & context for compiled config file
                $this->currentContext = $this->requireActionContext($action);
                if ($vd) { $logger->debug('[ValidationService][probe] including compiled validators (pre-checkCache)'); }
                if ($vd) {
                    try { $logger->debug('[ValidationService][probe] pre-checkCache methodHex=' . bin2hex((string)$method) . ' type=' . gettype($method)); } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
                }
                if (defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
                    $incFile = APCuConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if ($vd) { $logger->debug('[ValidationService][probe] APCu checkConfig returned ' . (str_starts_with($incFile, 'APCU:') ? 'APCU:...' : $incFile)); }
                    if (str_starts_with($incFile, 'APCU:')) {
                        $this->runCachedApcuValidatorSnippet(substr($incFile, 5), $configFile . '|' . $this->currentContext->getName(), $validationManager, $method);
                    } else {
                        require($incFile);
                    }
                    if ($vd) {
                        try {
                            $statLine = '[ValidationService][probe] post-require APCu childCount=' . count($validationManager->getChilds());
                            if (file_exists($incFile)) { $statLine .= ' real=' . realpath($incFile) . ' mtime=' . filemtime($incFile) . ' size=' . filesize($incFile); }
                            $logger->debug($statLine);
                        } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
                    }
                } else {
                    $incFile = ConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if ($vd) { $logger->debug('[ValidationService][probe] disk checkConfig returned ' . $incFile . ' exists=' . (file_exists($incFile)?'1':'0')); }
                    require($incFile);
                    if ($vd) {
                        try {
                            $statLine = '[ValidationService][probe] post-require disk childCount=' . count($validationManager->getChilds());
                            if (file_exists($incFile)) { 
                                $real = realpath($incFile); $mtime = @filemtime($incFile); $size = @filesize($incFile);
                                $contents = @file_get_contents($incFile);
                                $hash = $contents !== false ? sha1($contents) : 'no-read';
                                $snippet = $contents !== false ? substr($contents, 0, 180) : '';
                                $snippet = str_replace(["\n","\r"], ['\\n',''], $snippet);
                                $statLine .= ' real=' . $real . ' mtime=' . $mtime . ' size=' . $size . ' sha1=' . $hash . ' head=' . $snippet;
                            }
                            $logger->debug($statLine);
                        } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
                    }
                }
                $validatorsLoaded = $this->validatorNames($validationManager->getChilds());
                if ($vd) {
                    try {
                        $logger->debug('[ValidationService][validate] loadedValidators=' . (empty($validatorsLoaded) ? 'none' : implode(',', $validatorsLoaded)) . ' file=' . $configFile . ' method=' . $method);
                    } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
                }
            } else {
                if ($vd) {
                    try { $logger->debug('[ValidationService][validate] no readable config file at ' . $configFile); } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
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
            $validatorsLoaded = $this->validatorNames($validationManager->getChilds());
        }

        // NOTE: We intentionally do NOT relax strict mode when no validators are present.
        // Legacy semantics: with zero validators in strict mode, request parameters are cleared
        // before manual validate*() runs. This enforces that XML validators (or manual registrations)
        // must define every parameter intended for use in manual validation.

        // 3. Execute validators
        $ok = true;
        if ($vd) {
            try {
                $logger->debug('[ValidationService][validate] About to execute validators, childCount=' . count($validationManager->getChilds()));
            } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
        }
        try {
            $ok = (bool)$validationManager->execute($request);
            if ($vd) {
                try {
                    $logger->debug('[ValidationService][validate] Validators execute() returned: ' . ($ok ? 'true' : 'false'));
                } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
            }
        } catch (\Throwable $e) {
            // A validator throwing is a framework/app bug, not "the user submitted
            // invalid input" -- treating it as an ordinary validation failure would
            // return a graceful 400 while potentially leaving the request unpruned
            // (pruning happens later in ValidationManager::execute(), which never
            // completed). Log it as a hard error and let it propagate to
            // ErrorHandlingMiddleware for a 500, rather than swallowing it.
            $logger->error('[ValidationService][validate] Validators execute() threw exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
        if ($vd) {
            try {
                $childs = $validationManager->getChilds();
                $names = [];
                foreach ($childs as $cv) { $names[] = $cv->getName(); }
                $logger->debug('[ValidationService][validate] executeResult=' . ($ok?'1':'0') . ' childCount=' . count($names) . ' names=' . implode(',', $names));
            } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
        }
        // 4. Manual action validation (validate[Method])
        // Use the context's request which may have been updated by pruneParametersToValidated()
        // during VM execute(). This ensures the action's validate method sees the post-prune
        // request and any parameters it sets via setParameter() propagate correctly.
        $currentRequest = $this->requireActionContext($action)->getRequest();
        $validateMethod = 'validate' . $normalizedMethod;
        if (!is_callable([$action, $validateMethod])) {
            $validateMethod = 'validate';
        }
        if ($vd) {
            try {
                $logger->debug('[ValidationService][validate] About to call action->' . $validateMethod . '() on ' . $action::class);
            } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
        }
        try {
            $manualOk = (bool)$action->$validateMethod($currentRequest);
            if ($vd) {
                try {
                    $logger->debug('[ValidationService][validate] action->' . $validateMethod . '() returned ' . ($manualOk ? 'true' : 'false'));
                } catch(\Throwable $diagFailure) { $logger->debug('[diagnostics] debug line could not be assembled: ' . $diagFailure->getMessage()); }
            }
        } catch (\Throwable $e) {
            // Same rationale as the validator-execution catch above: a manual
            // validate*() method throwing is a bug, not invalid user input.
            $logger->error('[ValidationService][validate] action->' . $validateMethod . '() threw exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
        $final = $ok && $manualOk;
        $errors = [];
        if (!$final) {
            try {
                $errors = $validationManager->getReport()->getErrorMessages();
            } catch (\Throwable $e) {
                // The failure stands; only the explanation is missing. Say so and hand back a
                // stable marker, rather than reporting a failure with no reason at all.
                $logger->warning(
                    '[ValidationService][validate] could not read error messages from the report: ' . $e->getMessage()
                );
                $errors = ['validation_failed'];
            }
        }
        // Embed trace metadata for debugging (caller can ignore)
        $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
        return new ValidationResult($final, ['errors' => $errors, 'trace' => $trace]);
    }

    /** Execute only XML + registered validators (skip action validate* methods). */
    public function xmlOnlyValidate(Action $action, WebRequest $request, string $moduleName, string $actionName, string $method = ''): ValidationResult
    {
        $logger = $this->getLogger();
        // NOTE: Compiled validator configuration files gate validator registration using
        //   if($method == 'write') { ... }
        // The compiled config is included with a local $method variable in scope.
        // The validate() method already does this, but
        // xmlOnlyValidate previously forgot to normalize and overwrite the local $method.
        // As a result, all validators with method attributes (e.g. method="write") were skipped
        // because the condition evaluated against an empty or incorrect value.
        // We replicate the logic from validate(): lowercase token for XML gating while preserving
        // the passed argument semantics.
        $xmlMethod = strtolower($method ?: 'read');
        $method = $xmlMethod; // expose lowercase token to included compiled config scope

        $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);
        if ($vd) {
            $logger->debug("[ValidationService] xmlOnlyValidate for " . ($moduleName ?: 'no_module') . "/" . ($actionName ?: 'no_action') . " method=" . $method);
        }
        $validationManager = $this->manager;
        if (!$validationManager) {
            $ctx = $this->requireActionContext($action);
            $validationManager = $ctx->createInstanceFor('validation_manager');
        } else {
            $validationManager->clear();
        }
        // Expose the manager actually used so the error view can read its incidents.
        $this->activeManager = $validationManager;
        // Inject the VM into the action's init context so that ValidatorBuilder::on()
        // (called from register{Method}Validators() below) registers against the same
        // manager instance this method executes, instead of a throwaway one lazily
        // created by ActionInitContext::getValidationManager().
        $initCtx = $action->getInitContext();
        if ($initCtx !== null && method_exists($initCtx, 'setValidationManager')) {
            $initCtx->setValidationManager($validationManager);
        }
        $validatorsLoaded = [];
        $configFile = null;
        if ($moduleName && $actionName) {
            // Convert dots to slashes for file system paths (e.g., Resources.Data -> Resources/Data)
            $actionNamePath = str_replace('.', '/', $actionName);
            $configFile = \Quiote\Util\Toolkit::evaluateModuleDirective($moduleName, 'quiote.validate.path', ['moduleName' => $moduleName, 'actionName' => $actionNamePath]);
            $configReadable = is_readable($configFile);
            if ($vd) {
                $logger->debug("[ValidationService] Validation config file = " . $configFile . ", is_readable=" . ($configReadable ? "1":"0"));
            }
            if ($configReadable) {
                $this->currentContext = $this->requireActionContext($action);

                if (defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
                    if ($vd) {
                        $logger->debug("[ValidationService] Loading " . $method . " validators from APCu");
                    }
                    $cacheResult = \Quiote\Config\APCuConfigCache::checkConfig($configFile, $this->currentContext->getName());
                    if (str_starts_with($cacheResult, 'APCU:')) {
                        $this->runCachedApcuValidatorSnippet(substr($cacheResult, 5), $configFile . '|' . $this->currentContext->getName(), $validationManager, $method);
                    } else {
                        require($cacheResult);
                    }
                } else {
                    if ($vd) {
                        $logger->debug("[ValidationService] Loading " . $method . " validators from disk");
                    }
                    require(\Quiote\Config\ConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                }
                $validatorsLoaded = $this->validatorNames($validationManager->getChilds());
                if ($vd) {
                    $logger->debug('[ValidationService] Loaded validators: ');
                    $logger->debug(count($validatorsLoaded) > 0 ? implode(', ', $validatorsLoaded) : 'none');
                }
            }
        }
        // Manual validator registration method on action (mirrors validate()'s step 2).
        // Without this, actions that define validators purely via register{Method}Validators()
        // (no validators.xml file) had zero validators loaded here, so ValidationManager::execute()
        // never called enforceValidatedParameters() and every parameter stayed unwhitelisted.
        $registerMethod = 'register' . ucfirst($xmlMethod) . 'Validators';
        if (!is_callable([$action, $registerMethod])) {
            $registerMethod = 'registerValidators';
        }
        if (is_callable([$action, $registerMethod])) {
            $action->$registerMethod();
            $validatorsLoaded = $this->validatorNames($validationManager->getChilds());
        }
        // Execute validators only
        $ok = true;
        try {
            /** @var ValidationManager $validationManager */
            if ($vd) {
                $modeDbg = $this->scalarToString($validationManager->getParameter('mode', 'strict'));
                $logger->debug("[ValidationService] Running validation (mode=" . $modeDbg . ")");
            }
            $ok = (bool)$validationManager->execute($request);
        } catch (\Throwable $e) {
            // Same rationale as validate(): a validator throwing is a critical
            // framework/app bug, not invalid user input. Log it and let it
            // propagate to ErrorHandlingMiddleware for a 500 instead of
            // masquerading as a graceful validation failure.
            $logger->error('[ValidationService] xmlOnlyValidate execute() threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
        $logger->debugWith(fn(): string => $this->reportSnapshot($validationManager, $ok));
        // Collect detailed error messages from the validation report when available
        $errors = [];
    if (!$ok) {
            try {
                $report = $validationManager->getReport();
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
                                if ($res['severity'] > \Quiote\Validator\Validator::NOTICE) {
                                    $v = $res['validator'] ?? null;
                                    $vName = $v?->getName();
                                    if ($vName !== null) {
                                        $failedValidators[$vName] = true;
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
                    } catch (\Throwable $e) {
                        // The summary is a convenience over the report; without it the
                        // 'xml_failed' fallback below still names the outcome.
                        $logger->warning(
                            '[ValidationService] could not synthesize a failure summary from the report: ' . $e->getMessage()
                        );
                    }
                }
            } catch (\Throwable $te) {
                $logger->warning('[ValidationService] report extraction failed: ' . $te->getMessage());
                $errors = ['xml_failed'];
            }
            if (empty($errors)) {
                // Keep a stable, non-empty fallback so callers can surface a reason
                $errors = ['xml_failed'];
            }
            $logger->debugWith(function () use ($validationManager, $moduleName, $actionName, $method, $errors): string {
                $report = $validationManager->getReport();
                $failedArgs = array_map(
                    static fn($arg): string => (string) $arg->getName(),
                    iterator_to_array($report->getFailedArguments(), false)
                );
                $validatorNames = array_map(
                    static fn($v): string => (string) $v->getName(),
                    array_values($validationManager->getChilds())
                );

                return '[ValidationService] FAIL module=' . $moduleName . ' action=' . $actionName
                    . ' method=' . $method . ' severity=' . $report->getResult()
                    . ' failedArgs=' . implode(',', $failedArgs)
                    . ' validators=' . implode(',', $validatorNames)
                    . ' errors=' . json_encode($errors);
            });
        }
        $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
        return new ValidationResult($ok, ['errors' => $errors, 'trace' => $trace]);
    }
}
