<?php
namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * Immutable context returned by SlotDispatcher for container-less execution.
 * Mirrors ActionExecutionContext but focused on slot semantics.
 */
final class SlotExecutionContext
{
    public function __construct(
        public readonly AgaviAction $action,
        public readonly ?AgaviView $view,
        public readonly string $module,
        public readonly string $actionName,
        public readonly string $outputType,
        public readonly AgaviRequestDataHolder $requestData,
        public readonly string $content,
        public readonly ?string $viewModuleName = null,
        public readonly ?string $viewName = null,
        public readonly array $actionAttributes = [],
        public readonly array $parameters = []
    ) {}
}
