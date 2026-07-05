<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Execution\ForwardService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Execution\SecurityService;
use Quiote\Execution\SecurityDecision;
use Quiote\Action\Action;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;

/**
 * Security middleware: evaluates action security requirements and forwards
 * unauthenticated/unauthorized requests to login/secure system actions.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 30, after: 'RoutingMiddleware')]
class SecurityMiddleware implements MiddlewareInterface
{
    private ?ForwardService $forwardService = null;
    public function __construct(private readonly Controller $controller, private ?SecurityService $securityService = null)
    {
        $this->securityService ??= new SecurityService($controller);
        $this->forwardService ??= new ForwardService($controller);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dbg = \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug);
        $rid = $request->getAttribute('quiote.rid');
        if (!$rid) {
            try {
                $rid = bin2hex(random_bytes(4));
            } catch (\Throwable) {
                $rid = uniqid();
            }
            $request = $request->withAttribute('quiote.rid', $rid);
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
            if (method_exists($storage, 'getId')) {
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
            if (method_exists($userObj, 'isAuthenticated')) {
                $authState = $userObj->isAuthenticated() ? '1' : '0';
            }
        } catch (\Throwable) {
        }
        if ($dbg) {
            \Quiote\Logging\Log::for($this)->debug('[SecurityMiddleware][' . $rid . '] pre module=' . $actionDesc->module . ' action=' . $actionDesc->action . ' method=' . $actionDesc->method . ' sessId=' . $sessId . ' auth=' . $authState);
        }

        // Try to create and initialize the action instance
        $action = null;
        try {
            $action = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
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
                $request = $request->withAttribute('quiote.preinstantiated_action', $action);
            } catch (\Throwable) {
            }
        } catch (\Throwable $initEx) {
            \Quiote\Logging\Log::for($this)->error('[SecurityMiddleware][' . $rid . '] action init FAILED: ' . $initEx::class . ': ' . $initEx->getMessage() . ' @ ' . $initEx->getFile() . ':' . $initEx->getLine());
            $action = null;
        }

        // Determine security decision
        $useSecurity = \Quiote\Config\Config::getBool('core.use_security', true);
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
                $isAuth = method_exists($user, 'isAuthenticated') && $user->isAuthenticated();
            } catch (\Throwable) {
            }
            $decision = $isAuth ? SecurityDecision::Allow : SecurityDecision::LoginForward;
            if (!$isAuth) {
                \Quiote\Logging\Log::for($this)->error('[SecurityMiddleware][' . $rid . '] fail-closed: action creation failed, user not authenticated → LoginForward');
            }
        }

        if ($dbg) {
            \Quiote\Logging\Log::for($this)->debug('[SecurityMiddleware][' . $rid . '] decision=' . $decision->name . ' authAfter=' . ($userObj && method_exists($userObj, 'isAuthenticated') && $userObj->isAuthenticated() ? '1' : '0'));
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
            };
            if($dbg) {
                try {
                    $orig = $actionDesc;
                    $sidLog = $sessId;
                    \Quiote\Logging\Log::for($this)->debug('[SecurityMiddleware]['.$rid.'] non-allow decision='.$decision->name.' orig='.$orig->module.':'.$orig->action.':'.$orig->method.' sid='.$sidLog.' auth='.$authState);
                } catch(\Throwable) {}
            }
            // Produce a replacement ActionDescriptor for the system action based on HTTP verb.
            $httpMethod = $request->getMethod();
            // Safely resolve output type name — fall back to 'html' if not yet configured
            $outputTypeName = 'html';
            try {
                $ot = $this->controller->getOutputType();
                $outputTypeName = $ot->getName();
            } catch (\Throwable) {
                // Output types not yet loaded; use default
            }
            try {
                $newDesc = $this->forwardService->createSystemForwardActionDescriptor($key, $httpMethod, $outputTypeName);
                // Preserve original for debugging/redirect-after-login scenarios.
                $request = $request->withAttribute('quiote.original_action', $actionDesc);
                $request = $request->withAttribute(ActionDescriptor::class, $newDesc);
                // Clear the preinstantiated action — it was created for the original
                // action descriptor, not the forwarded one. DispatchMiddleware must
                // create a fresh instance for the login/secure action.
                $request = $request->withAttribute('quiote.preinstantiated_action', null);
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
                if ($dbg) {
                    \Quiote\Logging\Log::for($this)->debug('[SecurityMiddleware] forwarded decision=' . $decision->name . ' -> system action ' . $newDesc->module . ':' . $newDesc->action . ':' . $newDesc->method);
                }
            } catch (\Throwable $e) {
                // CRITICAL: If we cannot create a forward descriptor, we MUST NOT pass through
                // with the original action — that would bypass security entirely.
                \Quiote\Logging\Log::for($this)->error('[SecurityMiddleware][' . $rid . '] forward descriptor creation FAILED (returning 403): ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                return $factory->createResponse(403)->withBody($factory->createStream('Access Denied'));
            }
        }
        if ($dbg) {
            $finalDesc = $request->getAttribute(ActionDescriptor::class);
            if ($finalDesc) {
                \Quiote\Logging\Log::for($this)->debug('[SecurityMiddleware][' . $rid . '] post module=' . $finalDesc->module . ' action=' . $finalDesc->action . ' method=' . $finalDesc->method . ' forwarded=' . ($execState->forwarded ? '1' : '0'));
            }
        }
        return $handler->handle($request);
    }
}
