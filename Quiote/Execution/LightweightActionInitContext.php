<?php

namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Util\AttributeHolder;
use Psr\Http\Message\ServerRequestInterface;

class LightweightActionInitContext extends AttributeHolder implements ActionInitContext
{
    private ?string $viewModuleName = null;
    private ?string $viewName = null;
    private ?object $validationManager = null;

    public function __construct(
        private readonly Context $context,
        private readonly string $module,
        private readonly string $action,
        private readonly string $method,
        private readonly string $outputType,
        /**
         * Accept WebRequest (implements ServerRequestInterface) or any PSR-7 ServerRequest.
         */
    private readonly ServerRequestInterface|null $requestData,
        /**
         * Use the legacy Response type here so tests and legacy code that pass
         * Response-based shims (including TestLightweightResponse) do not
         * trigger a TypeError. WebResponse extends Response so this
         * remains compatible with the PSR adapter and web response code paths.
         */
    private readonly WebResponse $response
    ) {}

    public function getContext(): Context
    {
        return $this->context;
    }
    public function getModuleName(): string
    {
        return $this->module;
    }
    public function getActionName(): string
    {
        return $this->action;
    }
    public function getRequestMethod(): string
    {
        return $this->method;
    }
    public function getOutputTypeName(): string
    {
        return $this->outputType;
    }
    public function getRequestData(): ?ServerRequestInterface
    {
        return $this->requestData;
    }
    public function getResponse(): WebResponse
    {
        return $this->response;
    }
    public function setViewModuleName(?string $module): void
    {
        $this->viewModuleName = $module;
    }
    public function setViewName(?string $name): void
    {
        $this->viewName = $name;
    }
    public function getViewModuleName(): ?string
    {
        return $this->viewModuleName;
    }
    public function getViewName(): ?string
    {
        return $this->viewName;
    }

    // Legacy shim: some legacy actions call $this->getValidationManager() on the container.
    // Cached so that the same instance is returned every time — XML validators, action
    // validate*() methods, and error-handler code all need to share a single VM.
    public function getValidationManager(): ?object
    {
        if ($this->validationManager !== null) {
            return $this->validationManager;
        }
        try {
            $this->validationManager = $this->context->createInstanceFor('validation_manager');
            return $this->validationManager;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Replace the cached validation manager.
     * Called by ValidationService / ActionTestCase to inject the VM that
     * XML validators were executed against, so that the action's manual
     * validate*() methods see the same errors and exports.
     */
    public function setValidationManager(?object $vm): void
    {
        $this->validationManager = $vm;
    }
}
