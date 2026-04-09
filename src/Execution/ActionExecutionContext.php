<?php
namespace Agavi\Execution;
;

use Agavi\Request\AgaviWebRequest;
use Agavi\View\AgaviView;
use Psr\Http\Message\ServerRequestInterface;

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
        public readonly AgaviWebRequest $request,
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
