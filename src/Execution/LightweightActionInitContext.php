<?php

namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviWebResponse;
use Agavi\Util\AgaviAttributeHolder;
use Psr\Http\Message\ServerRequestInterface;

class LightweightActionInitContext extends AgaviAttributeHolder implements ActionInitContext
{
    private ?string $viewModuleName = null;
    private ?string $viewName = null;

    public function __construct(
        private AgaviContext $context,
        private string $module,
        private string $action,
        private string $method,
        private string $outputType,
        /**
         * Accept AgaviWebRequest (implements ServerRequestInterface) or any PSR-7 ServerRequest.
         */
    private ServerRequestInterface|null $requestData,
        /**
         * Use the legacy AgaviResponse type here so tests and legacy code that pass
         * AgaviResponse-based shims (including TestLightweightResponse) do not
         * trigger a TypeError. AgaviWebResponse extends AgaviResponse so this
         * remains compatible with the PSR adapter and web response code paths.
         */
    private AgaviWebResponse $response
    ) {}

    public function getContext(): AgaviContext
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
    public function getResponse(): AgaviWebResponse
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
    public function getValidationManager()
    {
        try {
            return $this->context->createInstanceFor('validation_manager');
        } catch (\Throwable) {
            return null;
        }
    }
}
