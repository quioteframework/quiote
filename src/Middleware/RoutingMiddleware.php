<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Routing\AgaviRouting;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Controller\AgaviController;

/**
 * Executes Agavi routing and attaches module/action/outputType to PSR request attributes.
 */
class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviRouting $routing, private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only run if we have legacy adapter (ensures global request is prepared)
        $container = $this->routing->execute();
        if($container instanceof AgaviExecutionContainer) {
            $request = $request
                ->withAttribute('module', $container->getModuleName())
                ->withAttribute('action', $container->getActionName())
                ->withAttribute('output_type', $container->getOutputType()->getName());
            // Stash container for later dispatch middleware to reuse to avoid second execution
            $request = $request->withAttribute('_agavi_execution_container', $container);
        }
        return $handler->handle($request);
    }
}
