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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
    // Adapter removed: assets should now be set directly as request attributes upstream.
        $response = $handler->handle($request);
        return $response;
    }
}
