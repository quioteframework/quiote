<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
use Quiote\Execution\ExecutionState;

/**
 * FinalizeMiddleware (scaffold): end-of-request persistence for session/user.
 * Future: write slim session (user_id, auth flag, versions) & flush metrics.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'after_action', after: 'DispatchMiddleware')]
class FinalizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        // Placeholder: future session persistence / cleanup hooks.
        return $response;
    }
}
