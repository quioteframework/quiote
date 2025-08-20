<?php

namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Nyholm\Psr7\Response;
// import negotiation middleware (added)
use Agavi\Middleware\ContentNegotiationMiddleware;
use Relay\Relay;

/**
 * Builds and caches the PSR-15 middleware chain; safe for FrankenPHP worker reuse.
 */
class MiddlewareKernel implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    public function __construct(private AgaviContext $context) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->built) {
            $this->build();
        }
        return $this->handler->handle($request);
    }

    public function reset(): void
    {
        $this->handler = null;
        $this->built = false;
    }

    private function build(): void
    {
        $controller = $this->context->getController();
        $routing = $this->context->getRouting();

        // Direct Relay stack preserving existing phase ordering.
        // Order (outer -> inner): ErrorHandling, Timing, Trace, JsonBodyParsing, ContentNegotiation,
        // Routing, OutputTypeSync, FormPopulation, Security, Validation, Dispatch, Slot, AssetAggregation,
        // ExecutionTime, Terminal.
        $stack = [];

        // Outermost error handler
        $stack[] = new ErrorHandlingMiddleware();
        // bootstrap
        $stack[] = new TimingMiddleware(false);
        $stack[] = new TraceMiddleware(false);
        $stack[] = new JsonBodyParsingMiddleware();
        // routing
        $stack[] = new ContentNegotiationMiddleware($controller); // before routing; routing may override output_type
        $stack[] = new RoutingMiddleware($routing, $controller);
        $stack[] = new OutputTypeSyncMiddleware($controller);
        // before_action
        $stack[] = new FormPopulationMiddleware();
        $stack[] = new SecurityMiddleware($controller);
        $stack[] = new ValidationMiddleware();
        // action
        $stack[] = new DispatchMiddleware($controller);
        // post
        $stack[] = new SlotMiddleware();
        $stack[] = new AssetAggregationMiddleware();
        // finalize
        $stack[] = new ExecutionTimeMiddleware();
        // terminal (safety) middleware
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            { return new Response(500); }
        };

        $relay = new Relay($stack);
        $this->handler = new class($relay) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); }
        };

        $this->built = true;
    }
}
