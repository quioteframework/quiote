<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Execution\SlotStack;

/**
 * SlotMiddleware: establishes a SlotStack in request attributes for nested slot/sub-action rendering.
 * Later stages (DispatchMiddleware or a future SlotDispatcher) can push/pop keys as they perform slot executions.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre_routing', before: 'RoutingMiddleware')]
class SlotMiddleware implements MiddlewareInterface
{
    public const ATTR = SlotStack::class;
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if(!$request->getAttribute(self::ATTR)) {
            $request = $request->withAttribute(self::ATTR, new SlotStack());
        }
        return $handler->handle($request);
    }
}
