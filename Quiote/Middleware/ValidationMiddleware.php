<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Validator\ValidationManager;
use Quiote\Http\PsrResponseAdapter;
use Quiote\View\View;
use Quiote\Execution\ValidationService;
use Quiote\Execution\ValidationDecision;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\ViewFactory;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Request\WebRequest;

/**
 * Executes validation early (before action execution) and enforces strict access to validated params only.
 * If validation fails, converts flow to handleError view resolution path similar to legacy performValidation().
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 20, after: 'SecurityMiddleware', before: 'DispatchMiddleware')]
class ValidationMiddleware implements MiddlewareInterface
{
    /** Stateless; built once per worker instead of per request. */
    private readonly ValidationService $validationService;

    public function __construct(private ?\Quiote\Controller\Controller $controller = null)
    {
        $this->validationService = new ValidationService();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $execState = $request->getAttribute(ExecutionState::class);
        // Always ensure we have an ExecutionState so downstream code can rely on it
        if (!$execState instanceof ExecutionState) {
            $execState = new ExecutionState();
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        $actionDesc = $request->getAttribute(\Quiote\Execution\ActionDescriptor::class);
        if (!$actionDesc) {
            return $handler->handle($request);
        }
        $vd = \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug);
        $moduleName = $actionDesc->module;
        $actionName = $actionDesc->action;
        $method = $actionDesc->method;
        // Map HTTP verbs or custom indicators to legacy semantic method names (Read|Write).
        // IMPORTANT: The compiled validator config files compare against lowercase tokens 'read' / 'write'.
        // We keep a normalized (capitalized) variant for naming validate* / handle*Error methods, but
        // pass the lowercase token to xmlOnlyValidate so <if($method == 'read')> blocks fire.
        // Derive canonical action method via central mapper then build normalized token for legacy method names
        $providedMethod = is_string($method) ? strtolower($method) : '';
        if ($providedMethod !== '' && in_array($providedMethod, ['read', 'write', 'create', 'update', 'remove'], true)) {
            // Action descriptors already use legacy semantic tokens – use as-is.
            $mapped = $providedMethod;
        } else {
            $mapped = HttpMethodMapper::toActionMethod($method ?: 'GET'); // normalize HTTP verbs to legacy tokens
        }
        $normalizedMethod = ucfirst($mapped);
        $lowerMethodToken = $mapped; // used for XML config inclusion conditions
        // Create the action instance (descriptor holds metadata only).
        $action = $request->getAttribute('quiote.preinstantiated_action');
        /* 
        We should always have a preinstantiated action        */
        if ($vd) {
            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] preinstantiated_action=' . gettype($action));
        }
        if (!$action) {
            if ($vd) {
                \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware]: pre-instantiated action not found');
            }
            $controller = $this->controller;
            if (!$controller) {
                try {
                    $controller = \Quiote\Quiote::context('web', true)->getController();
                } catch (\Throwable) {
                }
            }
            if (!$controller) {
                try {
                    $controller = \Quiote\Quiote::context('web', true)->getController();
                } catch (\Throwable) {
                }
            }
            if ($controller) {
                // Let exceptions bubble to ErrorHandlingMiddleware – failure is a hard error.
                $action = $controller->createActionInstance($moduleName, $actionName);
                // Use the PSR request for type compatibility; action methods still receive WebRequest param from dispatcher later.
                $initCtx = new \Quiote\Execution\LightweightActionInitContext(
                    $controller->getContext(),
                    $moduleName,
                    $actionName,
                    $actionDesc->method,
                    $actionDesc->outputType,
                    $request,
                    $controller->getGlobalResponse()
                );
                $action->initialize($initCtx);
            }
        }

        // Reuse the context's WebRequest so that validator exports (which write into runtime parameters)
        // are later visible to the action and views. Creating a fresh instance would isolate exports.
        // Always obtain (and thus materialize if needed) the context request so we mutate the
        // exact instance actions/views will later read. Creating an ad-hoc WebRequest would
        // isolate validator exports from downstream code because Context::getRequest() would
        // lazily create a different instance afterwards.
        $webRequest = null;
        // Ensure we resolve a controller reference early so context reuse works even if constructor passed null
        if ($this->controller === null) {
            try {
                $this->controller = \Quiote\Quiote::context('web', true)->getController();
            } catch (\Throwable) {
            }
        }
        try {
            $webRequest = $this->controller?->getContext()?->getRequest();
        } catch (\Throwable) {
        }
        if (!($webRequest instanceof WebRequest)) {
            throw new \RuntimeException('Canonical WebRequest missing in ValidationMiddleware (must be initialized earlier).');
        }

        // isSimple() means the action needs NO parameters at all -- not "skip
        // validation but still allow raw access". A route path segment's VALUE
        // is just as attacker-controlled as a query/body parameter (e.g. a
        // "slug" of "' OR 1=1;--"), so it must not reach the action either.
        // Every parameter source (query, body, runtime, and route params) is
        // therefore cleared unconditionally below, and route-param promotion
        // is skipped entirely -- there is nothing for it to promote INTO.
        $isSimple = $action && method_exists($action, 'isSimple') && $action->isSimple();

        if ($isSimple) {
            try {
                $webRequest = $webRequest->clearParameters();
                $this->controller?->getContext()?->setRequest($webRequest);
            } catch (\Throwable) {
            }
            // Clear the PSR-7 request too: DispatchMiddleware::processSimple()
            // rebuilds a WebRequest via ActionExecutor::buildRequestDataFromPsr(),
            // which reads THIS object's query/body directly and calls
            // setParameter() for each -- setParameter() auto-whitelists, so a
            // raw query/body param would otherwise resurrect and become
            // whitelisted right after the clear above.
            $request = $request->withQueryParams([])->withParsedBody([]);
            $execState->validationDecision = ValidationDecision::passed();
            $request = $request
                ->withAttribute(ExecutionState::class, $execState)
                ->withAttribute('quiote.request_data', $webRequest);
            return $handler->handle($request);
        }

        // If the request changed in the pipeline, sync it back to context.
        if ($request !== $webRequest) {
            if ($request instanceof WebRequest) {
                // Already an WebRequest (e.g. a with*() clone) — use it directly.
                $this->controller->getContext()->setRequest($request);
                $webRequest = $request;
            } else {
                // Generic PSR-7 request: overlay its pipeline state onto the canonical
                // WebRequest rather than replacing it, so the request's
                // Quiote-specific validation state (validatedKeys whitelist, runtime
                // parameters) is preserved. A fresh WebRequest::fromPsr($request)
                // would reset that state — the pipeline request doesn't carry it —
                // and break strict unvalidated-parameter access.
                $webRequest = $webRequest
                    ->withMethod($request->getMethod())
                    ->withUri($request->getUri())
                    ->withQueryParams($request->getQueryParams())
                    ->withParsedBody($request->getParsedBody());
                foreach ($request->getAttributes() as $name => $value) {
                    $webRequest = $webRequest->withAttribute($name, $value);
                }
                $this->controller->getContext()->setRequest($webRequest);
            }
        }
        
        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware][debug] using context WebRequest (shared)');
        }
        // Promote route params (excluding internal underscore-prefixed keys) into runtime parameters
        // BEFORE validation so validators treat them like any other input (GET/POST/etc.).
        try {
            $routeParams = $request->getAttribute('route_params');
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try {
                    \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware][debug] route_params=' . json_encode($routeParams, JSON_UNESCAPED_SLASHES));
                } catch (\Throwable) {
                }
            }
            if (is_array($routeParams) && $routeParams) {
                $injected = [];
                foreach ($routeParams as $k => $v) {
                    if ($k !== '' && $k[0] !== '_' && !is_array($v)) {
                        // Check if param already exists in query/body (don't overwrite)
                        $queryParams = $webRequest->getQueryParams();
                        $bodyParams = $webRequest->getParsedBody();
                        $exists = array_key_exists($k, $queryParams) || 
                                  (is_array($bodyParams) && array_key_exists($k, $bodyParams));
                        
                        if (!$exists) {
                            $webRequest = $webRequest->setParameter($k, $v);
                            $injected[$k] = $v;
                        }
                    }
                }
                if ($injected) {
                    // Also merge into raw query params so validators reading query directly see them.
                    if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                        try {
                            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware][debug] injected_route_params_runtime=' . json_encode($injected, JSON_UNESCAPED_SLASHES));
                        } catch (\Throwable) {
                        }
                    }
                    // Update context with the modified request (has route params in runtime parameters)
                    $this->controller->getContext()->setRequest($webRequest);
                }
            }
        } catch (\Throwable) {
            // ignore promotion errors – validation will proceed without route params if something unexpected happens
        }

        if ($vd) {
            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleare] Already validated?');
        }
        // Skip if already validated
        // Re-run only if not yet decided; SecurityMiddleware may reset validationPerformed on forward.
        if ($execState->validationDecision && !$execState->validationDecision->isPending()) {
            if ($vd) {
                \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] YES');
            }
            return $handler->handle($request);
        }

        if ($vd) {
            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddlware] NO');
        }

        $ok = true;
        $hasXml = false;
        $errors = [];
        $vs = $this->validationService;
        // Deliberately uncaught: a validator or a manual validate*()/validate()
        // hook throwing is a critical framework/app bug, not "the user submitted
        // invalid input". ValidationService already logs it at error level before
        // rethrowing; letting it propagate here means ErrorHandlingMiddleware
        // turns it into a 500 instead of this middleware masquerading it as an
        // ordinary graceful validation failure (which could also leave the
        // request in an unpruned, unsafe state -- pruning happens inside the
        // very execute() call that threw).
        if ($action && method_exists($action, 'isSimple') && $action->isSimple()) {
            $ok = true; // simple actions bypass validation
            // simple action bypass
        } else {
            // Attempt XML-only validation first (must use lowercase token so compiled config matches)
            $xmlRes = $vs->xmlOnlyValidate($action, $webRequest, $moduleName, $actionName, $lowerMethodToken);
            if ($vd) {
                try {
                    $t = $xmlRes->getTrace();
                    if ($t) {
                        \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] trace configFile=' . ($t->configFile ?? 'null') . ' validators=' . implode(',', $t->validatorsLoaded ?? []));
                    }
                } catch (\Throwable) {
                }
            }
            $trace = $xmlRes->getTrace();
            // "hasXml" gates whether we clear/lock down the request below. It must
            // also be true when validators were registered manually via
            // register{Method}Validators() (no validators.xml file), otherwise the
            // clearParameters() call further down wipes the parameter values that
            // ValidationManager::execute() just whitelisted, leaving getParameter()
            // whitelisted but returning null for a value that was actually submitted.
            $hasXml = $trace && (
                (property_exists($trace, 'configFile') && $trace->configFile !== null && $trace->configFile !== '')
                || (property_exists($trace, 'validatorsLoaded') && !empty($trace->validatorsLoaded))
            );
            $ok = $xmlRes->ok;
            if (!$ok) {
                $errors = $xmlRes->getErrors() ?: ['validation_failed'];
            }
            // xml validation phase complete
            if ($ok) {
                // Manual action validation phase
                $validateMethod = 'validate' . $normalizedMethod;
                if (is_callable([$action, $validateMethod])) {
                    $ok = (bool)$action->$validateMethod($webRequest);
                }
                if ($ok && is_callable([$action, 'validate'])) {
                    $ok = (bool)$action->validate($webRequest);
                }
                if (!$ok) {
                    $errors[] = 'manual_validation_failed';
                }
                // manual validation phase complete
            }
        }

        // CRITICAL: Re-fetch request from context after validation
        // ValidationManager may have replaced it with a pruned immutable instance
        try {
            $updatedRequest = $this->controller?->getContext()?->getRequest();
            if ($updatedRequest instanceof WebRequest) {
                $webRequest = $updatedRequest;
            }
        } catch (\Throwable) {
            // Keep existing reference if fetch fails
        }

        // Keep the PSR request in sync with the canonical WebRequest so downstream
        // middleware (e.g. FormPopulation) continue working on the pruned/whitelisted payload.
        $originalPsr = $request->getAttribute('_original_psr_request');
        $request = $webRequest;
        if ($originalPsr instanceof ServerRequestInterface) {
            $request = $request->withAttribute('_original_psr_request', $originalPsr);
        }

        // If no validators (XML or manually registered) ran, treat as success but expose
        // ZERO parameters to action (strict empty set)
        if (!$hasXml && !$action?->isSimple()) {
            // Clear webRequest parameters and lock down
            try {
                $webRequest = $webRequest->clearParameters();
                $this->controller->getContext()->setRequest($webRequest);
            } catch (\Throwable) {
            }
            // no xml => params cleared
        }
        $execState->validationDecision = $ok ? ValidationDecision::passed() : ValidationDecision::failed($errors);
        $request = $request
            ->withAttribute(ExecutionState::class, $execState)
            ->withAttribute('quiote.request_data', $webRequest);
        if ($vd) {
            $errStr = !$ok ? (' errors=' . json_encode($errors)) : '';
            $sessId = 'no-sid';
            try {
                $storage = $this->controller?->getContext()?->getStorage();
                if ($storage && method_exists($storage, 'getId')) {
                    $sidTmp = $storage->getId();
                    if (is_string($sidTmp) && $sidTmp !== '') {
                        $sessId = $sidTmp;
                    }
                }
            } catch (\Throwable) {
                // swallow
            }
            if ($sessId === 'no-sid' && function_exists('session_id')) {
                try {
                    $sidNative = session_id();
                    if (is_string($sidNative) && $sidNative !== '') {
                        $sessId = $sidNative;
                    }
                } catch (\Throwable) {
                }
            }
            $auth = 'na';
            try {
                $user = $this->controller?->getContext()?->getUser();
                if ($user && method_exists($user, 'isAuthenticated')) {
                    $auth = $user->isAuthenticated() ? '1' : '0';
                }
            } catch (\Throwable) {
            }
            \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] decision=' . $execState->validationDecision->state . ' module=' . $moduleName . ' action=' . $actionName . ' method=' . $method . ' simple=' . (($action && method_exists($action, 'isSimple') && $action->isSimple()) ? '1' : '0') . ' sessId=' . $sessId . ' auth=' . $auth . $errStr);
        }
        if ($ok) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try {
                    \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware][debug] post-validation SUCCESS');
                } catch (\Throwable) {
                }
            }
            return $handler->handle($request);
        }
        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            try {
                \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware][debug] post-validation FAILURE');
            } catch (\Throwable) {
            }
        }
        // failure path
        // Validation failed => 400 with errors and stash for form population
        $request = $request->withAttribute('quiote.validation.errors', $errors);
        $method = $normalizedMethod ?: 'Default';
        $handleErrorMethod = 'handle' . $method . 'Error';
        if (!is_callable([$action, $handleErrorMethod])) {
            $handleErrorMethod = 'handleError';
        }
        $rawViewName = $action ? $action->$handleErrorMethod($webRequest) : 'Error';
        // WebRequest is immutable: a handle*Error() that exports data via setParameter()
        // (e.g. so the error view can read it back) only replaces its own local copy unless
        // it also calls $this->getContext()->setRequest($request). Re-fetch so the error
        // view created below sees that self-synced instance rather than the stale $webRequest
        // captured before handle*Error() ran (mirrors ActionExecutor::doExecute()'s same
        // re-fetch on the success path).
        try {
            $refreshedRequest = $this->controller?->getContext()?->getRequest();
            if ($refreshedRequest instanceof WebRequest) {
                $webRequest = $refreshedRequest;
            }
        } catch (\Throwable) {
        }
        $resolver = new ViewNameResolver();
        [$viewModule, $viewName] = $resolver->resolve($moduleName, $actionName, $rawViewName);
        $execState->viewModule = $viewModule;
        $execState->viewName = $viewName;
        $request = $request->withAttribute(ExecutionState::class, $execState);
        // Execute view immediately so downstream dispatch middleware can skip action logic
        if ($viewName === View::NONE) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            return $factory->createResponse(400);
        }
        // Create view via controller and ImmutableViewInitContext
        try {
            $controller = $action->getContext()->getController();
            $vf = new ViewFactory($controller);
            // Render the error view in the NEGOTIATED output type — the same one the
            // successful dispatch would use (ActionDescriptor->outputType, which
            // RoutingMiddleware derives from the route or the content-negotiated
            // type), NOT the controller's default. Otherwise an Accept:
            // application/json request that fails validation would run executeHtml
            // and return an HTML/blank body.
            $ot = $this->resolveErrorOutputType($request, $controller);
            $view = $vf->create($viewModule, $viewName, $moduleName, $actionName, $ot, $webRequest, [], $vs->getValidationManager());
            if (!$view) {
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                    \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] view creation returned null for ' . $viewModule . ':' . $viewName);
                }
                $resp = $factory->createResponse(400)->withHeader('X-Quiote-Validation', 'failed')->withHeader('X-Quiote-Validation-Reason', 'view_not_created');
                return $resp->withBody($factory->createStream($viewName));
            }
            $otMethod = 'execute' . ucfirst($ot);
            $hasOtMethod = is_callable([$view, $otMethod]);
            $problemJson = false;
            if ($ot === 'json') {
                // JSON validation-error response. Decision tree:
                //   - error view has NO executeJson()        -> Problem Details
                //   - executeJson() returns null             -> Problem Details
                //   - executeJson() returns a value          -> use it verbatim
                // An RFC 9457 Problem Details document is synthesized from the
                // validation report in the first two cases. A view that DOES render
                // a JSON body is left untouched — its shape is an API contract we
                // must not silently rewrite. An explicit empty string from
                // executeJson() is a deliberate "no body" choice and is respected
                // (blank 400), since only a NULL return triggers the fallback.
                $content = $hasOtMethod ? $view->$otMethod($webRequest) : null;
                if ($content === null) {
                    $content = $this->buildValidationProblemDetails($vs->getValidationManager(), $errors, $request);
                    $problemJson = true;
                }
            } elseif ($hasOtMethod) {
                $content = $view->$otMethod($webRequest);
            } else {
                // Legacy fallback for non-JSON output types.
                $content = $view->execute($webRequest);
            }
            // Mirror ActionExecutor::renderView()'s fallback: a view that calls
            // loadLayout()/appendLayer() and implicitly returns null expects the
            // caller to render its configured layers instead. Without this, any
            // HTML error view following that (framework-scaffolded) convention
            // produced a 400 with an empty body.
            if ($content === null && $ot !== 'json' && $view->getLayers()) {
                $layerContent = $view->renderLayers();
                if ($layerContent !== '') {
                    $content = $layerContent;
                }
            }
            // Repopulate the submitted values into the rendered HTML form (the
            // "sticky form" behavior). Scoped to 'html' only: an API client
            // (JSON/etc.) is expected to hold its own submitted state, and
            // FormPopulationEngine's DOM rewriting only makes sense for markup
            // output. Sourced from ValidationManager's raw pre-prune snapshot,
            // NOT $webRequest -- a value that failed even one of several
            // validators registered against the same field name is
            // deliberately scrubbed from the request (see
            // WebRequest::pruneParametersToValidated()), which is correct for
            // business logic but would otherwise make that field render blank
            // again on the redisplayed form instead of showing what the user
            // actually typed.
            if ($ot === 'html' && is_string($content) && $content !== '') {
                try {
                    $vm = $vs->getValidationManager();
                    $rawSnapshot = $vm instanceof ValidationManager ? $vm->getRawParameterSnapshot() : [];
                    if ($rawSnapshot) {
                        $globalResponse = $controller->getGlobalResponse();
                        $globalResponse->setContent($content);
                        // FormPopulationEngine gates on $response->getOutputType()
                        // (the 'output_types' config below), so the global response
                        // must carry the negotiated output type, not whatever it
                        // last had set (e.g. from a prior request in a long-running
                        // worker, or none at all).
                        $globalResponse->setOutputType($controller->getOutputType($ot));
                        $engine = new \Quiote\Util\FormPopulationEngine();
                        $engine->initialize($controller->getContext());
                        try {
                            $engine->populate($globalResponse, $webRequest, [
                                // A ParameterHolder here (not a plain array) is used
                                // verbatim as the global value source for every form
                                // field on the page -- FormPopulationEngine treats a
                                // plain array under 'populate' as a per-form-id map
                                // instead (see resolvePopulateSource()).
                                'populate' => new \Quiote\Util\ParameterHolder($rawSnapshot),
                                'validation_report' => $vm->getReport(),
                                'output_types' => ['html'],
                            ]);
                        } finally {
                            $engine->reset();
                        }
                        $populated = $globalResponse->getContent();
                        if (is_string($populated) && $populated !== '') {
                            $content = $populated;
                        }
                    }
                } catch (\Throwable) {
                    // Form repopulation is a UX nicety, not correctness-critical --
                    // never let it turn a validation-failure response into a 500.
                }
            }
            // Stash content for DispatchMiddleware early short-circuit (non-simple container-less path)
            try {
                $request = $request->withAttribute('validation.error.content', (string)$content);
            } catch (\Throwable) {
            }
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400)->withHeader('X-Quiote-Validation', 'failed');
            if ($problemJson) {
                // RFC 9457 media type for the synthesized Problem Details body.
                $resp = $resp->withHeader('Content-Type', 'application/problem+json; charset=UTF-8');
            } else {
                // Respect a Content-Type the view explicitly set — notably
                // application/problem+json when the view used
                // returnProblemDetailsFromValidationIncidents().
                $viewContentType = null;
                try {
                    $ct = $controller->getGlobalResponse()->getContentType();
                    if (is_string($ct) && $ct !== '') {
                        $viewContentType = $ct;
                    }
                } catch (\Throwable) {
                }
                if ($viewContentType !== null) {
                    $resp = $resp->withHeader('Content-Type', $viewContentType);
                } else {
                    // Prefer Content-Type from output_types.xml; fall back to MimeTypeRegistry autodetection.
                    $primaryMime = null;
                    try {
                        $primaryMime = $controller->getOutputType($ot)->getParameter('http_headers[Content-Type]') ?: null;
                    } catch (\Throwable) {
                    }
                    $primaryMime ??= \Quiote\Http\MimeTypeRegistry::primaryMimeType($ot);
                    if ($primaryMime !== null) {
                        $resp = $resp->withHeader('Content-Type', (string) $primaryMime);
                    }
                }
            }
            // The X-Quiote-Validation-Errors header leaks internal field/validator
            // structure to the client, so it is opt-in and OFF by default. Enable it
            // (e.g. for a trusted dev/test front-end) via:
            //   Config::set('core.expose_validation_errors_header', true)
            if (!empty($errors) && \Quiote\Config\Config::getBool('core.expose_validation_errors_header', false)) {
                $resp = $resp->withHeader('X-Quiote-Validation-Errors', base64_encode(json_encode($errors)));
            }
            if ($content !== null) {
                $resp = $resp->withBody($factory->createStream((string)$content));
            }
            return $resp;
        } catch (\Throwable $e) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[ValidationMiddleware] exception during view creation: ' . $e->getMessage());
            }
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400)->withHeader('X-Quiote-Validation', 'failed')->withHeader('X-Quiote-Validation-Reason', 'view_creation_exception');
            // The X-Quiote-Validation-Errors header leaks internal field/validator
            // structure to the client, so it is opt-in and OFF by default. Enable it
            // (e.g. for a trusted dev/test front-end) via:
            //   Config::set('core.expose_validation_errors_header', true)
            if (!empty($errors) && \Quiote\Config\Config::getBool('core.expose_validation_errors_header', false)) {
                $resp = $resp->withHeader('X-Quiote-Validation-Errors', base64_encode(json_encode($errors)));
            }
            return $resp->withBody($factory->createStream('Error'));
        }
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
     * @param array<int, mixed> $fallback Flat list of error messages.
     */
    private function buildValidationProblemDetails($vm, array $fallback, \Psr\Http\Message\ServerRequestInterface $request): string
    {
        $errors = \Quiote\Http\ProblemDetails::extractErrors($vm);
        if ($errors === [] && $fallback !== []) {
            // The report had no field-scoped incidents (e.g. a manual validate()
            // returning false); surface the flat messages as non-field errors.
            $messages = array_values(array_unique(array_filter(
                array_map(static fn($m) => (string) $m, $fallback),
                static fn(string $m) => $m !== ''
            )));
            if ($messages !== []) {
                $errors = ['' => $messages];
            }
        }

        $instance = null;
        try {
            $instance = $request->getUri()->getPath();
        } catch (\Throwable) {
        }

        return \Quiote\Http\ProblemDetails::create(status: 400, instance: $instance, errors: $errors)->toJson();
    }
}
