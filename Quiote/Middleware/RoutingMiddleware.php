<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Routing\Routing;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\HttpMethodMapper;
use Nyholm\Psr7\Response;

/**
 * Executes Quiote routing and attaches module/action/outputType to PSR request attributes.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'routing', priority: 0)]
class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Routing $routing, private readonly Controller $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $dbg = \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug);
        // The routing's RequestContext is never updated elsewhere in the framework and otherwise
        // defaults to 'GET', which would make UrlMatcher reject every legitimate non-GET request
        // against a method-constrained route. Sync it to the incoming request before matching.
        $this->routing->getRequestContext()->setMethod($request->getMethod());
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
                    \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] matched path=' . $path . ' module=' . $module . ' action=' . $action . ' outputType=' . $outputType . ' preNegotiated=' . var_export($preNegotiated, true) . ' routeName=' . ($attributes['_route'] ?? ''));
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
                    \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] no module/action resolved for path=' . $path);
                }
            }
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            // Leave attributes unset; downstream could handle 404
            // optional debug removed
            if ($dbg) {
                \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] resource not found path=' . $path);
            }
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
            // A route matched the path but not this HTTP method.
            // OPTIONS requests are left unrouted (attributes unset) so downstream middleware
            // (e.g. CORS preflight handlers) still gets a chance to respond, matching the
            // behaviour routes had before per-route method constraints were introduced.
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                if ($dbg) {
                    \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] method not allowed for OPTIONS, leaving unrouted path=' . $path);
                }
                return $handler->handle($request);
            }
            if ($dbg) {
                \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] method not allowed path=' . $path . ' allowed=' . implode(',', $e->getAllowedMethods()));
            }
            return new Response(405, ['Allow' => implode(', ', $e->getAllowedMethods())]);
        }
        return $handler->handle($request);
    }
}
