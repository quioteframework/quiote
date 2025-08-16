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
        $moduleName = $actionDesc->module; $actionName = $actionDesc->action; $method = $actionDesc->method;
        // Create the action instance (descriptor holds metadata only).
        $action = $request->getAttribute('agavi.preinstantiated_action');
        if(!$action) {
            try { $controller = $this->controller ?? $GLOBALS['agavi_controller'] ?? null; } catch(\Throwable) { $controller = null; }
            if(!$controller && method_exists(\Agavi\Agavi::class,'context')) { try { $controller = \Agavi\Agavi::context('web', true)->getController(); } catch(\Throwable) {} }
            if($controller && $actionDesc) {
                try { $action = $controller->createActionInstance($moduleName, $actionName); } catch(\Throwable) { $action = null; }
            }
        }
        // If the container lacks a request data holder (goal: no legacy AgaviWebRequest), synthesize one from PSR-7 request.
    $requestData = $request->getAttribute('agavi.request_data') ?? new AgaviRequestDataHolder();
    $query = $request->getQueryParams(); foreach($query as $k=>$v) { $requestData->setParameter($k,$v); }
    $body = $request->getParsedBody(); if(is_array($body)) { foreach($body as $k=>$v){ $requestData->setParameter($k,$v);} }
    $routeParams = $request->getAttribute('route_params'); if(is_array($routeParams)) { foreach($routeParams as $k=>$v){ $requestData->setParameter($k,$v);} }
        if(!$requestData) { $requestData = new AgaviRequestDataHolder(); }

        // Skip if already validated
    // Skip if already validated via state
    if($execState instanceof ExecutionState && $execState->validationPerformed) { return $handler->handle($request); }

        $ok = true; $usedAdapter = false;
        $vs = new ValidationService();
        try {
            if($action && method_exists($action,'isSimple') && $action->isSimple()) {
                $ok = true;
            } else {
                // Phase 1: XML validators only
                $xmlRes = $vs->xmlOnlyValidate($action, $requestData, $moduleName, $actionName, $method);
                $ok = $xmlRes->ok;
                // Phase 2: action manual validate* methods if XML passed
                if($ok) {
                    $methodCap = ucfirst(strtolower($actionDesc->method));
                    $validateMethod = 'validate' . $methodCap;
                    $genericCalled = false; $methodCalled = false;
                    if(is_callable([$action, $validateMethod])) { $methodCalled = true; $ok = (bool)$action->$validateMethod($requestData); }
                    if($ok && is_callable([$action,'validate']) && !$methodCalled) { $genericCalled = true; $ok = (bool)$action->validate($requestData); }
                }
            }
        } catch(\Throwable) { $ok = false; }
        if($execState instanceof ExecutionState) {
            $execState->validationPerformed = true; $execState->validationSucceeded = $ok;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
    if($ok) {
            // Expose validated parameters and enforce strict access on RD holder.
            try {
                $validatedParams = $requestData->getParameters();
                if(is_array($validatedParams)) {
                    // Enable enforcement so later getParameter() calls reject unknowns.
                    if(method_exists($requestData,'enforceValidatedParameters')) {
                        $requestData->enforceValidatedParameters(array_keys($validatedParams), true);
                    }
                    $request = $request->withAttribute('agavi.validated_params', $validatedParams);
                }
            } catch(\Throwable) { /* non-fatal */ }
            // Fast path: adapter + simple action => execute immediately and short-circuit dispatch
            if($action && method_exists($action,'isSimple') && $action->isSimple()) {
                try {
                    $result = $action->execute($requestData);
                    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                    $resp = $factory->createResponse(200);
                    if(is_string($result)) { $resp = $resp->withBody($factory->createStream($result)); }
                    return $resp;
                } catch(\Throwable) { /* fall through to normal handler */ }
            }
            return $handler->handle($request);
        }
    // Validation failed => resolve error view.
    $method = $method ?: 'Default';
        $handleErrorMethod = 'handle' . $method . 'Error';
        if(!is_callable([$action, $handleErrorMethod])) { $handleErrorMethod = 'handleError'; }
    $viewName = $action ? $action->$handleErrorMethod($requestData) : 'Error';
        if(is_array($viewName)) { $viewModule = $viewName[0]; $viewName = $viewName[1]; }
        elseif($viewName !== AgaviView::NONE) { $viewModule = $moduleName; } else { $viewModule = AgaviView::NONE; }
        if($execState instanceof ExecutionState) { $execState->viewModule = $viewModule; $execState->viewName = $viewName; $request = $request->withAttribute(ExecutionState::class, $execState); }
        // Execute view immediately so downstream dispatch middleware can skip action logic
        if($viewName === AgaviView::NONE) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            return $factory->createResponse(400);
        }
    // Create view via controller and ImmutableViewInitContext
        try {
            $controller = $action->getContext()->getController();
            $vf = new ViewFactory($controller);
            $ot = strtolower($controller->getOutputType()->getName());
            $view = $vf->create($viewModule, $viewName, $moduleName, $actionName, $ot, $requestData, []);
            if(!$view) {
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                $resp = $factory->createResponse(400);
                return $resp->withBody($factory->createStream(is_string($viewName)?$viewName:'Error'));
            }
            $methodName = 'execute' . $controller->getOutputType()->getName();
            if(!is_callable([$view, $methodName])) { $methodName = 'execute'; }
            $content = $view->$methodName($requestData);
            // Stash content for DispatchMiddleware early short-circuit (non-simple container-less path)
            try { $request = $request->withAttribute('validation.error.content', (string)$content); } catch(\Throwable) {}
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400);
            if($content !== null) { $resp = $resp->withBody($factory->createStream((string)$content)); }
            return $resp;
        } catch(\Throwable) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $resp = $factory->createResponse(400);
            return $resp->withBody($factory->createStream('Error'));
        }
    }
}
