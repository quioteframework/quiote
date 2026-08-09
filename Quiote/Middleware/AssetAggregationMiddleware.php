<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * Collects legacy appended attributes like 'css' and 'js' from the Request
 * (when using adapter) and exposes them as PSR request attributes `assets.css` and `assets.js`.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'after_action')]
class AssetAggregationMiddleware implements MiddlewareInterface
{
    /**
     * Passes the request straight through to the rest of the stack.
     *
     * Nothing is aggregated here: `assets.css` and `assets.js` are expected to
     * be set as request attributes upstream, so this middleware neither reads
     * nor writes them and leaves the response untouched. It stays in the
     * `after_action` phase as the placement for asset collection.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
    // Adapter removed: assets should now be set directly as request attributes upstream.
        $response = $handler->handle($request);
        return $response;
    }
}
