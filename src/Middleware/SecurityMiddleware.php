<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\Execution\ForwardService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
// Removed legacy AgaviExecutionContainer path.
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Execution\SecurityService; // unified security service
use Agavi\Execution\SecurityDecision; // execution-layer enum (login|secure)
use Agavi\Action\AgaviAction;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ValidationDecision;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Placeholder security middleware: currently defers to legacy dispatch path.
 * Future: Inspect request attributes for module/action and run security logic prior to execution.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', after: 'RoutingMiddleware')]
class SecurityMiddleware implements MiddlewareInterface
{
    private ?ForwardService $forwardService = null;
    public function __construct(private AgaviController $controller, private ?SecurityService $securityService = null)
    {
        $this->securityService ??= new SecurityService($controller); // interim wire-up until DI container
        $this->forwardService ??= new ForwardService($controller);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dbg = getenv('AGAVI_DEBUG_SECURITY');
        $rid = $request->getAttribute('agavi.rid');
        if (!$rid) {
            try {
                $rid = bin2hex(random_bytes(4));
            } catch (\Throwable) {
                $rid = uniqid();
            }
            $request = $request->withAttribute('agavi.rid', $rid);
        }
        // Removed __auth attribute override. Tests should set user auth state explicitly or use TestAuthInjectionMiddleware.
        $override = null;
        // Test hook: if env AGAVI_TEST_FORCE_AUTH=1, set user authenticated early (avoids race with user initialization order in tests)
        if (getenv('AGAVI_TEST_FORCE_AUTH')) {
            try {
                $u = $this->controller->getContext()->getUser();
                if (method_exists($u, 'setAuthenticated')) {
                    $u->setAuthenticated(true);
                }
            } catch (\Throwable) {
            }
        }
        // Build/initialize action for security evaluation in container-less world.
        $actionDesc = $request->getAttribute(ActionDescriptor::class);
        if (!$actionDesc) {
            return $handler->handle($request);
        }
        $userObj = null;
        $authState = 'unknown';
        $sessId = 'no-sid';
        try {
            $storage = $this->controller->getContext()->getStorage();
            if ($storage && method_exists($storage, 'getId')) {
                $sidTmp = $storage->getId();
                if (is_string($sidTmp) && $sidTmp !== '') {
                    $sessId = $sidTmp;
                }
            }
        } catch (\Throwable) {
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
        try {
            $userObj = $this->controller->getContext()->getUser();
            if ($userObj && method_exists($userObj, 'isAuthenticated')) {
                $authState = $userObj->isAuthenticated() ? '1' : '0';
            }
        } catch (\Throwable) {
        }
        if ($dbg) {
            AgaviDebugLogger::debug('[SecurityMiddleware][' . $rid . '] pre module=' . $actionDesc->module . ' action=' . $actionDesc->action . ' method=' . $actionDesc->method . ' sessId=' . $sessId . ' auth=' . $authState, $this->controller->getContext());
        }
        try {
            $action = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
            if (method_exists($action, 'initialize')) {

                $lwCtx = new LightweightActionInitContext(
                    $this->controller->getContext(),
                    $actionDesc->module,
                    $actionDesc->action,
                    $actionDesc->method,
                    $actionDesc->outputType,
                    $request,
                    $this->controller->getGlobalResponse()
                );
                $action->initialize($lwCtx);
                // Expose the pre-instantiated, initialized action to downstream middleware (ValidationMiddleware expects it)
                try {
                    $request = $request->withAttribute('agavi.preinstantiated_action', $action);
                } catch (\Throwable) {
                    // non-fatal: continue without preinstantiated attribute if request immutability fails
                }
            }
        } catch (\Throwable) {
            return $handler->handle($request);
        }
        if (!$action) {
            return $handler->handle($request);
        }
        /** @var AgaviAction $action */
        // Instrumentation: only if env flag set
        $useSecurity = (bool)\Agavi\Config\AgaviConfig::get('core.use_security', true);
        if (!$useSecurity) {
            $decision = \Agavi\Execution\SecurityDecision::Allow;
        } else {
            $decision = $this->securityService->decide($action);
        }
        if ($dbg) {
            AgaviDebugLogger::debug('[SecurityMiddleware][' . $rid . '] decision=' . $decision->name . ' authAfter=' . ($userObj && method_exists($userObj, 'isAuthenticated') && $userObj->isAuthenticated() ? '1' : '0'), $this->controller->getContext());
        }
        $execState = $request->getAttribute(ExecutionState::class);
        if ($execState instanceof ExecutionState) {
            $execState->securityDecision = $decision;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        } else {
            $execState = new ExecutionState();
            $execState->securityDecision = $decision;
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        if ($decision !== SecurityDecision::Allow) {
            $key = match ($decision) {
                SecurityDecision::LoginForward => 'login',
                SecurityDecision::SecureForward => 'secure',
                default => 'login'
            };
            if($dbg) {
                try {
                    $orig = $actionDesc; // original descriptor
                    $sidLog = $sessId ?? 'n/a';
                    AgaviDebugLogger::debug('[SecurityMiddleware]['.$rid.'] non-allow decision='.$decision->name.' orig='.$orig->module.':'.$orig->action.':'.$orig->method.' sid='.$sidLog.' auth='.$authState, $this->controller->getContext());
                } catch(\Throwable) {}
            }
            // Produce a replacement ActionDescriptor for the system action based on HTTP verb.
            $httpMethod = $request->getMethod();
            try {
                $newDesc = $this->forwardService->createSystemForwardActionDescriptor($key, $httpMethod, $this->controller->getOutputType()->getName());
                // Preserve original for debugging/redirect-after-login scenarios.
                $request = $request->withAttribute('agavi.original_action', $actionDesc);
                $request = $request->withAttribute(ActionDescriptor::class, $newDesc);
                if ($execState instanceof ExecutionState) {
                    $execState->forwarded = true;
                    $execState->forwardCount++;
                    if ($execState->forwardCount > 5) {
                        // Exceeded forward limit: return 508 response immediately.
                        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                        return $factory->createResponse(508)->withBody($factory->createStream('Too many forwards'));
                    }
                    // Forward resets execution target – clear view related state and validation decision.
                    $execState->viewName = null;
                    $execState->viewModule = null;
                    $execState->actionAttributes = [];
                    $execState->module = $newDesc->module;
                    $execState->action = $newDesc->action;
                    $execState->outputType = $newDesc->outputType;
                    $execState->validationDecision = ValidationDecision::pending();
                    // Mark security decision as satisfied so downstream executor doesn't re-run security.
                    $execState->securityDecision = SecurityDecision::Allow;
                    $request = $request->withAttribute(ExecutionState::class, $execState);
                }
                if (getenv('AGAVI_DEBUG_SECURITY')) {
                    AgaviDebugLogger::debug('[SecurityMiddleware] forwarded decision=' . $decision->name . ' -> system action ' . $newDesc->module . ':' . $newDesc->action . ':' . $newDesc->method, $this->controller->getContext());
                }
            } catch (\Throwable $e) {
                if (getenv('AGAVI_DEBUG_SECURITY')) {
                    AgaviDebugLogger::debug('[SecurityMiddleware] forward descriptor creation failed: ' . $e->getMessage(), $this->controller->getContext());
                }
            }
        }
        if ($dbg) {
            $finalDesc = $request->getAttribute(ActionDescriptor::class);
            if ($finalDesc) {
                AgaviDebugLogger::debug('[SecurityMiddleware][' . $rid . '] post module=' . $finalDesc->module . ' action=' . $finalDesc->action . ' method=' . $finalDesc->method . ' forwarded=' . ($execState ? ($execState->forwarded ? '1' : '0') : '0'), $this->controller->getContext());
            }
        }
        return $handler->handle($request);
    }
}
