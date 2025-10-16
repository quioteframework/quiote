<?php

namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;
use Relay\Relay;

/**
 * MiddlewarePipeline builds and caches the PSR-15 middleware chain; safe for worker reuse.
 * Formerly named FrameworkMiddlewarePipeline (and before that MiddlewareKernel).
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    /** @var list<class-string|string> */
    private array $debugStack = [];
    private AgaviContext $context;

    public function __construct(AgaviContext $context)
    {
        $this->context = $context;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->built) {
            $this->doBuild();
        }
        return $this->handler->handle($request);
    }

    public function reset(): void
    {
        $this->handler = null;
        $this->built = false;
        $this->debugStack = [];
    }

    private function doBuild(): void
    {
        $this->debugStack = [];
        $stack = [];

        $context = $this->context;
        $controller = $context->getController();
        $routing = $context->getRouting();                

        $construct = function (string $label, callable $factory) use (&$stack) {
            $mw = $factory();
            $stack[] = $mw;
            $this->debugStack[] = $label;
        };
                    
        $construct(ErrorHandlingMiddleware::class, function () use ($context) {
            return new ErrorHandlingMiddleware(function (\Throwable $e, ServerRequestInterface $r) use ($context) {
                $first = $e->getFile() . ':' . $e->getLine();
                $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
                AgaviDebugLogger::error('[MiddlewarePipeline] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $first . ' trace=' . $snippet, $context);
            });
        });
        
        $construct(SessionMiddleware::class, fn() => new SessionMiddleware($controller));

        if (MiddlewareCatalog::isEnabled(TimingMiddleware::class)) {
            $construct(TimingMiddleware::class, fn() => new TimingMiddleware(false));
        }
        if (MiddlewareCatalog::isEnabled(TraceMiddleware::class)) {
            $construct(TraceMiddleware::class, fn() => new TraceMiddleware(false));
        }
        $construct(PayloadParsingMiddleware::class, fn() => new PayloadParsingMiddleware());
        $construct(ContentNegotiationMiddleware::class, fn() => new ContentNegotiationMiddleware($controller));
        $construct(RoutingMiddleware::class, fn() => new RoutingMiddleware($routing, $controller));
        $construct(OutputTypeSyncMiddleware::class, fn() => new OutputTypeSyncMiddleware($controller));
        $construct(SecurityMiddleware::class, fn() => new SecurityMiddleware($controller));
        $construct(ValidationMiddleware::class, fn() => new ValidationMiddleware());
        $construct(SlotMiddleware::class, fn() => new SlotMiddleware($this->context));
        $construct(DispatchMiddleware::class, fn() => new DispatchMiddleware($controller));
        $construct(AssetAggregationMiddleware::class, fn() => new AssetAggregationMiddleware());
            $construct(FormPopulationMiddleware::class, fn() => new FormPopulationMiddleware($controller));
        if (MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class)) {
            $construct(ExecutionTimeMiddleware::class, fn() => new ExecutionTimeMiddleware());
        }
        // Terminal sentinel - framework must always produce a response
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('Terminal pipeline reached without response');
            }
        };
        $this->debugStack[] = '__TERMINAL__';
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

    public function debugStack(): array
    {
        return $this->debugStack;
    }
}
