<?php
namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\View\View;
use Quiote\Request\WebRequest;

/**
 * Immutable context returned by SlotDispatcher for container-less execution.
 * Mirrors ActionExecutionContext but focused on slot semantics.
 */
final readonly class SlotExecutionContext
{
    /**
     * @param array<string, mixed> $actionAttributes
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public Action $action,
        public ?View $view,
        public string $module,
        public string $actionName,
        public string $outputType,
        public WebRequest $request,
        public string $content,
        public ?string $viewModuleName = null,
        public ?string $viewName = null,
        public array $actionAttributes = [],
        public array $parameters = []
    ) {}
}
