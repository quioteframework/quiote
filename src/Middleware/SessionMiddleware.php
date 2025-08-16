<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ExecutionState;

/**
 * SessionMiddleware (scaffold): ensures user/session hydrated prior to security & dispatch.
 * Future: start session if needed, attach user principal metadata, lazy credential loading.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', after: 'RoutingMiddleware', before: 'SecurityMiddleware')]
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Placeholder: ensure ExecutionState exists to be mutated downstream.
        if(!$request->getAttribute(ExecutionState::class)) {
            $request = $request->withAttribute(ExecutionState::class, new ExecutionState());
        }
        return $handler->handle($request);
    }
}
