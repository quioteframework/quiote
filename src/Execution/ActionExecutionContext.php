<?php
namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * Lightweight DTO for container-less slot execution path.
 */
class ActionExecutionContext
{
    public function __construct(
        public readonly object $action,
        public ?AgaviView $view,
        public readonly string $module,
        public readonly string $actionName,
        public readonly string $outputType,
        public readonly AgaviRequestDataHolder $requestData,
    public readonly string $content,
    public readonly ?string $viewModuleName = null,
    public readonly ?string $viewName = null,
    public readonly array $actionAttributes = [],
    // New shims (nullable until fully wired)
    public readonly ?AttributeBag $attributeBag = null,
    public readonly ?ResponseHandle $responseHandle = null,
    ) {}
}
