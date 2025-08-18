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
        $path = $request->getUri()->getPath();
        $dbg = getenv('AGAVI_DEBUG_ROUTING');
        try {
            $attributes = $this->routing->match($path);
            $module = $attributes['_module'] ?? null;
            $action = $attributes['_action'] ?? null;
            $outputType = strtolower($attributes['_output_type'] ?? 'html');
            if($module && $action) {
                $httpMethod = $request->getMethod();
                // Map HTTP verbs to Agavi semantic methods (GET -> READ, POST -> WRITE, etc.)
                $method = match(strtoupper($httpMethod)) {
                    'GET','HEAD','OPTIONS','TRACE' => 'READ',
                    'POST','PUT','PATCH' => 'WRITE',
                    'DELETE' => 'REMOVE',
                    default => strtoupper($httpMethod)
                };
                // Build descriptor via controller so isSimple flag reflects actual action implementation
                try {
                    $descriptor = ActionDescriptor::fromController($this->controller, $module, $action, $method, $outputType);
                } catch(\Throwable) {
                    // Fallback to non-simple if instantiation fails
                    $descriptor = new ActionDescriptor($module, $action, $method, $outputType, false);
                }
                $request = $request
                    ->withAttribute('module', $module)
                    ->withAttribute('action', $action)
                    ->withAttribute('output_type', $outputType)
                    ->withAttribute(ActionDescriptor::class, $descriptor)
                    ->withAttribute('route_name', $attributes['_route'] ?? null)
                    ->withAttribute('route_params', $attributes);
            }
        } catch(\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
            // Leave attributes unset; downstream could handle 404
            // optional debug removed
        }
        return $handler->handle($request);
    }
}
