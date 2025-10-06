<?php

namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;
use Relay\Relay;

/**
 * FrameworkMiddlewarePipeline builds and caches the PSR-15 middleware chain; safe for worker reuse.
 * Formerly named MiddlewareKernel.
 */
class FrameworkMiddlewarePipeline implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    /** @var array<class-string> */
    private array $debugStack = [];
    private bool $traceEnabled = false; // retained for potential future lightweight tracing (currently unused)
    public function __construct(private AgaviContext $context) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->built) { $this->build(); }
        $response = $this->handler->handle($request);
        return $response;
    }

    public function reset(): void
    { $this->handler = null; $this->built = false; $this->debugStack = []; }

    private function build(): void
    {
        $this->debugStack = [];
        $controller = $this->context->getController();
        $routing = $this->context->getRouting();
        $capture = function(string $label) {};

        $stack = [];

        // Helper to construct middleware with delta detection
        $construct = function(string $name, callable $factory) use (&$stack) {
            $before = ob_get_level();
            $mw = $factory();
            $after = ob_get_level();
            $stack[] = $mw;
            if($this->traceEnabled) {
                // tracing removed; previously recorded build deltas
            }
        };

        // Outermost error handler
        $context = $this->context;
        $construct('ErrorHandlingMiddleware', function() use ($context) { return new ErrorHandlingMiddleware(function(\Throwable $e, ServerRequestInterface $r) use ($context) {
            $first = $e->getFile().':'.$e->getLine();
            $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
            AgaviDebugLogger::debug('[FrameworkPipeline] '.get_class($e).': '.$e->getMessage().' @ '.$first.' trace='.$snippet, $context);
        }); });
        $this->debugStack[] = ErrorHandlingMiddleware::class;
        $capture('after:ErrorHandlingMiddleware');
        $construct('SessionMiddleware', function() use ($controller) { return new SessionMiddleware($controller); });
        $this->debugStack[] = SessionMiddleware::class;
        $capture('after:SessionMiddleware');
        $construct('TimingMiddleware', function() { return new TimingMiddleware(false); });
        $this->debugStack[] = TimingMiddleware::class;
        $capture('after:TimingMiddleware');
        $construct('TraceMiddleware', function() { return new TraceMiddleware(false); });
        $this->debugStack[] = TraceMiddleware::class;
        $capture('after:TraceMiddleware');
        $construct('PayloadParsingMiddleware', function() { return new PayloadParsingMiddleware(); });
        $this->debugStack[] = PayloadParsingMiddleware::class;
        $capture('after:PayloadParsingMiddleware');
        $construct('ContentNegotiationMiddleware', function() use ($controller) { return new ContentNegotiationMiddleware($controller); });
        $this->debugStack[] = ContentNegotiationMiddleware::class;
        $capture('after:ContentNegotiationMiddleware');
        $construct('RoutingMiddleware', function() use ($routing, $controller) { return new RoutingMiddleware($routing, $controller); });
        $this->debugStack[] = RoutingMiddleware::class;
        $capture('after:RoutingMiddleware');
        $construct('OutputTypeSyncMiddleware', function() use ($controller) { return new OutputTypeSyncMiddleware($controller); });
        $this->debugStack[] = OutputTypeSyncMiddleware::class;
        $capture('after:OutputTypeSyncMiddleware');
        $construct('SecurityMiddleware', function() use ($controller) { return new SecurityMiddleware($controller); });
        $this->debugStack[] = SecurityMiddleware::class;
        $capture('after:SecurityMiddleware');
        $construct('ValidationMiddleware', function() { return new ValidationMiddleware(); });
        $this->debugStack[] = ValidationMiddleware::class;
        $capture('after:ValidationMiddleware');
        $construct('SlotMiddleware', function() { return new SlotMiddleware($this->context); });
        $this->debugStack[] = SlotMiddleware::class;
        $capture('after:SlotMiddleware');
        $construct('DispatchMiddleware', function() use ($controller) { return new DispatchMiddleware($controller); });
        $this->debugStack[] = DispatchMiddleware::class;
        $capture('after:DispatchMiddleware');
        $construct('AssetAggregationMiddleware', function() { return new AssetAggregationMiddleware(); });
        $this->debugStack[] = AssetAggregationMiddleware::class;
        $capture('after:AssetAggregationMiddleware');
        // Execution time middleware will be conditionally included after enable map hook (future step)
        if (\Agavi\Middleware\MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class)) {
            $construct('ExecutionTimeMiddleware', function() { return new ExecutionTimeMiddleware(); });
            $this->debugStack[] = ExecutionTimeMiddleware::class;
            $capture('after:ExecutionTimeMiddleware');
        }
        // terminal safety middleware
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            { throw new \RuntimeException('Terminal pipeline reached without response'); }
        };
        $this->debugStack[] = '__TERMINAL__';
        $capture('after:__TERMINAL__');

        // No runtime OB tracing in cleaned version

        $relay = new Relay($stack);
        $this->handler = new class($relay) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); }
        };
        $this->built = true;
        // Initial build snapshot (runtime entries will be appended and flushed after handle()).
        // Tracing removed: no file output
    }

    /** Testing helper: returns ordered class names of middleware stack (plus '__TERMINAL__'). */
    public function debugStack(): array
    { return $this->debugStack; }
}

?>