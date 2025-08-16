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
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Execution\SecurityService; // unified security service
use Agavi\Execution\SecurityDecision; // execution-layer enum (login|secure)
use Agavi\Action\AgaviAction;
use Agavi\Execution\ExecutionState;

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
        try {
            $action = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
            if (method_exists($action, 'initialize')) {
                $rd = new AgaviRequestDataHolder();
                $query = $request->getQueryParams();
                foreach ($query as $k => $v) {
                    $rd->setParameter($k, $v);
                }
                $body = $request->getParsedBody();
                if (is_array($body)) {
                    foreach ($body as $k => $v) {
                        $rd->setParameter($k, $v);
                    }
                }
                $lwCtx = new LightweightActionInitContext(
                    $this->controller->getContext(),
                    $actionDesc->module,
                    $actionDesc->action,
                    $actionDesc->method,
                    $actionDesc->outputType,
                    $rd,
                    $this->controller->getGlobalResponse()
                );
                $action->initialize($lwCtx);
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
            $rd = new AgaviRequestDataHolder();
            $fwdService = $this->forwardService;
            if ($fwdService) {
                try {
                    [$view, $vm, $vn, $content] = $fwdService->createSystemForwardView($key, $this->controller->getOutputType()->getName(), $rd);
                    $request = $request->withAttribute('agavi.forward_view', [$view, $vm, $vn, $content]);
                    // Mark forwarded on execution state
                    if($execState instanceof ExecutionState) { $execState->forwarded = true; $request = $request->withAttribute(ExecutionState::class, $execState); }
                } catch (\Throwable) {
                }
            }
        }
        return $handler->handle($request);
    }
}
