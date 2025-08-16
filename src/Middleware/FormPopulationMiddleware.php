<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * Minimal replacement for AgaviFormPopulationFilter: ensures request data holders are populated early.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', before: 'SecurityMiddleware', priority: 10)]
class FormPopulationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
    // Build request data attribute for downstream middlewares / actions (always container-less now)
    $rd = $request->getAttribute('agavi.request_data');
    if(!$rd instanceof AgaviRequestDataHolder) { $rd = new AgaviRequestDataHolder(); }
    $query = $request->getQueryParams(); foreach($query as $k=>$v) { $rd->setParameter($k,$v); }
    $body = $request->getParsedBody(); if(is_array($body)) { foreach($body as $k=>$v) { $rd->setParameter($k,$v); } }
    $routeParams = $request->getAttribute('route_params'); if(is_array($routeParams)) { foreach($routeParams as $k=>$v) { $rd->setParameter($k,$v); } }
    $request = $request->withAttribute('agavi.request_data', $rd);
        return $handler->handle($request);
    }
}
