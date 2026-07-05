<?php

namespace Quiote\Execution;

use Quiote\Controller\Controller;
use Quiote\Action\Action;
use Quiote\View\View;
use Quiote\Request\RequestDataHolder; // legacy (deprecated)
use Quiote\Execution\ValidationService;
use Quiote\Execution\SecurityService; // still injected to keep signature stable (may be removed later)
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Execution\ImmutableViewInitContext;
use Quiote\Execution\ViewInitContext;
use Quiote\Response\WebResponse;
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\ActionResolver;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Request\WebRequest;

/**
 * ActionExecutor: container-less execution of an action+view producing ActionExecutionContext.
 * Current scope (incremental):
 * - Security + validation (optional) via services when enabled.
 * - Simple actions: execute() method.
 * - Non-simple actions: use ActionResolver for method dispatch.
 * - View resolution via ViewNameResolver (pure).
 * - View initialization via legacy container (temporary) if needed until ViewFactory extracted.
 * Future work will remove any dependency on containers entirely.
 */
final class ActionExecutor
{
    public function __construct(
        private readonly Controller $controller,
        private ?ActionResolver $actionResolver = null,
        private ?ValidationService $validationService = null,
        private ?SecurityService $securityService = null,
        private ?ViewFactory $viewFactory = null,
        private ?ViewNameResolver $viewNameResolver = null,
        // ForwardService removed from executor (security forwards handled exclusively in SecurityMiddleware)
    ) {
        // Initialize services
        $this->viewNameResolver ??= new ViewNameResolver();
        $this->actionResolver ??= new ActionResolver();
        $this->validationService ??= new ValidationService();
        $this->securityService ??= new SecurityService($controller);
        $this->viewFactory ??= new ViewFactory($controller);
    }

    /**
     * Build an WebRequest (preferred) from a PSR-7 ServerRequest.
     * Merges query + parsed body (body wins) into runtime parameters and carries over route attributes.
     * (Deprecated) Older call sites expecting RequestDataHolder should be updated to use the returned WebRequest directly.
     *
     * @param ?\Quiote\Context $context The Context actually handling this request (e.g.
     *        `$this->controller->getContext()` from a middleware that has a Controller).
     *        Its existing canonical WebRequest is reused when present (avoids rebuilding
     *        one already created earlier in the same request's pipeline). Previously this
     *        always reused `Context::getInstance('web')`'s request regardless of which
     *        context was actually dispatching -- harmless for single-context apps, but for
     *        any app using more than one named Context, every dispatch after "web" had
     *        handled its first request would silently reuse "web"'s stale WebRequest (wrong
     *        parameter whitelist, wrong prior values) instead of the current request's own.
     *        Omitting $context always builds a fresh WebRequest from $psr -- correct, if
     *        slightly less optimized, rather than guessing a context that might be wrong.
     */
    public static function buildRequestDataFromPsr(ServerRequestInterface $psr, ?\Quiote\Context $context = null): WebRequest
    {
    // Reuse the current context's own request if available; otherwise create WebRequest from the PSR-7 request
    $web = null;
    if ($context !== null) {
        try { $web = $context->getRequest(); } catch (\Throwable) { $web = null; }
    }
    if (!($web instanceof WebRequest)) {
        // Create WebRequest from PSR-7 request (WebRequest extends ServerRequest)
        try {
            $web = new WebRequest(
                $psr->getMethod(),
                $psr->getUri(),
                $psr->getHeaders(),
                $psr->getBody(),
                $psr->getProtocolVersion(),
                $psr->getServerParams()
            );
            $web = $web
                ->withQueryParams($psr->getQueryParams())
                ->withCookieParams($psr->getCookieParams())
                ->withParsedBody($psr->getParsedBody())
                ->withUploadedFiles($psr->getUploadedFiles());
            foreach ($psr->getAttributes() as $name => $value) {
                $web = $web->withAttribute($name, $value);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot create WebRequest from PSR-7 request: ' . $e->getMessage(), 0, $e);
        }
    }
        $query = $psr->getQueryParams();
        $body = $psr->getParsedBody();
        if (!is_array($body)) {
            $ct = strtolower($psr->getHeaderLine('Content-Type'));
            $isForm = str_contains($ct, 'application/x-www-form-urlencoded');
            if ($isForm) {
                $raw = '';
                try {
                    $stream = $psr->getBody();
                    if ($stream->isSeekable()) {
                        $stream->rewind();
                    }
                    $raw = $stream->getContents();
                    if ($raw === '' && $stream->isSeekable()) {
                        $stream->rewind();
                        $raw = (string)$stream;
                    }
                } catch (\Throwable) {
                }
                if ($raw === '' && $_POST) {
                    $body = $_POST;
                } else {
                    $tmp = [];
                    if ($raw !== '') {
                        parse_str($raw, $tmp);
                    }
                    if ($tmp) {
                        $body = $tmp;
                    }
                }
                $logger = \Quiote\Logging\Log::create('Quiote.Execution.ActionExecutor');
                if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
                    $keys = is_array($body) ? implode(',', array_slice(array_keys($body), 0, 6)) : 'n/a';
                    $logger->debug('[ActionExecutor] formParse(webReq) ct=' . $ct . ' rawLen=' . strlen($raw) . ' keys=' . $keys);
                }
            }
        }
        if (!is_array($body)) {
            $body = [];
        }
        $params = $query + $body; // body wins
        foreach (['module', 'action', 'output_type'] as $attr) {
            $val = $psr->getAttribute($attr);
            if (is_scalar($val) && !array_key_exists($attr, $params)) {
                $params[$attr] = $val;
            }
        }
        foreach ($params as $k => $v) {
            try {
                $web->setParameter($k, $v);
            } catch (\Throwable) {
            }
        }
        return $web;
    }

    /**
     * Execute an action given its descriptor and request data, mutating ExecutionState accordingly.
     * NOTE: For now we still create a lightweight execution container only to satisfy legacy view->initialize expectations.
     * In a later phase a ViewFactory + ViewInitContext will replace this.
     *
     * @param array<string, mixed> $parameters
     */
    public function execute(ActionDescriptor $desc, ServerRequestInterface $request, ExecutionState $state, array $parameters = [], ?Action $preInstantiatedAction = null): ActionExecutionContext
    {
        $logger = \Quiote\Logging\Log::for($this);
        $dbg = $logger->isEnabled(\Quiote\Logging\Level::Debug);
        if ($dbg) {
            $logger->debug('[ActionExecutor] start ' . $desc->module . ':' . $desc->action . ' method=' . $desc->method . ' output=' . $desc->outputType);
        }

        // Action span (telemetry.spans.action, default true). Not
        // created at all (not even as a no-op wrap) when the
        // depth toggle is off, matching RoutingMiddleware's own pattern.
        $span = \Quiote\Config\Config::getBool('telemetry.spans.action', true)
            ? \Quiote\Telemetry\Trace::span('Quiote.Action', $desc->module . ':' . $desc->action, [
                'quiote.module' => $desc->module,
                'quiote.action' => $desc->action,
                'quiote.method' => $desc->method,
                'quiote.output_type' => $desc->outputType,
            ])
            : \Quiote\Telemetry\NoopSpanHandle::instance();
        // Lifecycle hook: about to run the action.
        \Quiote\Event\Events::emit(new \Quiote\Event\Lifecycle\ActionBeforeEvent($desc));
        try {
            $result = $this->doExecute($desc, $state, $preInstantiatedAction, $dbg, $logger);
            \Quiote\Event\Events::emit(new \Quiote\Event\Lifecycle\ActionAfterEvent($desc, $result));
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e)->setStatusError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    private function doExecute(ActionDescriptor $desc, ExecutionState $state, ?Action $preInstantiatedAction, bool $dbg, \Quiote\Logging\CategoryLogger $logger): ActionExecutionContext
    {
        // Use provided action instance if supplied to avoid double instantiation (enables external pre-initialization & test counters)
        $action = $preInstantiatedAction ?? $this->controller->createActionInstance($desc->module, $desc->action);
        // Reuse the context's canonical WebRequest (created earlier by ValidationMiddleware) so
        // validator exports are visible to action and later to the view without copying.
        $actionRequest = null;
        try {
            $actionRequest = $this->controller->getContext()->getRequest();
        } catch (\Throwable) {
        }
        if (!($actionRequest instanceof WebRequest)) { throw new \RuntimeException('Canonical WebRequest missing in ActionExecutor::execute'); }
        // No need to attachPsrRequest - WebRequest IS the PSR-7 request

        // Initialize action with lightweight context
        $lwCtx = new LightweightActionInitContext(
            $this->controller->getContext(),
            $desc->module,
            $desc->action,
            $desc->method,
            $desc->outputType,
            $actionRequest,
            $this->controller->getGlobalResponse()
        );
        $action->initialize($lwCtx);

        // SECURITY: Executor is security-agnostic. Only minimal guard remains.
        if ($state->securityDecision === null) {
            $useSecurity = \Quiote\Config\Config::getBool('core.use_security', true);
            if (!$useSecurity) {
                $state->securityDecision = SecurityDecision::Allow; // global security disabled
            } elseif (!$action->isSecure()) {
                $state->securityDecision = SecurityDecision::Allow; // action explicitly open
            } else {
                throw new \RuntimeException('Security decision missing before action execution for ' . $desc->module . ':' . $desc->action);
            }
        } elseif ($state->securityDecision !== SecurityDecision::Allow) {
            // A non-allow decision should have been short-circuited earlier; treat as logic error.
            throw new \LogicException('Non-allow securityDecision reached ActionExecutor (expected short-circuit).');
        }

        // Validation decision (performed + succeeded flag) must be set by ValidationMiddleware only.
        // Executor no longer performs or enforces validation beyond trusting provided state.

        // ACTION EXECUTION
        $rawView = $this->actionResolver->execute($action, $desc->method, $actionRequest);
        if ($dbg) {
            $logger->debug('[ActionExecutor] rawView=' . var_export($rawView, true));
        }
        // Snapshot attributes immediately after action code runs (pre-view)
        $attributeSnapshot = [];
        try {
            $attributeSnapshot = $action->getAttributes();
        } catch (\Throwable) {
            $attributeSnapshot = [];
        }
        // Shallow clone to detach from holder internal storage (defensive)
        $attributeSnapshot = array_merge([], $attributeSnapshot);

        [$vm, $vn] = $this->viewNameResolver->resolve($desc->module, $desc->action, $rawView);
        [$view, $content] = $this->renderView($vm, $vn, $desc, $actionRequest, $attributeSnapshot, $dbg, $logger);
        $state->securityDecision = SecurityDecision::Allow;
        $state->viewModule = $vm;
        $state->viewName = $vn;
        $bag = new AttributeBag($attributeSnapshot);
        $globalResp = $this->controller->getGlobalResponse();
        $respHandle = new ResponseHandle($globalResp);
        // Snapshot redirect immediately - before any fiber context switch could cause another
        // request to call clear() on the shared global response object.
        $redirectSnapshot = $globalResp->getRedirect();
        $ctx = new ActionExecutionContext($action, $view, $desc->module, $desc->action, $desc->outputType, $actionRequest, $content, $vm, $vn, $attributeSnapshot, $bag, $respHandle, $redirectSnapshot);
        if ($dbg) {
            $logger->debug('[ActionExecutor] done contentLen=' . strlen($content));
        }
        return $ctx;
    }

    /**
     * Resolves, creates, and renders the view (if any), wrapped in a nested
     * view-render span — a child of the action span opened by
     * {@see execute()}. Gated by the
     * same `telemetry.spans.action` toggle; when `$vn` is `View::NONE` no
     * span is opened at all, since there is nothing to render.
     * @param array<string, mixed> $attributeSnapshot
     * @return array{0: ?View, 1: string} [$view, $content]
     */
    private function renderView(?string $vm, ?string $vn, ActionDescriptor $desc, WebRequest $actionRequest, array $attributeSnapshot, bool $dbg, \Quiote\Logging\CategoryLogger $logger): array
    {
        if ($vn === View::NONE) {
            if ($dbg) {
                $logger->debug('[ActionExecutor] vn is NONE (no view)');
            }
            return [null, ''];
        }

        $span = \Quiote\Config\Config::getBool('telemetry.spans.action', true)
            ? \Quiote\Telemetry\Trace::span('Quiote.View', $vm . ':' . $vn, ['quiote.view.module' => $vm, 'quiote.view.name' => $vn])
            : \Quiote\Telemetry\NoopSpanHandle::instance();
        try {
            $view = $this->viewFactory?->create($vm, $vn, $desc->module, $desc->action, strtolower($this->controller->getOutputType()->getName()), $actionRequest, $attributeSnapshot, $this->validationService?->getValidationManager());
            if (!$view) {
                $view = $this->createAndInitView($vm, $vn, $desc->module, $desc->action, $actionRequest, $attributeSnapshot);
            }
            $content = '';
            $method = 'execute';
            if ($view !== null) {
                $method = $this->selectViewMethod($view, $desc->outputType);
                $res = $view->$method($actionRequest);
                if ($res !== null) {
                    $content = (string)$res;
                } elseif ($view->getLayers()) {
                    $layerContent = $view->renderLayers();
                    if ($layerContent !== '') {
                        $content = $layerContent;
                    }
                }
            } else {
                // No view could be created; leave content empty (legacy tests expect this behaviour)
            }
            if ($dbg) {
                $prefix = substr($content, 0, 120);
                $logger->debug('[ActionExecutor] view=' . $view::class . ' method=' . $method . ' contentLen=' . strlen($content) . ' prefix=' . $prefix);
            }
            return [$view, $content];
        } catch (\Throwable $e) {
            $span->recordException($e)->setStatusError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @param array<string, mixed> $attributeSnapshot
     */
    private function createAndInitView(string $vm, string $vn, string $module, string $action, ServerRequestInterface $rd, array $attributeSnapshot = []): ?View
    {
        try {
            /** @var View $view */
            $view = $this->controller->createViewInstance($vm, $vn);
            // Snapshot action attributes if action already executed (not passed here; minimal for now)
            $global = $this->controller->getGlobalResponse();
            $psr = new \Quiote\Http\PsrResponseAdapter($global);
            $vic = new ImmutableViewInitContext(
                context: $this->controller->getContext(),
                viewModule: $vm,
                viewName: $vn,
                outputType: strtolower($this->controller->getOutputType()->getName()),
                actionModule: $module,
                actionName: $action,
                actionAttributes: $attributeSnapshot,
                response: $global,
                psrResponse: $psr,
                validationManager: $this->validationService?->getValidationManager()
            );
            $view->initialize($vic);
            return $view;
        } catch (\Throwable) {
            return null;
        }
    }

    private function selectViewMethod(?View $view, string $outputType): string
    {
        if (!$view) {
            return 'execute';
        }
        $candidate = 'execute' . ucfirst($outputType);
        return is_callable([$view, $candidate]) ? $candidate : 'execute';
    }
}
