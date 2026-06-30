<?php

namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder; // legacy (deprecated)
use Agavi\Execution\ValidationService;
use Agavi\Execution\SecurityService; // still injected to keep signature stable (may be removed later)
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Execution\ImmutableViewInitContext;
use Agavi\Execution\ViewInitContext;
use Agavi\Response\AgaviWebResponse;
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\ActionResolver;
use Psr\Http\Message\ServerRequestInterface;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;

/**
 * ActionExecutor: container-less execution of an action+view producing ActionExecutionContext.
 *
 * Phase 1 scope (incremental):
 * - Security + validation (optional) via services when enabled.
 * - Simple actions: execute() method.
 * - Non-simple actions: use ActionResolver for method dispatch.
 * - View resolution via ViewNameResolver (pure).
 * - View initialization via legacy container (temporary) if needed until ViewFactory extracted.
 *
 * Future phases will remove any dependency on containers entirely.
 */
final class ActionExecutor
{
    public function __construct(
        private AgaviController $controller,
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
     * Build an AgaviWebRequest (preferred) from a PSR-7 ServerRequest.
     * Merges query + parsed body (body wins) into runtime parameters and carries over route attributes.
     * (Deprecated) Older call sites expecting AgaviRequestDataHolder should be updated to use the returned AgaviWebRequest directly.
     */
    public static function buildRequestDataFromPsr(ServerRequestInterface $psr): AgaviWebRequest
    {
    // Reuse context request if available; otherwise create AgaviWebRequest from the PSR-7 request
    $web = null;
    try { $web = \Agavi\Agavi::context('web', true)?->getRequest(); } catch(\Throwable) { $web = null; }
    if (!($web instanceof AgaviWebRequest)) {
        // Create AgaviWebRequest from PSR-7 request (AgaviWebRequest extends ServerRequest)
        try {
            $web = new AgaviWebRequest(
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
            throw new \RuntimeException('Cannot create AgaviWebRequest from PSR-7 request: ' . $e->getMessage(), 0, $e);
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
                    $raw = method_exists($stream, 'getContents') ? $stream->getContents() : (string)$stream;
                    if ($raw === '' && $stream->isSeekable()) {
                        $stream->rewind();
                        $raw = (string)$stream;
                    }
                } catch (\Throwable) {
                }
                if ($raw === '' && isset($_POST) && is_array($_POST) && $_POST) {
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
                if (\Agavi\Util\DebugFlags::$exec) {
                    $keys = is_array($body) ? implode(',', array_slice(array_keys($body), 0, 6)) : 'n/a';
                    AgaviDebugLogger::debug('[ActionExecutor] formParse(webReq) ct=' . $ct . ' rawLen=' . strlen($raw) . ' keys=' . $keys);
                }
            }
        }
        if (!is_array($query)) {
            $query = [];
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
     */
    public function execute(ActionDescriptor $desc, ServerRequestInterface $request, ExecutionState $state, array $parameters = [], ?AgaviAction $preInstantiatedAction = null): ActionExecutionContext
    {
        $dbg = \Agavi\Util\DebugFlags::$exec;
        if ($dbg) {
            AgaviDebugLogger::debug('[ActionExecutor] start ' . $desc->module . ':' . $desc->action . ' method=' . $desc->method . ' output=' . $desc->outputType, $this->controller->getContext());
        }
        // Use provided action instance if supplied to avoid double instantiation (enables external pre-initialization & test counters)
        $action = $preInstantiatedAction ?? $this->controller->createActionInstance($desc->module, $desc->action);
        if (!($action instanceof AgaviAction)) {
            throw new \RuntimeException('Created action is not instance of AgaviAction');
        }
        // Reuse the context's canonical AgaviWebRequest (created earlier by ValidationMiddleware) so
        // validator exports are visible to action and later to the view without copying.
        $actionRequest = null;
        try {
            $actionRequest = $this->controller->getContext()->getRequest();
        } catch (\Throwable) {
        }
        if (!($actionRequest instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing in ActionExecutor::execute'); }
        // No need to attachPsrRequest - AgaviWebRequest IS the PSR-7 request

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
            $useSecurity = \Agavi\Config\AgaviConfig::get('core.use_security', true);
            if (!$useSecurity) {
                $state->securityDecision = SecurityDecision::Allow; // global security disabled
            } elseif (method_exists($action, 'isSecure') && !$action->isSecure()) {
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
            AgaviDebugLogger::debug('[ActionExecutor] rawView=' . var_export($rawView, true), $this->controller->getContext());
        }
        // Snapshot attributes immediately after action code runs (pre-view)
        $attributeSnapshot = [];
        if (method_exists($action, 'getAttributes')) {
            try {
                $attributeSnapshot = $action->getAttributes();
            } catch (\Throwable) {
                $attributeSnapshot = [];
            }
            // Shallow clone to detach from holder internal storage (defensive)
            if (is_array($attributeSnapshot)) {
                $attributeSnapshot = array_merge([], $attributeSnapshot);
            } else {
                $attributeSnapshot = [];
            }
        }

        [$vm, $vn] = $this->viewNameResolver->resolve($desc->module, $desc->action, $rawView);
        $view = null;
        $content = '';
        if ($vn !== AgaviView::NONE) {
            $view = $this->viewFactory?->create($vm, $vn, $desc->module, $desc->action, strtolower($this->controller->getOutputType()->getName()), $actionRequest, $attributeSnapshot, $this->validationService?->getValidationManager());
            if (!$view) {
                $view = $this->createAndInitView($vm, $vn, $desc->module, $desc->action, $actionRequest, $attributeSnapshot);
            }
                if ($view !== null) {
                    $method = $this->selectViewMethod($view, $desc->outputType);
                    $res = $view->$method($actionRequest);
                    if ($res !== null) {
                        $content = (string)$res;
                    } elseif (method_exists($view, 'getLayers') && method_exists($view, 'renderLayers') && $view->getLayers()) {
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
                AgaviDebugLogger::debug('[ActionExecutor] view=' . get_class($view) . ' method=' . $method . ' contentLen=' . strlen($content) . ' prefix=' . $prefix, $this->controller->getContext());
            }
        } else {
            if ($dbg) {
                AgaviDebugLogger::debug('[ActionExecutor] vn is NONE (no view)', $this->controller->getContext());
            }
        }
        $state->securityDecision = SecurityDecision::Allow;
        $state->viewModule = $vm;
        $state->viewName = $vn;
        $bag = new AttributeBag($attributeSnapshot);
        $globalResp = $this->controller->getGlobalResponse();
        $respHandle = new ResponseHandle($globalResp);
        // Snapshot redirect immediately - before any fiber context switch could cause another
        // request to call clear() on the shared global response object.
        $redirectSnapshot = null;
        if (method_exists($globalResp, 'getRedirect') && $globalResp->getRedirect() !== null) {
            $redirectSnapshot = $globalResp->getRedirect();
        }
        $ctx = new ActionExecutionContext($action, $view, $desc->module, $desc->action, $desc->outputType, $actionRequest, $content, $vm, $vn, $attributeSnapshot, $bag, $respHandle, $redirectSnapshot);
        if ($dbg) {
            AgaviDebugLogger::debug('[ActionExecutor] done contentLen=' . strlen($content), $this->controller->getContext());
        }
        return $ctx;
    }

    private function createAndInitView(string $vm, string $vn, string $module, string $action, ServerRequestInterface $rd, array $attributeSnapshot = []): ?AgaviView
    {
        try {
            /** @var AgaviView $view */
            $view = $this->controller->createViewInstance($vm, $vn);
            // Snapshot action attributes if action already executed (not passed here; minimal for now)
            $global = $this->controller->getGlobalResponse();
            $psr = null;
            if ($global instanceof \Agavi\Response\AgaviWebResponse) {
                $psr = new \Agavi\Http\PsrResponseAdapter($global);
            }
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function selectViewMethod(?AgaviView $view, string $outputType): string
    {
        if (!$view) {
            return 'execute';
        }
        $candidate = 'execute' . ucfirst($outputType);
        return is_callable([$view, $candidate]) ? $candidate : 'execute';
    }
}
