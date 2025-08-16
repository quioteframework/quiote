<?php

namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Util\AgaviToolkit;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;

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
    public function validate(AgaviAction $action, AgaviRequestDataHolder $rd, string $moduleName = '', string $actionName = '', string $method = 'Default'): ValidationResult
    {
        $vm = $this->manager;
        if (!$vm) {
            // Build a lightweight manager via context from action (container may be ActionInitContext or full container)
            $ctx = $action->getContext();
            $vm = $ctx->createInstanceFor('validation_manager');
        } else {
            $vm->clear();
        }
        $validatorsLoaded = [];
        $configFile = null;
        // 1. Load XML validation config if we have module/action names
        if ($moduleName && $actionName) {
            $configFile = AgaviToolkit::evaluateModuleDirective($moduleName, 'agavi.validate.path', [
                'moduleName' => $moduleName,
                'actionName' => $actionName,
            ]);
            if (is_readable($configFile)) {
                // Provide expected variables & context for compiled config file
                $this->currentContext = $action->getContext();
                $validationManager = $vm; // compiled code registers validators on this variable
                if (defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
                    require(AgaviAPCuConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                } else {
                    require(AgaviConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                }
                $validatorsLoaded = array_map(fn($v) => $v->getName(), $vm->getChilds());
            }
        }
        // 2. Manual validator registration method on action (mirrors container logic)
        $registerMethod = 'register' . $method . 'Validators';
        if (!is_callable([$action, $registerMethod])) {
            $registerMethod = 'registerValidators';
        }
        if (is_callable([$action, $registerMethod])) {
            $action->$registerMethod();
            $validatorsLoaded = array_map(fn($v) => $v->getName(), $vm->getChilds());
        }

        // NOTE: We intentionally do NOT relax strict mode when no validators are present.
        // Legacy semantics: with zero validators in strict mode, request parameters are cleared
        // before manual validate*() runs. This enforces that XML validators (or manual registrations)
        // must define every parameter intended for use in manual validation.

        // 3. Execute validators
        $ok = true;
        try {
            $ok = $vm->execute($rd);
        } catch (\Throwable $e) {
            return ValidationResult::failure(['exception' => $e->getMessage()]);
        }
        // 4. Manual action validation (validate[Method])
        $validateMethod = 'validate' . $method;
        if (!is_callable([$action, $validateMethod])) {
            $validateMethod = 'validate';
        }
        try {
            $manualOk = $action->$validateMethod($rd);
        } catch (\Throwable $e) {
            return ValidationResult::failure(['exception' => $e->getMessage()]);
        }
        $final = $ok && $manualOk;
        $errors = [];
        if (!$final) {
            try {
                $report = $vm->getReport();
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
    public function xmlOnlyValidate(AgaviAction $action, AgaviRequestDataHolder $rd, string $moduleName, string $actionName, string $method = 'Default'): ValidationResult
    {
        $vm = $this->manager;
        if (!$vm) {
            $ctx = $action->getContext();
            $vm = $ctx->createInstanceFor('validation_manager');
        } else {
            $vm->clear();
        }
        $validatorsLoaded = [];
        $configFile = null;
        if ($moduleName && $actionName) {
            $configFile = \Agavi\Util\AgaviToolkit::evaluateModuleDirective($moduleName, 'agavi.validate.path', ['moduleName' => $moduleName, 'actionName' => $actionName]);
            if (is_readable($configFile)) {
                $this->currentContext = $action->getContext();
                $validationManager = $vm;
                if (defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
                    require(\Agavi\Config\AgaviAPCuConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                } else {
                    require(\Agavi\Config\AgaviConfigCache::checkConfig($configFile, $this->currentContext->getName()));
                }
                $validatorsLoaded = array_map(fn($v) => $v->getName(), $vm->getChilds());
            }
        }
        // Execute validators only
        $ok = true;
        try {
            $ok = $vm->execute($rd);
        } catch (\Throwable $e) {
            return ValidationResult::failure(['exception' => $e->getMessage()]);
        }
        $trace = new ValidationTrace($moduleName, $actionName, $validatorsLoaded, $configFile);
        return new ValidationResult($ok, ['errors' => $ok ? [] : ['xml_failed'], 'trace' => $trace]);
    }
}
