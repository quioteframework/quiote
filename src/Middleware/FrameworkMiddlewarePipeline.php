<?php

namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Relay\Relay;

/**
 * MiddlewarePipeline builds and caches the PSR-15 middleware chain; safe for worker reuse.
 * (Replaces legacy phase-based pipeline & former FrameworkMiddlewarePipeline.)
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    /** @var list<class-string|string> Execution order for diagnostics (mirrors legacy implementation) */
    private array $debugStack = [];
    public function __construct(private readonly AgaviContext $context) {}

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
        $this->debugStack = [];
    }

    private function build(): void
    {
        $controller = $this->context->getController();
        $routing = $this->context->getRouting();
        $stack = [];
        $this->debugStack = [];

        $construct = function (string $label, callable $factory) use (&$stack): void {
            $mw = $factory();
            $stack[] = $mw;
            $this->debugStack[] = $label;
        };

        $context = $this->context;
        $construct(ErrorHandlingMiddleware::class, fn() => new ErrorHandlingMiddleware(function (\Throwable $e, ServerRequestInterface $r) use ($context): void {
            $first = $e->getFile() . ':' . $e->getLine();
            $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
            \Agavi\Logging\Log::for($this)->debug('[MiddlewarePipeline] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $first . ' trace=' . $snippet);
        }));
        $construct(SessionMiddleware::class, fn()=> new SessionMiddleware($controller));
        $construct(TimingMiddleware::class, fn()=> new TimingMiddleware(false));
        $construct(TraceMiddleware::class, fn()=> new TraceMiddleware(false));
        $construct(PayloadParsingMiddleware::class, fn()=> new PayloadParsingMiddleware());
        $construct(ContentNegotiationMiddleware::class, fn()=> new ContentNegotiationMiddleware($controller));
        $construct(RoutingMiddleware::class, fn()=> new RoutingMiddleware($routing, $controller));
        $construct(OutputTypeSyncMiddleware::class, fn()=> new OutputTypeSyncMiddleware($controller));
        $construct(SecurityMiddleware::class, fn()=> new SecurityMiddleware($controller));
        $construct(ValidationMiddleware::class, fn()=> new ValidationMiddleware());
        $construct(SlotMiddleware::class, fn()=> new SlotMiddleware($this->context));
        $construct(DispatchMiddleware::class, fn()=> new DispatchMiddleware($controller));
        $construct(AssetAggregationMiddleware::class, fn()=> new AssetAggregationMiddleware());
        if (\Agavi\Middleware\MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class)) {
            $construct(ExecutionTimeMiddleware::class, fn()=> new ExecutionTimeMiddleware());
        }
        // terminal safeguard
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            { throw new \RuntimeException('Terminal pipeline reached without response'); }
        };
        $this->debugStack[] = '__TERMINAL__';

        $relay = new Relay($stack);
        $this->handler = new readonly class($relay) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); }
        };
        $this->built = true;
    }

    /**
     * Return debug execution order stack (first element is the first middleware executed).
     * Provided for test diagnostics and introspection.
     * @return list<string>
     */
    public function debugStack(): array
    {
        return $this->debugStack;
    }
}
