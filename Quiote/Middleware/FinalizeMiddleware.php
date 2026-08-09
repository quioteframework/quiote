<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;

/**
 * FinalizeMiddleware (scaffold): end-of-request persistence for session/user.
 * Future: write slim session (user_id, auth flag, versions) & flush metrics.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'after_action', after: 'DispatchMiddleware')]
class FinalizeMiddleware implements MiddlewareInterface
{
    /**
     * Passes the request through and returns the response unchanged.
     *
     * The middleware holds the `after_action` slot immediately after
     * DispatchMiddleware, which is where end-of-request persistence and
     * cleanup belong; no such work is performed yet.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        // Placeholder: future session persistence / cleanup hooks.
        return $response;
    }
}
