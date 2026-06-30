<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Routing\AgaviRouting;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ActionDescriptor;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Execution\HttpMethodMapper;

/**
 * Executes Agavi routing and attaches module/action/outputType to PSR request attributes.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'routing', priority: 0)]
class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AgaviRouting $routing, private readonly AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $dbg = \Agavi\Util\DebugFlags::$routing;
        try {
            $attributes = $this->routing->match($path);
            $module = $attributes['_module'] ?? null;
            $action = $attributes['_action'] ?? null;
            // Preserve pre-routing negotiation if route does not specify an output_type
            $preNegotiated = $request->getAttribute('output_type');
            $outputType = $attributes['_output_type'] ?? $preNegotiated ?? 'html';
            $outputType = is_string($outputType) ? strtolower($outputType) : 'html';
            if ($module && $action) {
                $httpMethod = $request->getMethod();
                // Centralized mapping
                $method = HttpMethodMapper::toActionMethod($httpMethod); // TODO: create rector rule to change executeRead to executeGet etc
                // Build descriptor via controller so isSimple flag reflects actual action implementation
                try {
                    $descriptor = ActionDescriptor::fromController($this->controller, $module, $action, $method, $outputType);
                } catch (\Throwable) {
                    // Fallback to non-simple if instantiation fails
                    $descriptor = new ActionDescriptor($module, $action, $method, $outputType, false);
                }
                if ($dbg) {
                    AgaviDebugLogger::debug('[RoutingMiddleware] matched path=' . $path . ' module=' . $module . ' action=' . $action . ' outputType=' . $outputType . ' preNegotiated=' . var_export($preNegotiated, true) . ' routeName=' . ($attributes['_route'] ?? ''), $this->controller->getContext());
                }
                $request = $request
                    ->withAttribute('module', $module)
                    ->withAttribute('action', $action)
                    ->withAttribute('output_type', $outputType)
                    ->withAttribute(ActionDescriptor::class, $descriptor)
                    ->withAttribute('route_name', $attributes['_route'] ?? null)
                    ->withAttribute('route_params', $attributes);
            } else {
                if ($dbg) {
                    AgaviDebugLogger::debug('[RoutingMiddleware] no module/action resolved for path=' . $path, $this->controller->getContext());
                }
            }
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            // Leave attributes unset; downstream could handle 404
            // optional debug removed
            if ($dbg) {
                AgaviDebugLogger::debug('[RoutingMiddleware] resource not found path=' . $path, $this->controller->getContext());
            }
        }
        return $handler->handle($request);
    }
}
