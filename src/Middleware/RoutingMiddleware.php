<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Routing\AgaviRouting;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ActionDescriptor;

/**
 * Executes Agavi routing and attaches module/action/outputType to PSR request attributes.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'routing', priority: 0)]
class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviRouting $routing, private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->routing->execute();
        if(is_array($result) && isset($result['module'],$result['action'],$result['output_type'])) {
            $module = $result['module'];
            $action = $result['action'];
            $outputType = strtolower($result['output_type']);
            $method = $result['method'] ?? $request->getMethod();
            // Build descriptor (isSimple determined later by ActionExecutor via introspection): default false
            $descriptor = new ActionDescriptor($module, $action, $method, $outputType, false);
            $request = $request
                ->withAttribute('module', $module)
                ->withAttribute('action', $action)
                ->withAttribute('output_type', $outputType)
                ->withAttribute(ActionDescriptor::class, $descriptor)
                ->withAttribute('matched_routes', $result['matched_routes'] ?? []);
        }
        return $handler->handle($request);
    }
}
