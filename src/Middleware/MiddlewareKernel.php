<?php

namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Nyholm\Psr7\Response;
use Agavi\Logging\AgaviDebugLogger;
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
        $context = $this->context;
    $stack[] = new \Agavi\Middleware\ErrorHandlingMiddleware(function(\Throwable $e, ServerRequestInterface $r) use ($context) {
            $first = $e->getFile().':'.$e->getLine();
            $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
            AgaviDebugLogger::debug('[AgaviKernel] '.get_class($e).': '.$e->getMessage().' @ '.$first.' trace='.$snippet, $context);
        });
        // session
    $stack[] = new \Agavi\Middleware\SessionMiddleware($controller);
        // bootstrap
    $stack[] = new \Agavi\Middleware\TimingMiddleware(false);
    $stack[] = new \Agavi\Middleware\TraceMiddleware(false);
        // Unified body parsing (form + json)
    $stack[] = new \Agavi\Middleware\PayloadParsingMiddleware();
        // routing
    $stack[] = new \Agavi\Middleware\ContentNegotiationMiddleware($controller); // before routing; routing may override output_type
    $stack[] = new \Agavi\Middleware\RoutingMiddleware($routing, $controller);
    $stack[] = new \Agavi\Middleware\OutputTypeSyncMiddleware($controller);
        // before_action
        //$stack[] = new FormPopulationMiddleware();
    $stack[] = new \Agavi\Middleware\SecurityMiddleware($controller);
    $stack[] = new \Agavi\Middleware\ValidationMiddleware();
        // Ensure SlotMiddleware runs before Dispatch so SlotStack is available to views
    $stack[] = new \Agavi\Middleware\SlotMiddleware($this->context);
        // action
    $stack[] = new \Agavi\Middleware\DispatchMiddleware($controller);
    $stack[] = new \Agavi\Middleware\AssetAggregationMiddleware();
    $stack[] = new \Agavi\Middleware\FormPopulationMiddleware($controller);
        // finalize
    $stack[] = new \Agavi\Middleware\ExecutionTimeMiddleware();
        // terminal (safety) middleware
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('Terminal pipeline reached without response');
            }
        };

        $relay = new Relay($stack);
        $this->handler = new class($relay) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return $this->relay->handle($r);
            }
        };

        $this->built = true;
    }
}
