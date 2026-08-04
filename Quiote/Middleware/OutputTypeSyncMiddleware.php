<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;

/**
 * Synchronizes the Controller's current output type with the PSR request attribute 'output_type'
 * after routing has resolved (and potentially overridden) it. Ensures downstream code relying on
 * $controller->getOutputType() sees the correct routed/negotiated value.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'routing', after: 'RoutingMiddleware', before: 'SecurityMiddleware', priority: -50)]
class OutputTypeSyncMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $attr = $request->getAttribute('output_type');
        if(is_string($attr) && $attr !== '') {
            try {
                // Calling getOutputType with a name mutates controller internal selection
                $this->controller->getOutputType($attr);
            } catch(\Throwable $e) {
                // An unknown name leaves the controller's current selection in place, which is
                // the right outcome for a request naming an output type the app does not define.
                \Quiote\Logging\Log::for($this)->debug(
                    '[OutputTypeSyncMiddleware] request named an unknown output type; keeping the '
                    . 'current selection: ' . $e->getMessage()
                );
            }
        }
        return $handler->handle($request);
    }
}
