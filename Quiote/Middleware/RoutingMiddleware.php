<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Config\Config;
use Quiote\Routing\Routing;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Telemetry\NoopSpanHandle;
use Quiote\Telemetry\SpanHandle;
use Quiote\Telemetry\Trace;
use Nyholm\Psr7\Response;

/**
 * Executes Quiote routing and attaches module/action/outputType to PSR request attributes.
 *
 * Also owns the route-match span (`telemetry.spans.route`, default true)
 * and, on a successful match, renames whatever telemetry span is currently active (the root request span opened
 * by `TelemetryMiddleware`) to the matched route's low-cardinality identity —
 * this is the only place in the pipeline that knows it, since `TelemetryMiddleware`
 * only ever sees the raw request/response per PSR-7 immutability (its own
 * request clone never reflects attributes set by inner middleware).
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

        $spansEnabled = Config::get('telemetry.spans.route', true);
        // Captured BEFORE opening the route-match span below: Trace::span()
        // activates its span immediately, which would make Trace::current()
        // return the route span itself (now the innermost active span), not
        // the outer root span opened by TelemetryMiddleware that we actually
        // want to rename.
        $root = $spansEnabled ? Trace::current() : NoopSpanHandle::instance();
        $span = $spansEnabled
            ? Trace::span('Quiote.Routing', 'match')
            : NoopSpanHandle::instance();
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
                $this->recordMatch($span, $root, $httpMethod, $path, $attributes['_route'] ?? null);
                $request = $request
                    ->withAttribute('module', $module)
                    ->withAttribute('action', $action)
                    ->withAttribute('output_type', $outputType)
                    ->withAttribute(ActionDescriptor::class, $descriptor)
                    ->withAttribute('route_name', $attributes['_route'] ?? null)
                    ->withAttribute('route_params', $attributes);
                // Lifecycle hook: route matched.
                // Events::emit gates on hasListeners and swallows listener errors,
                // so a no-listener app pays only a lookup and a bad listener can't
                // break routing.
                \Quiote\Event\Events::emit(new \Quiote\Event\Lifecycle\RequestMatchedEvent(
                    $request, (string) $module, (string) $action, $attributes['_route'] ?? null, $outputType
                ));
            } else {
                if ($dbg) {
                    \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] no module/action resolved for path=' . $path);
                }
                $span->setAttribute('route.matched', false);
            }
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            // Leave attributes unset; downstream could handle 404
            // optional debug removed
            if ($dbg) {
                \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] resource not found path=' . $path);
            }
            $span->setAttribute('route.matched', false)->setAttribute('route.outcome', '404');
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
            // A route matched the path but not this HTTP method.
            // OPTIONS requests are left unrouted (attributes unset) so downstream middleware
            // (e.g. CORS preflight handlers) still gets a chance to respond, matching the
            // behaviour routes had before per-route method constraints were introduced.
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                if ($dbg) {
                    \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] method not allowed for OPTIONS, leaving unrouted path=' . $path);
                }
                $span->setAttribute('route.matched', false)->setAttribute('route.outcome', '405-options-passthrough');
                return $handler->handle($request); // finally below still ends the span before this returns
            }
            if ($dbg) {
                \Quiote\Logging\Log::for($this)->debug('[RoutingMiddleware] method not allowed path=' . $path . ' allowed=' . implode(',', $e->getAllowedMethods()));
            }
            $span->setAttribute('route.matched', false)->setAttribute('route.outcome', '405')->setAttribute('http.response.status_code', 405);
            return new Response(405, ['Allow' => implode(', ', $e->getAllowedMethods())]);
        } finally {
            $span->end();
        }
        return $handler->handle($request);
    }

    /**
     * Sets route-match span attributes, and renames $root (the span that was
     * active before the route-match span was opened — the root request span,
     * in the default pipeline) to the matched route's low-cardinality
     * identity — falling back to the route name (always available, always
     * low-cardinality) if the raw path pattern can't be looked up for any
     * reason.
     */
    private function recordMatch(SpanHandle $span, SpanHandle $root, string $httpMethod, string $path, ?string $routeName): void
    {
        $routeIdentity = $this->resolveRoutePattern($routeName) ?? $routeName ?? $path;
        $span->setAttribute('http.route', $routeIdentity)->setAttribute('route_name', $routeName);
        $root->updateName($httpMethod . ' ' . $routeIdentity)
            ->setAttribute('http.route', $routeIdentity)
            ->setAttribute('route_name', $routeName);
    }

    private function resolveRoutePattern(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        try {
            [$routes] = $this->routing->exportRoutes();
            return $routes->get($routeName)?->getPath();
        } catch (\Throwable) {
            return null;
        }
    }
}
