<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * Collects legacy appended attributes like 'css' and 'js' from the AgaviRequest
 * (when using adapter) and exposes them as PSR request attributes `assets.css` and `assets.js`.
 */
class AssetAggregationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
    // Adapter removed: assets should now be set directly as request attributes upstream.
        $response = $handler->handle($request);
        return $response;
    }
}
