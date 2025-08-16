<?php
namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Response\AgaviResponse;
use Agavi\Util\AgaviAttributeHolder;

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
        private ?AgaviRequestDataHolder $requestData,
        private AgaviResponse $response
    ) {}

    public function getContext(): AgaviContext { return $this->context; }
    public function getModuleName(): string { return $this->module; }
    public function getActionName(): string { return $this->action; }
    public function getRequestMethod(): string { return $this->method; }
    public function getOutputTypeName(): string { return $this->outputType; }
    public function getRequestData(): ?AgaviRequestDataHolder { return $this->requestData; }
    public function getResponse(): AgaviResponse { return $this->response; }
    public function setViewModuleName(?string $module): void { $this->viewModuleName = $module; }
    public function setViewName(?string $name): void { $this->viewName = $name; }
    public function getViewModuleName(): ?string { return $this->viewModuleName; }
    public function getViewName(): ?string { return $this->viewName; }
}
