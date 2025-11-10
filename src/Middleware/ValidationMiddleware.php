<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Http\PsrResponseAdapter;
use Agavi\View\AgaviView;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ValidationDecision;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\ViewFactory;
use Agavi\Execution\HttpMethodMapper;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Executes validation early (before action execution) and enforces strict access to validated params only.
 * If validation fails, converts flow to handleError view resolution path similar to legacy performValidation().
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', after: 'SecurityMiddleware', before: 'DispatchMiddleware')]
class ValidationMiddleware implements MiddlewareInterface
{
    public function __construct(private ?\Agavi\Controller\AgaviController $controller = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $execState = $request->getAttribute(ExecutionState::class);
        // Always ensure we have an ExecutionState so downstream code can rely on it
        if (!$execState instanceof ExecutionState) {
            $execState = new ExecutionState();
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        $actionDesc = $request->getAttribute(\Agavi\Execution\ActionDescriptor::class);
        if (!$actionDesc) {
            return $handler->handle($request);
        }
        $vd = getenv('AGAVI_DEBUG_VALIDATION');
        $moduleName = $actionDesc->module;
        $actionName = $actionDesc->action;
        $method = $actionDesc->method;
        // Map HTTP verbs or custom indicators to legacy semantic method names (Read|Write).
        // IMPORTANT: The compiled validator config files compare against lowercase tokens 'read' / 'write'.
        // We keep a normalized (capitalized) variant for naming validate* / handle*Error methods, but
        // pass the lowercase token to xmlOnlyValidate so <if($method == 'read')> blocks fire.
        // Derive canonical action method via central mapper then build normalized token for legacy method names
        $providedMethod = is_string($method) ? strtolower($method) : '';
        if ($providedMethod !== '' && in_array($providedMethod, ['read', 'write', 'create', 'remove'], true)) {
            // Action descriptors already use legacy semantic tokens – use as-is.
            $mapped = $providedMethod;
        } else {
            $mapped = HttpMethodMapper::toActionMethod($method ?: 'GET'); // normalize HTTP verbs to legacy tokens
        }
        $normalizedMethod = ucfirst($mapped);
        $lowerMethodToken = $mapped; // used for XML config inclusion conditions
        // Create the action instance (descriptor holds metadata only).
        $action = $request->getAttribute('agavi.preinstantiated_action');
        /* 
        We should always have a preinstantiated action
        *
        */
        if ($vd) {
            AgaviDebugLogger::debug('[ValidationMiddleware] preinstantiated_action=' . gettype($action), $this->controller?->getContext());
        }
        if (!$action) {
            if ($vd) {
                AgaviDebugLogger::debug('[ValidationMiddleware]: pre-instantiated action not found', $this->controller?->getContext());
            }
            $controller = $this->controller;
            if (!$controller) {
                try {
                    $controller = \Agavi\Agavi::context('web', true)?->getController();
                } catch (\Throwable) {
                }
            }
            if (!$controller && method_exists(\Agavi\Agavi::class, 'context')) {
                try {
                    $controller = \Agavi\Agavi::context('web', true)->getController();
                } catch (\Throwable) {
                }
            }
            if ($controller && $actionDesc) {
                // Let exceptions bubble to ErrorHandlingMiddleware – failure is a hard error.
                $action = $controller->createActionInstance($moduleName, $actionName);
                if ($action) {
                    // Use the PSR request for type compatibility; action methods still receive AgaviWebRequest param from dispatcher later.
                    $initCtx = new \Agavi\Execution\LightweightActionInitContext(
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
        }

        // Reuse the context's AgaviWebRequest so that validator exports (which write into runtime parameters)
        // are later visible to the action and views. Creating a fresh instance would isolate exports.
        // Always obtain (and thus materialize if needed) the context request so we mutate the
        // exact instance actions/views will later read. Creating an ad-hoc AgaviWebRequest would
        // isolate validator exports from downstream code because AgaviContext::getRequest() would
        // lazily create a different instance afterwards.
        $webRequest = null;
        // Ensure we resolve a controller reference early so context reuse works even if constructor passed null
        if ($this->controller === null && method_exists(\Agavi\Agavi::class, 'context')) {
            try {
                $this->controller = \Agavi\Agavi::context('web', true)?->getController();
            } catch (\Throwable) {
            }
        }
        try {
            $webRequest = $this->controller?->getContext()?->getRequest();
        } catch (\Throwable) {
        }
        if (!($webRequest instanceof AgaviWebRequest)) {
            throw new \RuntimeException('Canonical AgaviWebRequest missing in ValidationMiddleware (must be initialized earlier).');
        }
        $webRequest->attachPsrRequest($request);
        if (getenv('AGAVI_DEBUG_ROUTING')) {
            AgaviDebugLogger::debug('[ValidationMiddleware][debug] using context AgaviWebRequest (shared)', $this->controller?->getContext());
        }
        // Promote route params (excluding internal underscore-prefixed keys) into runtime parameters
        // BEFORE validation so validators treat them like any other input (GET/POST/etc.).
        try {
            $routeParams = $request->getAttribute('route_params');
            if (getenv('AGAVI_DEBUG_ROUTING')) {
                try {
                    AgaviDebugLogger::debug('[ValidationMiddleware][debug] route_params=' . json_encode($routeParams, JSON_UNESCAPED_SLASHES), $this->controller?->getContext());
                } catch (\Throwable) {
                }
            }
            if (is_array($routeParams) && $routeParams) {
                $injected = [];
                foreach ($routeParams as $k => $v) {
                    if ($k !== '' && $k[0] !== '_' && !is_array($v)) {
                        $current = $webRequest->getParameter($k);
                        if ($current === null) {
                            $webRequest->setParameter($k, $v);
                            $injected[$k] = $v;
                        }
                    }
                }
                if ($injected) {
                    // Also merge into raw query params so validators reading query directly see them.
                    if (getenv('AGAVI_DEBUG_ROUTING')) {
                        try {
                            AgaviDebugLogger::debug('[ValidationMiddleware][debug] injected_route_params_runtime=' . json_encode($injected, JSON_UNESCAPED_SLASHES), $this->controller?->getContext());
                        } catch (\Throwable) {
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // ignore promotion errors – validation will proceed without route params if something unexpected happens
        }

        if ($vd) {
            AgaviDebugLogger::debug('[ValidationMiddleare] Already validated?', $this->controller?->getContext());
        }
        // Skip if already validated
        // Re-run only if not yet decided; SecurityMiddleware may reset validationPerformed on forward.
        if ($execState->validationDecision && !$execState->validationDecision->isPending()) {
            if ($vd) {
                AgaviDebugLogger::debug('[ValidationMiddleware] YES', $this->controller?->getContext());
            }
            return $handler->handle($request);
        }

        if ($vd) {
            AgaviDebugLogger::debug('[ValidationMiddlware] NO', $this->controller?->getContext());
        }

        $ok = true;
        $hasXml = false;
        $errors = [];
        $vs = new ValidationService();
        try {
            if ($action && method_exists($action, 'isSimple') && $action->isSimple()) {
                $ok = true; // simple actions bypass validation
                // simple action bypass
            } else {
                // Attempt XML-only validation first (must use lowercase token so compiled config matches)
                $xmlRes = $vs->xmlOnlyValidate($action, $webRequest, $moduleName, $actionName, $lowerMethodToken);
                if ($vd && method_exists($xmlRes, 'getTrace')) {
                    try {
                        $t = $xmlRes->getTrace();
                        if ($t) {
                            AgaviDebugLogger::debug('[ValidationMiddleware] trace configFile=' . ($t->configFile ?? 'null') . ' validators=' . implode(',', $t->validatorsLoaded ?? []), $this->controller?->getContext());
                        }
                    } catch (\Throwable) {
                    }
                }
                $trace = $xmlRes->getTrace();
                $hasXml = $trace && property_exists($trace, 'configFile') && $trace->configFile !== null && $trace->configFile !== '';
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
                    if (!$ok && empty($errors)) {
                        $errors[] = 'manual_validation_failed';
                    }
                    // manual validation phase complete
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            if (!$errors) {
                $errors[] = $e->getMessage();
            }
        }
        // If no XML present treat as success but expose ZERO parameters to action (strict empty set)
        if (!$hasXml && !$action?->isSimple()) {
            // Clear webRequest parameters and lock down
            try {
                if (method_exists($webRequest, 'clearParameters')) {
                    $webRequest->clearParameters();
                }
            } catch (\Throwable) {
            }
            // no xml => params cleared
        }
        $execState->validationDecision = $ok ? ValidationDecision::passed() : ValidationDecision::failed($errors);
        $request = $request->withAttribute(ExecutionState::class, $execState);
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
            AgaviDebugLogger::debug('[ValidationMiddleware] decision=' . $execState->validationDecision->state . ' module=' . $moduleName . ' action=' . $actionName . ' method=' . $method . ' simple=' . (($action && method_exists($action, 'isSimple') && $action->isSimple()) ? '1' : '0') . ' sessId=' . $sessId . ' auth=' . $auth . $errStr, $this->controller?->getContext());
        }
        if ($ok) {
            if (getenv('AGAVI_DEBUG_ROUTING')) {
                try {
                    AgaviDebugLogger::debug('[ValidationMiddleware][debug] post-validation SUCCESS', $this->controller?->getContext());
                } catch (\Throwable) {
                }
            }
            return $handler->handle($request);
        }
        if (getenv('AGAVI_DEBUG_ROUTING')) {
            try {
                AgaviDebugLogger::debug('[ValidationMiddleware][debug] post-validation FAILURE', $this->controller?->getContext());
            } catch (\Throwable) {
            }
        }
        // failure path
        // Validation failed => 400 with errors and stash for form population
        $request = $request->withAttribute('agavi.validation.errors', $errors);
        $method = $normalizedMethod ?: 'Default';
        $handleErrorMethod = 'handle' . $method . 'Error';
        if (!is_callable([$action, $handleErrorMethod])) {
            $handleErrorMethod = 'handleError';
        }
        $viewName = $action ? $action->$handleErrorMethod($webRequest) : 'Error';
        if (is_array($viewName)) {
            $viewModule = $viewName[0];
            $viewName = $viewName[1];
        } elseif ($viewName !== AgaviView::NONE) {
            $viewModule = $moduleName;
        } else {
            $viewModule = AgaviView::NONE;
        }
        if ($execState instanceof ExecutionState) {
            $execState->viewModule = $viewModule;
            $execState->viewName = $viewName;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        // Execute view immediately so downstream dispatch middleware can skip action logic
        if ($viewName === AgaviView::NONE) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            return $factory->createResponse(400);
        }
        // Create view via controller and ImmutableViewInitContext
        try {
            $controller = $action->getContext()->getController();
            $vf = new ViewFactory($controller);
            $ot = strtolower($controller->getOutputType()->getName());
            $view = $vf->create($viewModule, $viewName, $moduleName, $actionName, $ot, $webRequest, []);
            if (!$view) {
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                if (getenv('AGAVI_DEBUG_VALIDATION')) {
                    AgaviDebugLogger::debug('[ValidationMiddleware] view creation returned null for ' . $viewModule . ':' . $viewName, $this->controller?->getContext());
                }
                $resp = $factory->createResponse(400)->withHeader('X-Agavi-Validation', 'failed')->withHeader('X-Agavi-Validation-Reason', 'view_not_created');
                return $resp->withBody($factory->createStream(is_string($viewName) ? $viewName : 'Error'));
            }
            $methodName = 'execute' . $controller->getOutputType()->getName();
            if (!is_callable([$view, $methodName])) {
                $methodName = 'execute';
            }
            $content = $view->$methodName($webRequest);
            // Stash content for DispatchMiddleware early short-circuit (non-simple container-less path)
            try {
                $request = $request->withAttribute('validation.error.content', (string)$content);
            } catch (\Throwable) {
            }
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400)->withHeader('X-Agavi-Validation', 'failed');
            if (!empty($errors)) {
                $resp = $resp->withHeader('X-Agavi-Validation-Errors', base64_encode(json_encode($errors)));
            }
            if ($content !== null) {
                $resp = $resp->withBody($factory->createStream((string)$content));
            }
            return $resp;
        } catch (\Throwable $e) {
            if (getenv('AGAVI_DEBUG_VALIDATION')) {
                AgaviDebugLogger::debug('[ValidationMiddleware] exception during view creation: ' . $e->getMessage(), $this->controller?->getContext());
            }
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400)->withHeader('X-Agavi-Validation', 'failed')->withHeader('X-Agavi-Validation-Reason', 'view_creation_exception');
            if (!empty($errors)) {
                $resp = $resp->withHeader('X-Agavi-Validation-Errors', base64_encode(json_encode($errors)));
            }
            return $resp->withBody($factory->createStream('Error'));
        }
    }
}
