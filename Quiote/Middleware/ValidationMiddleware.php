<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Validator\ValidationManager;
use Quiote\View\View;
use Quiote\Execution\ValidationService;
use Quiote\Execution\ValidationDecision;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\ViewFactory;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Request\WebRequest;

/**
 * Runs validation before the action executes, and enforces that only validated parameters
 * are reachable afterwards. A failure is turned into the action's handle*Error() view,
 * rendered here rather than by dispatch.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 20, after: 'SecurityMiddleware', before: 'DispatchMiddleware')]
class ValidationMiddleware implements MiddlewareInterface
{
    use RequestDiagnostics;

    /** Stateless; built once per worker instead of per request. */
    private readonly ValidationService $validationService;

    public function __construct(private readonly Controller $controller)
    {
        $this->validationService = new ValidationService();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $execState = $request->getAttribute(ExecutionState::class);
        if (!$execState instanceof ExecutionState) {
            $execState = new ExecutionState();
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }

        $actionDesc = $request->getAttribute(ActionDescriptor::class);
        if (!$actionDesc instanceof ActionDescriptor) {
            return $handler->handle($request);
        }

        $action = $this->resolveAction($request, $actionDesc);
        $webRequest = $this->canonicalWebRequest();

        if ($action->isSimple()) {
            return $this->handleSimpleAction($request, $handler, $execState, $webRequest);
        }

        $webRequest = $this->adoptPipelineRequest($request, $webRequest);
        $webRequest = $this->promoteRouteParams($request, $webRequest);

        // SecurityMiddleware resets the decision to pending on a forward, so a decided
        // state here means validation already ran for this request.
        if ($execState->validationDecision && !$execState->validationDecision->isPending()) {
            return $handler->handle($request);
        }

        $tokens = self::methodTokens($actionDesc->method);
        $outcome = $this->runValidation($action, $webRequest, $actionDesc, $tokens['config']);

        // ValidationManager may have replaced the context's request with a pruned instance.
        $webRequest = $this->canonicalWebRequest($webRequest);

        // Carry the pipeline request forward as the canonical WebRequest, so downstream
        // middleware (FormPopulation among them) work on the pruned, whitelisted payload.
        $originalPsr = $request->getAttribute('_original_psr_request');
        $request = $webRequest;
        if ($originalPsr instanceof ServerRequestInterface) {
            $request = $request->withAttribute('_original_psr_request', $originalPsr);
        }

        if (!$outcome['hasValidators']) {
            // No validators ran at all, so no parameter has been vetted: expose none.
            $webRequest = $this->clearParameters($webRequest);
        }

        $execState->validationDecision = $outcome['ok']
            ? ValidationDecision::passed()
            : ValidationDecision::failed($outcome['errors']);
        $request = $request
            ->withAttribute(ExecutionState::class, $execState)
            ->withAttribute('quiote.request_data', $webRequest);

        $this->logDecision($execState->validationDecision, $actionDesc, $outcome);

        if ($outcome['ok']) {
            return $handler->handle($request);
        }

        return $this->renderValidationFailure($request, $action, $actionDesc, $tokens, $outcome, $webRequest);
    }

    /**
     * Semantic method tokens derived from the descriptor's method.
     *
     * The compiled validator config compares against lowercase tokens ('read'/'write'), so
     * `<if($method == 'read')>` blocks need the 'config' form, while the validate*() and
     * handle*Error() method names are built from the capitalized 'suffix' form.
     *
     * @return     array{config: string, suffix: string}
     */
    private static function methodTokens(string $method): array
    {
        $provided = strtolower($method);
        $mapped = in_array($provided, ['read', 'write', 'create', 'update', 'remove'], true)
            // Descriptors already carrying a semantic token are used as-is.
            ? $provided
            : HttpMethodMapper::toActionMethod($method !== '' ? $method : 'GET');

        return ['config' => $mapped, 'suffix' => ucfirst($mapped)];
    }

    /**
     * The action to validate against: the instance dispatch pre-built when present,
     * otherwise one created and initialized here.
     */
    private function resolveAction(ServerRequestInterface $request, ActionDescriptor $actionDesc): Action
    {
        $action = $request->getAttribute('quiote.preinstantiated_action');
        if ($action instanceof Action) {
            return $action;
        }

        // Exceptions from here bubble to ErrorHandlingMiddleware: an action that exists but
        // cannot be built is a hard error, not a reason to skip validation.
        $action = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $this->controller->getContext(),
            $actionDesc->module,
            $actionDesc->action,
            $actionDesc->method,
            $actionDesc->outputType,
            $request,
            $this->controller->getGlobalResponse()
        ));

        return $action;
    }

    /**
     * The context's canonical WebRequest -- the instance validator exports write into and
     * actions and views later read. Building an ad-hoc one instead would isolate those
     * exports, because the context would still hand out its own.
     *
     * $fallback is returned when the context cannot produce one, for the re-fetch after
     * validation where losing the request would be worse than keeping a slightly stale
     * reference to it.
     */
    private function canonicalWebRequest(?WebRequest $fallback = null): WebRequest
    {
        try {
            $webRequest = $this->controller->getContext()->getRequest();
        } catch (\Throwable $e) {
            if ($fallback !== null) {
                \Quiote\Logging\Log::for($this)->warning(
                    '[ValidationMiddleware] could not re-read the canonical request, keeping the previous instance: '
                    . $e->getMessage()
                );

                return $fallback;
            }

            throw new \RuntimeException(
                'Canonical WebRequest missing in ValidationMiddleware (must be initialized earlier).',
                0,
                $e
            );
        }

        return $webRequest;
    }

    /**
     * A simple action declares that it needs no parameters at all, so every source is
     * cleared and route-param promotion is skipped -- there is nothing to promote into.
     *
     * A route path segment's value is as attacker-controlled as a query or body parameter,
     * so "needs no parameters" has to mean none of them reach the action either. The PSR-7
     * query and body are cleared alongside the runtime store because
     * DispatchMiddleware::processSimple() rebuilds a WebRequest from this object via
     * ActionExecutor::buildRequestDataFromPsr(), whose setParameter() calls would
     * re-whitelist anything left behind.
     */
    private function handleSimpleAction(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        ExecutionState $execState,
        WebRequest $webRequest,
    ): ResponseInterface {
        $webRequest = $this->clearParameters($webRequest);
        $request = $request->withQueryParams([])->withParsedBody([]);

        $execState->validationDecision = ValidationDecision::passed();
        $request = $request
            ->withAttribute(ExecutionState::class, $execState)
            ->withAttribute('quiote.request_data', $webRequest);

        return $handler->handle($request);
    }

    /**
     * Drop every runtime parameter and publish the result as the context's request.
     */
    private function clearParameters(WebRequest $webRequest): WebRequest
    {
        $cleared = $webRequest->clearParameters();
        $this->publish($cleared);

        return $cleared;
    }

    /**
     * Install $webRequest as the context's canonical request.
     *
     * WebRequest is immutable, so every with*()/set*() above produced a new instance that
     * the context does not yet know about; without this, code reading
     * Context::getRequest() would keep seeing the pre-modification one.
     */
    private function publish(WebRequest $webRequest): void
    {
        $this->controller->getContext()->setRequest($webRequest);
    }

    /**
     * Fold whatever the pipeline did to the request into the canonical WebRequest.
     *
     * A foreign PSR-7 request's state is overlaid rather than replacing the canonical
     * instance: a fresh WebRequest::fromPsr() would discard the validated-parameter
     * whitelist and runtime parameters the pipeline request never carried, which strict
     * unvalidated-access checking depends on.
     */
    private function adoptPipelineRequest(ServerRequestInterface $request, WebRequest $webRequest): WebRequest
    {
        if ($request === $webRequest) {
            return $webRequest;
        }

        if ($request instanceof WebRequest) {
            $this->publish($request);

            return $request;
        }

        $webRequest = $webRequest
            ->withMethod($request->getMethod())
            ->withUri($request->getUri())
            ->withQueryParams($request->getQueryParams())
            ->withParsedBody($request->getParsedBody());
        foreach ($request->getAttributes() as $name => $value) {
            $webRequest = $webRequest->withAttribute($name, $value);
        }
        $this->publish($webRequest);

        return $webRequest;
    }

    /**
     * Make route parameters visible to validators as ordinary input, before validation runs.
     *
     * Promoted with setUnvalidatedParameter() rather than setParameter(): the latter
     * auto-whitelists as a trusted-export side effect, which would let a route param like
     * {id} reach getParameter() with no validator targeting it. Promoted unvalidated, the
     * raw value is still visible to a validator that does target it -- ValidationManager
     * whitelists by validator-declared argument name before running them -- and pruning
     * removes it otherwise, exactly as for an unvalidated query or body parameter.
     *
     * Underscore-prefixed keys are internal routing metadata, and a name already present in
     * query or body is left alone rather than overwritten.
     */
    private function promoteRouteParams(ServerRequestInterface $request, WebRequest $webRequest): WebRequest
    {
        $routeParams = $request->getAttribute('route_params');
        if (!is_array($routeParams) || $routeParams === []) {
            return $webRequest;
        }

        $queryParams = $webRequest->getQueryParams();
        $bodyParams = $webRequest->getParsedBody();
        $injected = [];
        foreach ($routeParams as $name => $value) {
            if ($name === '' || $name[0] === '_' || is_array($value)) {
                continue;
            }
            $alreadyPresent = array_key_exists($name, $queryParams)
                || (is_array($bodyParams) && array_key_exists($name, $bodyParams));
            if (!$alreadyPresent) {
                $injected[$name] = $value;
            }
        }

        if ($injected === []) {
            return $webRequest;
        }

        $webRequest = $webRequest->withUnvalidatedParameters($injected);
        $this->publish($webRequest);

        return $webRequest;
    }

    /**
     * Run the XML-configured validators, then the action's manual validate*() hooks.
     *
     * Deliberately uncaught: a validator or a manual hook throwing is a framework or
     * application bug, not invalid user input. ValidationService logs it before rethrowing,
     * and letting it propagate means ErrorHandlingMiddleware turns it into a 500 rather than
     * this middleware presenting it as an ordinary validation failure -- which would also
     * leave the request unpruned, since pruning happens inside the execute() call that threw.
     *
     * 'hasValidators' reports whether anything actually vetted the input, counting both a
     * validators config file and validators registered through register{Method}Validators().
     * Without the second case the caller's parameter clear would wipe values
     * ValidationManager::execute() had just whitelisted, leaving getParameter() permitted
     * but answering null for a value the client did submit.
     *
     * @return     array{ok: bool, hasValidators: bool, errors: list<string>}
     */
    private function runValidation(
        Action $action,
        WebRequest $webRequest,
        ActionDescriptor $actionDesc,
        string $configMethodToken,
    ): array {
        $xmlResult = $this->validationService->xmlOnlyValidate(
            $action,
            $webRequest,
            $actionDesc->module,
            $actionDesc->action,
            $configMethodToken
        );

        $trace = $xmlResult->getTrace();
        $hasValidators = $trace instanceof \Quiote\Execution\ValidationTrace && (
            ($trace->configFile !== null && $trace->configFile !== '')
            || !empty($trace->validatorsLoaded)
        );

        if (!$xmlResult->ok) {
            /** @var list<string> $errors */
            $errors = $xmlResult->getErrors() ?: ['validation_failed'];

            return ['ok' => false, 'hasValidators' => $hasValidators, 'errors' => $errors];
        }

        $suffix = self::methodTokens($actionDesc->method)['suffix'];
        $ok = true;
        $validateMethod = 'validate' . $suffix;
        if (is_callable([$action, $validateMethod])) {
            $ok = (bool) $action->$validateMethod($webRequest);
        }
        if ($ok) {
            $ok = (bool) $action->validate($webRequest);
        }

        return [
            'ok' => $ok,
            'hasValidators' => $hasValidators,
            'errors' => $ok ? [] : ['manual_validation_failed'],
        ];
    }

    /**
     * @param array{ok: bool, hasValidators: bool, errors: list<string>} $outcome
     */
    private function logDecision(ValidationDecision $decision, ActionDescriptor $actionDesc, array $outcome): void
    {
        $logger = \Quiote\Logging\Log::for($this);
        if (!$logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            return;
        }

        $errStr = $outcome['ok'] ? '' : (' errors=' . json_encode($outcome['errors']));
        $logger->debug(
            '[ValidationMiddleware] decision=' . $decision->state
            . ' module=' . $actionDesc->module
            . ' action=' . $actionDesc->action
            . ' method=' . $actionDesc->method
            . ' sessId=' . $this->diagnosticSessionId()
            . ' auth=' . $this->diagnosticAuthState()
            . $errStr
        );
    }

    /**
     * Turn a validation failure into a response: baseline 400, run the action's
     * handle*Error() hook, render the view it names, and repopulate the submitted values
     * into an HTML form.
     *
     * @param array{config: string, suffix: string} $tokens
     * @param array{ok: bool, hasValidators: bool, errors: list<string>} $outcome
     */
    private function renderValidationFailure(
        ServerRequestInterface $request,
        Action $action,
        ActionDescriptor $actionDesc,
        array $tokens,
        array $outcome,
        WebRequest $webRequest,
    ): ResponseInterface {
        $errors = $outcome['errors'];

        // A validation failure is always a 400. The view name handle*Error() returns is
        // presentation detail an app author picks freely, so branching status on it would
        // let an arbitrary string double as an unintended status override. The supported
        // override is WebResponse::setHttpStatusCode(), read back when the response is
        // assembled -- the same convention DispatchMiddleware honours on the success path.
        $this->controller->getGlobalResponse()->setHttpStatusCode(400);

        $request = $request->withAttribute('quiote.validation.errors', $errors);

        $handleErrorMethod = 'handle' . ($tokens['suffix'] !== '' ? $tokens['suffix'] : 'Default') . 'Error';
        if (!is_callable([$action, $handleErrorMethod])) {
            $handleErrorMethod = 'handleError';
        }
        $rawViewName = $action->$handleErrorMethod($webRequest);

        // A handle*Error() that exports data via setParameter() only changed its own copy
        // unless it also published it to the context, so re-read rather than reusing the
        // instance captured before the hook ran.
        $webRequest = $this->canonicalWebRequest($webRequest);

        [$viewModule, $viewName] = (new ViewNameResolver())->resolve($actionDesc->module, $actionDesc->action, $rawViewName);

        $execState = $request->getAttribute(ExecutionState::class);
        if ($execState instanceof ExecutionState) {
            $execState->viewModule = $viewModule;
            $execState->viewName = $viewName;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }

        // The error view renders inside this middleware, before the pipeline reaches
        // SlotMiddleware, so nothing has attached a SlotStack yet and a view calling
        // renderSlot() would fail. Attach one here, as SlotMiddleware would.
        $webRequest = $this->ensureSlotStack($webRequest, $request);
        $this->publish($webRequest);

        if ($viewName === View::NONE) {
            return \Quiote\Http\Psr17::factory()->createResponse(400);
        }

        // ViewNameResolver only returns a null module alongside a null view name, which is
        // the View::NONE case handled above.
        if ($viewModule === null) {
            throw new \RuntimeException('ViewNameResolver returned a null module for a non-null view name.');
        }

        try {
            return $this->renderErrorView($request, $action, $actionDesc, $viewModule, $viewName, $errors, $webRequest);
        } catch (\Throwable $e) {
            \Quiote\Logging\Log::for($this)->error(
                '[ValidationMiddleware] error view rendering failed for ' . $viewModule . ':' . $viewName
                . ': ' . $e::class . ': ' . $e->getMessage()
            );

            $factory = \Quiote\Http\Psr17::factory();
            $resp = $factory->createResponse(400)
                ->withHeader('X-Quiote-Validation', 'failed')
                ->withHeader('X-Quiote-Validation-Reason', 'view_creation_exception');

            return $this->withErrorDetailHeader($resp, $errors)->withBody($factory->createStream('Error'));
        }
    }

    /**
     * Render the named error view and assemble the failure response around its output.
     *
     * @param list<string> $errors
     */
    private function renderErrorView(
        ServerRequestInterface $request,
        Action $action,
        ActionDescriptor $actionDesc,
        string $viewModule,
        string $viewName,
        array $errors,
        WebRequest $webRequest,
    ): ResponseInterface {
        $actionContext = $action->getContext();
        if ($actionContext === null) {
            throw new \RuntimeException('Action must be initialized before an error view can be created.');
        }
        $controller = $actionContext->getContainer()->get(\Quiote\Controller\Controller::class);
        $validationManager = $this->validationService->getValidationManager();

        // Render in the negotiated output type dispatch would have used, not the
        // controller default: an Accept: application/json request that fails validation
        // must not run executeHtml and answer with markup.
        $outputType = $this->resolveErrorOutputType($request, $controller);

        $view = (new ViewFactory($controller))->create(
            $viewModule,
            $viewName,
            $actionDesc->module,
            $actionDesc->action,
            $outputType,
            $webRequest,
            [],
            $validationManager
        );

        $factory = \Quiote\Http\Psr17::factory();
        if (!$view) {
            \Quiote\Logging\Log::for($this)->warning(
                '[ValidationMiddleware] view creation returned null for ' . $viewModule . ':' . $viewName
            );
            $resp = $factory->createResponse(400)
                ->withHeader('X-Quiote-Validation', 'failed')
                ->withHeader('X-Quiote-Validation-Reason', 'view_not_created');

            return $resp->withBody($factory->createStream($viewName));
        }

        [$content, $problemJson] = $this->executeErrorView($view, $outputType, $webRequest, $errors, $request);

        if ($outputType === 'html' && is_string($content) && $content !== '') {
            $content = $this->repopulateForm($content, $outputType, $controller, $webRequest);
        }

        $request = $request->withAttribute('validation.error.content', (string) $content);

        return $this->buildFailureResponse($factory, $controller, $outputType, $content, $problemJson, $errors);
    }

    /**
     * Invoke the view's output-type method and normalize what it produced.
     *
     * For JSON, an RFC 9457 Problem Details document stands in when the view has no
     * executeJson() or its executeJson() returns null. A view that does render a JSON body
     * is left untouched -- its shape is an API contract. An explicit empty string is a
     * deliberate "no body" choice and is respected, since only null triggers the fallback.
     *
     * @param      list<string> $errors
     * @return     array{0: ?string, 1: bool} [content, whether it is a synthesized problem document]
     */
    private function executeErrorView(
        View $view,
        string $outputType,
        WebRequest $webRequest,
        array $errors,
        ServerRequestInterface $request,
    ): array {
        $method = 'execute' . ucfirst($outputType);
        $hasMethod = is_callable([$view, $method]);
        $problemJson = false;

        if ($outputType === 'json') {
            $content = $hasMethod ? $view->$method($webRequest) : null;
            if ($content === null) {
                $content = $this->buildValidationProblemDetails(
                    $this->validationService->getValidationManager(),
                    $errors,
                    $request
                );
                $problemJson = true;
            }
        } elseif ($hasMethod) {
            $content = $view->$method($webRequest);
        } else {
            $content = $view->execute($webRequest);
        }

        // A view that calls loadLayout()/appendLayer() and returns null expects its
        // configured layers to be rendered by the caller, as ActionExecutor does.
        if ($content === null && $outputType !== 'json' && $view->getLayers()) {
            $layerContent = $view->renderLayers();
            if ($layerContent !== '') {
                $content = $layerContent;
            }
        }

        // Dynamic execute*() calls are untypeable beyond mixed; normalize once here.
        if ($content !== null && !is_string($content)) {
            $content = is_scalar($content) ? (string) $content : '';
        }

        return [$content, $problemJson];
    }

    /**
     * Write the submitted values back into the rendered form (the sticky-form behaviour).
     *
     * Sourced from ValidationManager's raw pre-prune snapshot rather than the request: a
     * value that failed even one of several validators registered against the same field is
     * scrubbed from the request by design, which is right for business logic but would
     * redisplay the field blank instead of showing what the user typed.
     *
     * HTML only -- an API client holds its own submitted state, and the DOM rewriting this
     * performs only makes sense for markup.
     */
    private function repopulateForm(
        string $content,
        string $outputType,
        Controller $controller,
        WebRequest $webRequest,
    ): string {
        try {
            $vm = $this->validationService->getValidationManager();
            $rawSnapshot = $vm instanceof ValidationManager ? $vm->getRawParameterSnapshot() : [];
            if (!$rawSnapshot) {
                return $content;
            }

            $globalResponse = $controller->getGlobalResponse();
            $globalResponse->setContent($content);
            // FormPopulationEngine gates on the response's output type (via the
            // 'output_types' option below), so it must carry the negotiated one rather than
            // whatever it last held -- which under a long-running worker may be a previous
            // request's, or nothing at all.
            $globalResponse->setOutputType($controller->getOutputType($outputType));

            $engine = new \Quiote\Util\FormPopulationEngine();
            $engine->initialize($controller->getContext());
            try {
                $engine->populate($globalResponse, $webRequest, [
                    // A ParameterHolder is used verbatim as the global value source for
                    // every field on the page; a plain array under 'populate' is instead
                    // read as a per-form-id map.
                    'populate' => new \Quiote\Util\ParameterHolder($rawSnapshot),
                    'validation_report' => $vm->getReport(),
                    'output_types' => ['html'],
                ]);
            } finally {
                $engine->reset();
            }

            $populated = $globalResponse->getContent();

            return is_string($populated) && $populated !== '' ? $populated : $content;
        } catch (\Throwable $e) {
            // Repopulation is a UX nicety: never let it turn a validation failure into a 500.
            \Quiote\Logging\Log::for($this)->warning(
                '[ValidationMiddleware] form repopulation failed, serving the unpopulated body: ' . $e->getMessage()
            );

            return $content;
        }
    }

    /**
     * Assemble the failure response: status, content type, diagnostic headers, body.
     *
     * @param list<string> $errors
     */
    private function buildFailureResponse(
        \Psr\Http\Message\ResponseFactoryInterface&\Psr\Http\Message\StreamFactoryInterface $factory,
        Controller $controller,
        string $outputType,
        ?string $content,
        bool $problemJson,
        array $errors,
    ): ResponseInterface {
        $resp = $factory->createResponse(400)->withHeader('X-Quiote-Validation', 'failed');

        // Honour an explicit setHttpStatusCode() from handle*Error() or the error view,
        // over the 400 baselined before the hook ran.
        $statusCode = (int) $controller->getGlobalResponse()->getHttpStatusCode();
        if ($statusCode >= 100) {
            $resp = $resp->withStatus($statusCode);
        }

        $resp = $resp->withHeader('Content-Type', $this->failureContentType($controller, $outputType, $problemJson));
        $resp = $this->withErrorDetailHeader($resp, $errors);

        if ($content !== null) {
            $resp = $resp->withBody($factory->createStream($content));
        }

        return $resp;
    }

    /**
     * The Content-Type for a validation-failure body: the RFC 9457 media type for a
     * synthesized problem document, else one the view set explicitly, else the type
     * configured for the output type, else whatever the mime registry knows about it.
     */
    private function failureContentType(Controller $controller, string $outputType, bool $problemJson): string
    {
        if ($problemJson) {
            return 'application/problem+json; charset=UTF-8';
        }

        $viewContentType = $controller->getGlobalResponse()->getContentType();
        if (is_string($viewContentType) && $viewContentType !== '') {
            return $viewContentType;
        }

        $configuredMime = null;
        try {
            $configured = $controller->getOutputType($outputType)->getParameter('http_headers[Content-Type]') ?: null;
            $configuredMime = is_scalar($configured) ? (string) $configured : null;
        } catch (\Throwable $e) {
            \Quiote\Logging\Log::for($this)->debug(
                '[ValidationMiddleware] no configured Content-Type for output type "' . $outputType
                . '", falling back to the mime registry: ' . $e->getMessage()
            );
        }

        return $configuredMime
            ?? \Quiote\Http\MimeTypeRegistry::primaryMimeType($outputType)
            ?? 'text/plain';
    }

    /**
     * Attach the validation-error detail header when explicitly enabled.
     *
     * Off by default: it exposes internal field and validator structure to the client.
     * Enable for a trusted dev or test front-end with
     * `Config::set('core.expose_validation_errors_header', true)`.
     *
     * @param list<string> $errors
     */
    private function withErrorDetailHeader(ResponseInterface $resp, array $errors): ResponseInterface
    {
        if ($errors === [] || !\Quiote\Config\Config::getBool('core.expose_validation_errors_header', false)) {
            return $resp;
        }

        $encoded = json_encode($errors);
        if ($encoded === false) {
            return $resp;
        }

        return $resp->withHeader('X-Quiote-Validation-Errors', base64_encode($encoded));
    }

    /**
     * Determine the output type to render the validation-error view in: the
     * negotiated type used by dispatch (ActionDescriptor->outputType, then the
     * request's 'output_type' attribute), falling back to the controller default.
     * @param \Quiote\Controller\Controller $controller The controller dispatching the request.
     */
    private function resolveErrorOutputType(\Psr\Http\Message\ServerRequestInterface $request, $controller): string
    {
        $descriptor = $request->getAttribute(\Quiote\Execution\ActionDescriptor::class);
        if ($descriptor instanceof \Quiote\Execution\ActionDescriptor
            && $descriptor->outputType !== '') {
            return strtolower($descriptor->outputType);
        }
        $attrOt = $request->getAttribute('output_type');
        if (is_string($attrOt) && $attrOt !== '') {
            return strtolower($attrOt);
        }
        try {
            return strtolower((string) $controller->getOutputType()->getName());
        } catch (\Throwable) {
            return 'html';
        }
    }

    /**
     * Build an RFC 9457 (Problem Details) document describing the validation
     * failures, so API clients that requested application/json receive the actual
     * field errors even when the action's error view only renders HTML.
     * The body is `application/problem+json`:
     *   {
     *     "type": "about:blank",           // or core.problem_details.validation_type
     *     "title": "Bad Request",          // status phrase for about:blank, else the configured title
     *     "status": 400,
     *     "instance": "/orders/offers/new",
     *     "errors": { "field": ["message", ...], ... }   // extension member; "" = non-field errors
     *   }
     * The `errors` map (field -> messages) follows the widely-recognised
     * validation-problem convention. Falls back to the flat message list under
     * the "" key when the report cannot be introspected.
     * @param ?object $vm       The validation manager (may be null).
     * @param array<int|string, mixed> $fallback Flat list of error messages (keys are ignored).
     */
    private function buildValidationProblemDetails($vm, array $fallback, \Psr\Http\Message\ServerRequestInterface $request): string
    {
        $errors = \Quiote\Http\ProblemDetails::extractErrors($vm);
        if ($errors === [] && $fallback !== []) {
            // The report had no field-scoped incidents (e.g. a manual validate()
            // returning false); surface the flat messages as non-field errors.
            $messages = array_values(array_unique(array_filter(
                array_map(static fn($m) => is_scalar($m) ? (string) $m : '', $fallback),
                static fn(string $m) => $m !== ''
            )));
            if ($messages !== []) {
                $errors = ['' => $messages];
            }
        }

        $instance = null;
        try {
            $instance = $request->getUri()->getPath();
        } catch (\Throwable $e) {
            // The document omits its "instance" member, which RFC 9457 allows.
            \Quiote\Logging\Log::for($this)->debug(
                '[ValidationMiddleware] request path unavailable for the problem-details instance '
                . 'member: ' . $e->getMessage()
            );
        }

        return \Quiote\Http\ProblemDetails::create(status: 400, instance: $instance, errors: $errors)->toJson();
    }

    /**
     * Attach a {@see SlotMiddleware::ATTR} SlotStack to $webRequest if one
     * isn't already present, mirroring SlotMiddleware::process() exactly
     * (including preserving the pre-pruning original request slots read
     * from). Needed because the validation-failure path renders its error
     * view before SlotMiddleware ever runs.
     */
    private function ensureSlotStack(WebRequest $webRequest, ServerRequestInterface $request): WebRequest
    {
        if ($webRequest->getAttribute(SlotMiddleware::ATTR)) {
            return $webRequest;
        }
        $slotStack = new \Quiote\Execution\SlotStack();
        $originalRequest = $request->getAttribute('_original_psr_request');
        if ($originalRequest instanceof ServerRequestInterface) {
            $slotStack->setOriginalRequest($originalRequest);
        }
        return $webRequest->withAttribute(SlotMiddleware::ATTR, $slotStack);
    }
}
