<?php
namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;

/**
 * Immutable context returned by SlotDispatcher for container-less execution.
 * Mirrors ActionExecutionContext but focused on slot semantics.
 */
final readonly class SlotExecutionContext
{
    public function __construct(
        public AgaviAction $action,
        public ?AgaviView $view,
        public string $module,
        public string $actionName,
        public string $outputType,
        public AgaviWebRequest $request,
        public string $content,
        public ?string $viewModuleName = null,
        public ?string $viewName = null,
        public array $actionAttributes = [],
        public array $parameters = []
    ) {}
}
