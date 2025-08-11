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
        // If legacy adapter used, copy css/js arrays into unified assets.* attributes for later template usage.
        if($request instanceof \Agavi\Http\PsrServerRequestAdapter) {
            $legacy = $request->getLegacyRequest();
            if($legacy) {
                $rqData = $legacy; // legacy request has attribute holder methods
                foreach(['css','js'] as $key) {
                    if($legacy->hasAttribute($key)) {
                        $request = $request->withAttribute('assets.'.$key, $legacy->getAttribute($key));
                    }
                }
            }
        }
        $response = $handler->handle($request);
        return $response;
    }
}
