<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviExecutionContainer; // extend to satisfy AgaviAction::initialize type-hint
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\AgaviContext;

/**
 * Minimal lightweight execution container used only for initializing actions and views
 * in container-less paths. It avoids the heavy logic of the full AgaviExecutionContainer
 * and provides just the subset of state accessed by AgaviAction/AgaviView initialization
 * and attribute APIs.
 *
 * NOTE: This is an internal transitional shim. Do NOT rely on it for full legacy behavior.
 */
class LightExecutionContainer extends AgaviExecutionContainer
{
    public function __construct(
        AgaviContext $context,
        string $module,
        string $action,
        string $method,
        string $outputTypeName,
        ?AgaviRequestDataHolder $requestData
    ) {
        // Directly set protected properties defined in parent (no parent constructor exists)
        $this->context = $context;
        $this->moduleName = $module;
        $this->actionName = $action;
        $this->requestMethod = $method;
        $this->requestData = $requestData;
        // Minimal outputType object exposing getName()
        $this->outputType = new class($outputTypeName) { public function __construct(private string $n){} public function getName(){ return $this->n; } };
        // Provide a response instance via controller (may be lightweight already)
        try { $this->response = $context->getController()->getResponse(); } catch(\Throwable) { $this->response = null; }
    }

    // Override heavy methods with no-ops to prevent accidental legacy behavior.
    public function initialize() { /* no-op */ }
    public function getValidationManager() { return null; }
    public function performValidation() { return true; }
    // Ensure getters used during view/action init work.
    public function getRequestData() { return $this->requestData; }
    public function getModuleName() { return $this->moduleName; }
    public function getActionName() { return $this->actionName; }
    public function getRequestMethod() { return $this->requestMethod; }
}
