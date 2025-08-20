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
use Agavi\Execution\HttpMethodMapper;

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
                // Centralized mapping
                $method = HttpMethodMapper::toActionMethod($httpMethod);
                // Build descriptor via controller so isSimple flag reflects actual action implementation
                try {
                    $descriptor = ActionDescriptor::fromController($this->controller, $module, $action, $method, $outputType);
                } catch(\Throwable) {
                    // Fallback to non-simple if instantiation fails
                    $descriptor = new ActionDescriptor($module, $action, $method, $outputType, false);
                }
                if($dbg) { error_log('[RoutingMiddleware] matched path=' . $path . ' module=' . $module . ' action=' . $action . ' outputType=' . $outputType . ' routeName=' . ($attributes['_route'] ?? '')); }
                $request = $request
                    ->withAttribute('module', $module)
                    ->withAttribute('action', $action)
                    ->withAttribute('output_type', $outputType)
                    ->withAttribute(ActionDescriptor::class, $descriptor)
                    ->withAttribute('route_name', $attributes['_route'] ?? null)
                    ->withAttribute('route_params', $attributes);
            } else {
                if($dbg) { error_log('[RoutingMiddleware] no module/action resolved for path=' . $path); }
            }
        } catch(\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
            // Leave attributes unset; downstream could handle 404
            // optional debug removed
            if($dbg) { error_log('[RoutingMiddleware] resource not found path=' . $path); }
        }
        return $handler->handle($request);
    }
}
