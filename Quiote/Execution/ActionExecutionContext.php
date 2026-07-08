<?php
namespace Quiote\Execution;
;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\View\View;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lightweight DTO for container-less slot execution path.
 */
class ActionExecutionContext
{
    /**
     * @param array<string, mixed> $actionAttributes
     * @param array<string, mixed>|null $redirect
     */
    public function __construct(
        public readonly Action $action,
        public ?View $view,
        public readonly string $module,
        public readonly string $actionName,
        public readonly string $outputType,
        public readonly WebRequest $request,
    public readonly string $content,
    public readonly ?string $viewModuleName = null,
    public readonly ?string $viewName = null,
    public readonly array $actionAttributes = [],
    // New shims (nullable until fully wired)
    public readonly ?AttributeBag $attributeBag = null,
    public readonly ?ResponseHandle $responseHandle = null,
    // Snapshot of any redirect set by the view, captured immediately after view execution
    // to avoid a fiber/concurrency race where another request clears the shared global response.
    public readonly ?array $redirect = null,
    ) {}
}
