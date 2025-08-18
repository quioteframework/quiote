<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Http\PsrResponseAdapter;
use Agavi\View\AgaviView;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\ViewFactory;

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
        $actionDesc = $request->getAttribute(\Agavi\Execution\ActionDescriptor::class);
        if(!$actionDesc) { return $handler->handle($request); }
        $vd = getenv('AGAVI_DEBUG_VALIDATION');
        $moduleName = $actionDesc->module; $actionName = $actionDesc->action; $method = $actionDesc->method;
        // Create the action instance (descriptor holds metadata only).
        $action = $request->getAttribute('agavi.preinstantiated_action');
        if (!$action) {
            try {
                $controller = $this->controller ?? $GLOBALS['agavi_controller'] ?? null;
            } catch (\Throwable) {
                $controller = null;
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
                if ($action && method_exists($action, 'initialize')) {
                    $rdInit = new \Agavi\Request\AgaviRequestDataHolder();
                    $initCtx = new \Agavi\Execution\LightweightActionInitContext($controller->getContext(), $moduleName, $actionName, $actionDesc->method, $actionDesc->outputType, $rdInit, $controller->getGlobalResponse());
                    $action->initialize($initCtx);
                }
            }
        }
        // If the container lacks a request data holder (goal: no legacy AgaviWebRequest), synthesize one from PSR-7 request.
        $requestData = $request->getAttribute('agavi.request_data') ?? new AgaviRequestDataHolder();
        $query = $request->getQueryParams();
        foreach ($query as $k => $v) {
            $requestData->setParameter($k, $v);
        }
        $body = $request->getParsedBody();
        if (is_array($body)) {
            foreach ($body as $k => $v) {
                $requestData->setParameter($k, $v);
            }
        }
        $routeParams = $request->getAttribute('route_params');
        if (is_array($routeParams)) {
            foreach ($routeParams as $k => $v) {
                $requestData->setParameter($k, $v);
            }
        }
        if (!$requestData) {
            $requestData = new AgaviRequestDataHolder();
        }

        // Skip if already validated
    if ($execState instanceof ExecutionState && $execState->validationPerformed) {
            return $handler->handle($request);
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
                // Attempt XML-only validation first
                $xmlRes = $vs->xmlOnlyValidate($action, $requestData, $moduleName, $actionName, $method);
                $trace = $xmlRes->getTrace();
                $hasXml = $trace && property_exists($trace, 'configFile') && $trace->configFile !== null && $trace->configFile !== '';
                $ok = $xmlRes->ok;
                if (!$ok) {
                    $errors = $xmlRes->getErrors() ?: ['validation_failed'];
                }
                // xml validation phase complete
                if ($ok) {
                    // Manual action validation phase
                    $methodCap = ucfirst(strtolower($actionDesc->method));
                    $validateMethod = 'validate' . $methodCap;
                    if (is_callable([$action, $validateMethod])) {
                        $ok = (bool)$action->$validateMethod($requestData);
                    }
                    if ($ok && is_callable([$action, 'validate'])) {
                        $ok = (bool)$action->validate($requestData);
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
            // Clear requestData parameters and lock down
            try {
                if (method_exists($requestData, 'clearParameters')) {
                    $requestData->clearParameters();
                }
            } catch (\Throwable) {
            }
        // no xml => params cleared
        }
        if ($execState instanceof ExecutionState) {
            $execState->validationPerformed = true;
            $execState->validationSucceeded = $ok;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        if ($ok) {
            // Enforce validated-only access when XML existed; otherwise empty set already enforced.
            if ($hasXml) {
                try {
                    $validatedParams = $requestData->getParameters();
                    if (is_array($validatedParams) && method_exists($requestData, 'enforceValidatedParameters')) {
                        $requestData->enforceValidatedParameters(array_keys($validatedParams), true);
                    }
                    $request = $request->withAttribute('agavi.validated_params', $validatedParams);
            // success with xml
                } catch (\Throwable) {
                }
            }
            return $handler->handle($request);
        }
    // failure path
        // Validation failed => 400 with errors and stash for form population
        $request = $request->withAttribute('agavi.validation.errors', $errors);
        $method = $method ?: 'Default';
        $handleErrorMethod = 'handle' . $method . 'Error';
        if (!is_callable([$action, $handleErrorMethod])) {
            $handleErrorMethod = 'handleError';
        }
        $viewName = $action ? $action->$handleErrorMethod($requestData) : 'Error';
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
            $view = $vf->create($viewModule, $viewName, $moduleName, $actionName, $ot, $requestData, []);
            if (!$view) {
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                $resp = $factory->createResponse(400);
                return $resp->withBody($factory->createStream(is_string($viewName) ? $viewName : 'Error'));
            }
            $methodName = 'execute' . $controller->getOutputType()->getName();
            if (!is_callable([$view, $methodName])) {
                $methodName = 'execute';
            }
            $content = $view->$methodName($requestData);
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
        } catch (\Throwable) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400)->withHeader('X-Agavi-Validation', 'failed');
            if (!empty($errors)) {
                $resp = $resp->withHeader('X-Agavi-Validation-Errors', base64_encode(json_encode($errors)));
            }
            return $resp->withBody($factory->createStream('Error'));
        }
    }
}
