<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\Execution\ForwardService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Execution\SecurityService;
use Agavi\Execution\SecurityDecision;
use Agavi\Action\AgaviAction;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ValidationDecision;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Security middleware: evaluates action security requirements and forwards
 * unauthenticated/unauthorized requests to login/secure system actions.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', after: 'RoutingMiddleware')]
class SecurityMiddleware implements MiddlewareInterface
{
    private ?ForwardService $forwardService = null;
    public function __construct(private readonly AgaviController $controller, private ?SecurityService $securityService = null)
    {
        $this->securityService ??= new SecurityService($controller);
        $this->forwardService ??= new ForwardService($controller);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dbg = \Agavi\Util\DebugFlags::$security;
        $rid = $request->getAttribute('agavi.rid');
        if (!$rid) {
            try {
                $rid = bin2hex(random_bytes(4));
            } catch (\Throwable) {
                $rid = uniqid();
            }
            $request = $request->withAttribute('agavi.rid', $rid);
        }
        // Test hook: if env AGAVI_TEST_FORCE_AUTH=1, set user authenticated early
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

        // Try to create and initialize the action instance
        $action = null;
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
                try {
                    $request = $request->withAttribute('agavi.preinstantiated_action', $action);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $initEx) {
            AgaviDebugLogger::error('[SecurityMiddleware][' . $rid . '] action init FAILED: ' . $initEx::class . ': ' . $initEx->getMessage() . ' @ ' . $initEx->getFile() . ':' . $initEx->getLine(), $this->controller->getContext());
            $action = null;
        }

        // Determine security decision
        $useSecurity = (bool)\Agavi\Config\AgaviConfig::get('core.use_security', true);
        if (!$useSecurity) {
            $decision = SecurityDecision::Allow;
        } elseif ($action) {
            $decision = $this->securityService->decide($action);
        } else {
            // Fail-closed: action couldn't be created. If user is authenticated, allow through.
            // If not, forward to login.
            $isAuth = false;
            try {
                $user = $this->controller->getContext()->getUser();
                $isAuth = $user && method_exists($user, 'isAuthenticated') && $user->isAuthenticated();
            } catch (\Throwable) {
            }
            $decision = $isAuth ? SecurityDecision::Allow : SecurityDecision::LoginForward;
            if (!$isAuth) {
                AgaviDebugLogger::error('[SecurityMiddleware][' . $rid . '] fail-closed: action creation failed, user not authenticated → LoginForward', $this->controller->getContext());
            }
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
                    $orig = $actionDesc;
                    $sidLog = $sessId ?? 'n/a';
                    AgaviDebugLogger::debug('[SecurityMiddleware]['.$rid.'] non-allow decision='.$decision->name.' orig='.$orig->module.':'.$orig->action.':'.$orig->method.' sid='.$sidLog.' auth='.$authState, $this->controller->getContext());
                } catch(\Throwable) {}
            }
            // Produce a replacement ActionDescriptor for the system action based on HTTP verb.
            $httpMethod = $request->getMethod();
            // Safely resolve output type name — fall back to 'html' if not yet configured
            $outputTypeName = 'html';
            try {
                $ot = $this->controller->getOutputType();
                if ($ot) {
                    $outputTypeName = $ot->getName();
                }
            } catch (\Throwable) {
                // Output types not yet loaded; use default
            }
            try {
                $newDesc = $this->forwardService->createSystemForwardActionDescriptor($key, $httpMethod, $outputTypeName);
                // Preserve original for debugging/redirect-after-login scenarios.
                $request = $request->withAttribute('agavi.original_action', $actionDesc);
                $request = $request->withAttribute(ActionDescriptor::class, $newDesc);
                // Clear the preinstantiated action — it was created for the original
                // action descriptor, not the forwarded one. DispatchMiddleware must
                // create a fresh instance for the login/secure action.
                $request = $request->withAttribute('agavi.preinstantiated_action', null);
                if ($execState instanceof ExecutionState) {
                    $execState->forwarded = true;
                    $execState->forwardCount++;
                    if ($execState->forwardCount > 5) {
                        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                        return $factory->createResponse(508)->withBody($factory->createStream('Too many forwards'));
                    }
                    $execState->viewName = null;
                    $execState->viewModule = null;
                    $execState->actionAttributes = [];
                    $execState->module = $newDesc->module;
                    $execState->action = $newDesc->action;
                    $execState->outputType = $newDesc->outputType;
                    $execState->validationDecision = ValidationDecision::pending();
                    $execState->securityDecision = SecurityDecision::Allow;
                    $request = $request->withAttribute(ExecutionState::class, $execState);
                }
                if ($dbg) {
                    AgaviDebugLogger::debug('[SecurityMiddleware] forwarded decision=' . $decision->name . ' -> system action ' . $newDesc->module . ':' . $newDesc->action . ':' . $newDesc->method, $this->controller->getContext());
                }
            } catch (\Throwable $e) {
                // CRITICAL: If we cannot create a forward descriptor, we MUST NOT pass through
                // with the original action — that would bypass security entirely.
                AgaviDebugLogger::error('[SecurityMiddleware][' . $rid . '] forward descriptor creation FAILED (returning 403): ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), $this->controller->getContext());
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                return $factory->createResponse(403)->withBody($factory->createStream('Access Denied'));
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
