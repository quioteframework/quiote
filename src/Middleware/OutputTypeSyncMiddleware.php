<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;

/**
 * Synchronizes the AgaviController's current output type with the PSR request attribute 'output_type'
 * after routing has resolved (and potentially overridden) it. Ensures downstream code relying on
 * $controller->getOutputType() sees the correct routed/negotiated value.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'routing', after: 'RoutingMiddleware', before: 'SecurityMiddleware', priority: -50)]
class OutputTypeSyncMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $attr = $request->getAttribute('output_type');
        if(is_string($attr) && $attr !== '') {
            try {
                // Calling getOutputType with a name mutates controller internal selection
                $this->controller->getOutputType($attr);
            } catch(\Throwable) {
                // Ignore invalid output type names
            }
        }
        return $handler->handle($request);
    }
}
