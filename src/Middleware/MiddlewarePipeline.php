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

    public function __construct(private readonly AgaviContext $context)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->built) {
            $this->doBuild();
        }
        // Save original request before validation pruning for slot parameter access
        // Pass it through as an attribute so SlotMiddleware can inject it into SlotDispatcher
        $request = $request->withAttribute('_original_psr_request', $request);
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

        $construct = function (string $label, callable $factory) use (&$stack): void {
            $mw = $factory();
            $stack[] = $mw;
            $this->debugStack[] = $label;
        };
                    
        $construct(ErrorHandlingMiddleware::class, fn() => new ErrorHandlingMiddleware(function (\Throwable $e, ServerRequestInterface $r) use ($context): void {
            $first = $e->getFile() . ':' . $e->getLine();
            $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
            AgaviDebugLogger::error('[MiddlewarePipeline] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $first . ' trace=' . $snippet, $context);
        }));
        
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
        // CSRF: injection wraps the response (placed first so it post-processes the
        // final HTML), validation short-circuits unsafe requests with a bad/missing
        // token before the action runs. Both sit before DispatchMiddleware (which is
        // terminal). Behavior is gated at runtime by core.csrf.enabled.
        $construct(CsrfInjectionMiddleware::class, fn() => new CsrfInjectionMiddleware($controller));
        $construct(CsrfValidationMiddleware::class, fn() => new CsrfValidationMiddleware($controller));
        $construct(SecurityMiddleware::class, fn() => new SecurityMiddleware($controller));
        $construct(ValidationMiddleware::class, fn() => new ValidationMiddleware());
        $construct(SlotMiddleware::class, fn() => new SlotMiddleware($this->context));
        $construct(DispatchMiddleware::class, fn() => new DispatchMiddleware($controller));
        $construct(AssetAggregationMiddleware::class, fn() => new AssetAggregationMiddleware());
        $construct(FormPopulationMiddleware::class, fn() => new FormPopulationMiddleware($controller));
        if (MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class)) {
            $construct(ExecutionTimeMiddleware::class, fn() => new ExecutionTimeMiddleware());
        }

        // Insert externally registered middleware
        $this->insertRegistered($stack);

        // Terminal sentinel - framework must always produce a response
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('Terminal pipeline reached without response');
            }
        };
        $this->debugStack[] = '__TERMINAL__';
        AgaviDebugLogger::debug('[MiddlewarePipeline] built stack: ' . implode(' → ', $this->debugStack), $this->context);

        $relay = new Relay($stack);
        $this->handler = new readonly class($relay) implements RequestHandlerInterface {
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

    /**
     * Insert externally registered middleware into the stack at their requested positions.
     *
     * @param list<\Psr\Http\Server\MiddlewareInterface> &$stack
     */
    private function insertRegistered(array &$stack): void
    {
        $entries = MiddlewareCatalog::getRegistered();
        if (empty($entries)) {
            return;
        }

        // Sort by priority descending so splice-based insertion yields lower-priority-first order
        uasort($entries, fn($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($entries as $entry) {
            if (!MiddlewareCatalog::isEnabled($entry['fqcn'])) {
                continue;
            }

            $pos = $this->findInsertPosition($entry['after'], $entry['before']);
            $mw = ($entry['factory'])();

            array_splice($stack, $pos, 0, [$mw]);
            array_splice($this->debugStack, $pos, 0, [$entry['fqcn']]);
        }
    }

    /**
     * Find the insertion index in debugStack based on after/before hints.
     * Falls back to just before SecurityMiddleware if no hints match.
     */
    private function findInsertPosition(?string $after, ?string $before): int
    {
        if ($after !== null) {
            $idx = array_search($after, $this->debugStack, true);
            if ($idx !== false) {
                return $idx + 1;
            }
        }

        if ($before !== null) {
            $idx = array_search($before, $this->debugStack, true);
            if ($idx !== false) {
                return $idx;
            }
        }

        // Default: before SecurityMiddleware
        $idx = array_search(SecurityMiddleware::class, $this->debugStack, true);
        if ($idx !== false) {
            return $idx;
        }

        // Last resort: append at end
        return count($this->debugStack);
    }
}
