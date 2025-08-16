<?php
namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviResponse;

final class ImmutableViewInitContext implements ViewInitContext
{
    public function __construct(
        private AgaviContext $context,
        private string $viewModule,
        private string $viewName,
        private string $outputType,
        private ?string $actionModule,
        private ?string $actionName,
        private array $actionAttributes,
        private AgaviResponse $response
    ) {}

    public function getContext(): AgaviContext { return $this->context; }
    public function getViewModuleName(): string { return $this->viewModule; }
    public function getViewName(): string { return $this->viewName; }
    public function getOutputTypeName(): string { return $this->outputType; }
    public function getActionModuleName(): ?string { return $this->actionModule; }
    public function getActionName(): ?string { return $this->actionName; }
    public function getActionAttributes(): array { return $this->actionAttributes; }
    public function getResponse(): AgaviResponse { return $this->response; }
}
