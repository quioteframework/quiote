<?php

namespace Quiote\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\Compiler\MiddlewareAttributeScanner;
use Quiote\Middleware\Compiler\MiddlewareOrderResolver;
use Quiote\Support\Compiler\Diagnostic;
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

    public function __construct(private readonly Context $context)
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

        if (MiddlewareCatalog::hasCoreStackOverride()) {
            // Escape hatch: the app has explicitly replaced the entire default
            // stack (see MiddlewareCatalog::replaceCoreStack()). None of Quiote's
            // own error handling / session / CSRF / security / routing middleware
            // runs here, and registered() middleware is deliberately NOT spliced
            // in either — the app owns the whole pipeline now.
            \Quiote\Logging\Log::for($this)->warning(
                '[MiddlewarePipeline] core stack REPLACED via MiddlewareCatalog::replaceCoreStack() — '
                . 'none of the framework default middleware (error handling, sessions, CSRF, security, '
                . 'routing) is running for this pipeline.'
            );
            foreach (MiddlewareCatalog::buildCoreStack($context) as $mw) {
                $stack[] = $mw;
                $this->debugStack[] = $mw::class;
            }
        } else {
            $controller = $context->getController();
            $routing = $context->getRouting();

            // Per-middleware spans, opt-in and high-cardinality — computed once
            // per build (the stack itself is
            // cached for the worker's lifetime, and telemetry is configured once
            // at worker startup, before any request runs, so this can't go stale
            // mid-worker) so a disabled pipeline never allocates the decorator.
            $spanEachMiddleware = \Quiote\Telemetry\Trace::enabled()
                && \Quiote\Config\Config::get('telemetry.spans.middleware', false);

            // $factory is called with $context so a plugin-supplied attributed
            // factory (see MiddlewareCatalog::attributedFactory()) can build a
            // middleware that needs the currently-building pipeline's Context
            // (e.g. its Controller instance) without capturing anything at plugin
            // registration time, when no Context yet exists. Core's own zero-arg
            // factories (below) and the plain DI fallback both ignore the extra
            // argument, same as any PHP callable invoked with more args than it
            // declares.
            $construct = function (string $label, callable $factory) use (&$stack, $spanEachMiddleware, $context): void {
                $mw = $factory($context);
                if ($spanEachMiddleware) {
                    $mw = new \Quiote\Telemetry\MiddlewareSpanDecorator($mw, $label);
                }
                $stack[] = $mw;
                $this->debugStack[] = $label;
            };

            // CSRF middleware are NOT in this map — they're registered by
            // Quiote\Security\Csrf\CsrfPlugin via
            // PluginRegistrar::attributedMiddleware(), which core runs by default
            // today (see the "core default" note in Quiote::bootstrap()) so
            // this map staying free of them costs nothing while they're still
            // in-tree, and needs no further change when they actually move to
            // their own package.
            $factories = [
                ErrorHandlingMiddleware::class => fn() => new ErrorHandlingMiddleware(function (\Throwable $e, ServerRequestInterface $r): void {
                    $first = $e->getFile() . ':' . $e->getLine();
                    $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
                    \Quiote\Logging\Log::for($this)->error('[MiddlewarePipeline] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $first . ' trace=' . $snippet);
                    // Backstop: TelemetryMiddleware
                    // already records+ends the root span on its own way out (it sits
                    // inside this middleware), so by the time we get here Trace::current()
                    // is normally back to a no-op — this only matters if TelemetryMiddleware
                    // itself never ran (e.g. a stack replaced via replaceCoreStack()) while
                    // some other span is still active.
                    \Quiote\Telemetry\Trace::current()->recordException($e)->setStatusError($e->getMessage());
                }),
                SessionMiddleware::class => fn() => new SessionMiddleware($controller),
                TelemetryMiddleware::class => fn() => new TelemetryMiddleware(),
                TimingMiddleware::class => fn() => new TimingMiddleware(false),
                TraceMiddleware::class => fn() => new TraceMiddleware(false),
                PayloadParsingMiddleware::class => fn() => new PayloadParsingMiddleware(),
                ContentNegotiationMiddleware::class => fn() => new ContentNegotiationMiddleware(),
                RoutingMiddleware::class => fn() => new RoutingMiddleware($routing, $controller),
                OutputTypeSyncMiddleware::class => fn() => new OutputTypeSyncMiddleware($controller),
                SecurityMiddleware::class => fn() => new SecurityMiddleware($controller),
                ValidationMiddleware::class => fn() => new ValidationMiddleware($controller),
                SlotMiddleware::class => fn() => new SlotMiddleware($this->context),
                DispatchMiddleware::class => fn() => new DispatchMiddleware($controller),
                AssetAggregationMiddleware::class => fn() => new AssetAggregationMiddleware(),
                FormPopulationMiddleware::class => fn() => new FormPopulationMiddleware($controller),
                ExecutionTimeMiddleware::class => fn() => new ExecutionTimeMiddleware(),
            ];

            // Order is derived from each class's #[Middleware] attribute (phase +
            // before/after + priority), not a hand-maintained sequence. App middleware opts in
            // via MiddlewareCatalog::registerAttributed(). If the same FQCN is also
            // passed to MiddlewareCatalog::register(), register() wins outright: it's
            // excluded here and spliced in below by insertRegistered() instead.
            $registered = MiddlewareCatalog::getRegistered();
            $attributedCandidates = array_merge(array_keys($factories), MiddlewareCatalog::getAttributedCandidates());
            foreach ($attributedCandidates as $fqcn) {
                if (isset($registered[$fqcn])) {
                    \Quiote\Logging\Log::for($this)->warning(
                        "[MiddlewarePipeline] \"$fqcn\" is both attribute-scannable and "
                        . 'MiddlewareCatalog::register()-ed; register() wins for placement.'
                    );
                }
            }
            $candidates = array_filter(
                $attributedCandidates,
                static fn(string $fqcn): bool => !isset($registered[$fqcn])
            );

            $scanner = new MiddlewareAttributeScanner();
            $definitions = $scanner->scan($candidates);
            foreach ($scanner->getDiagnostics() as $diagnostic) {
                $level = $diagnostic->severity === Diagnostic::SEVERITY_ERROR ? 'error' : 'warning';
                \Quiote\Logging\Log::for($this)->{$level}('[MiddlewarePipeline] middleware scan: ' . $diagnostic->message);
            }

            $resolver = new MiddlewareOrderResolver();
            $ordered = $resolver->resolve($definitions);
            foreach ($resolver->getDiagnostics() as $diagnostic) {
                $level = $diagnostic->severity === Diagnostic::SEVERITY_ERROR ? 'error' : 'warning';
                \Quiote\Logging\Log::for($this)->{$level}('[MiddlewarePipeline] middleware order: ' . $diagnostic->message);
            }

            foreach ($ordered as $definition) {
                $fqcn = $definition->fqcn;
                $enabled = MiddlewareCatalog::hasOverride($fqcn) ? MiddlewareCatalog::isEnabled($fqcn) : $definition->enabled;
                if (!$enabled) {
                    continue;
                }
                $factory = $factories[$fqcn]
                    ?? MiddlewareCatalog::attributedFactory($fqcn)
                    ?? fn() => $context->getContainer()->get($fqcn);
                $construct($fqcn, $factory);
            }

            // Insert externally registered middleware
            $this->insertRegistered($stack);
        }

        // Terminal sentinel - framework must always produce a response
        $stack[] = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('Terminal pipeline reached without response');
            }
        };
        $this->debugStack[] = '__TERMINAL__';
        \Quiote\Logging\Log::for($this)->debug('[MiddlewarePipeline] built stack: ' . implode(' → ', $this->debugStack));

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

    /**
     * @return list<string>
     */
    public function debugStack(): array
    {
        return $this->debugStack;
    }

    /**
     * Insert externally registered middleware into the stack at their requested positions.
     * @param non-empty-list<\Psr\Http\Server\MiddlewareInterface> &$stack
     * @param-out non-empty-list<\Psr\Http\Server\MiddlewareInterface> $stack
     */
    private function insertRegistered(array &$stack): void
    {
        $entries = MiddlewareCatalog::getRegistered();
        if (empty($entries)) {
            return;
        }

        // Sort by priority descending so splice-based insertion yields lower-priority-first order
        uasort($entries, fn($a, $b) => $b['priority'] <=> $a['priority']);

        $spanEachMiddleware = \Quiote\Telemetry\Trace::enabled()
            && \Quiote\Config\Config::get('telemetry.spans.middleware', false);

        foreach ($entries as $entry) {
            if (!MiddlewareCatalog::isEnabled($entry['fqcn'])) {
                continue;
            }

            $pos = $this->findInsertPosition($entry['after'], $entry['before']);
            // See the analogous call in doBuild(): passing $this->context lets a
            // plugin's MiddlewareCatalog::register() factory build a per-context
            // middleware without capturing anything at plugin-registration time.
            $mw = ($entry['factory'])($this->context);
            if (!$mw instanceof \Psr\Http\Server\MiddlewareInterface) {
                throw new \Quiote\Exception\QuioteException(sprintf(
                    'Middleware factory for "%s" must return an instance of %s.',
                    $entry['fqcn'],
                    \Psr\Http\Server\MiddlewareInterface::class,
                ));
            }
            if ($spanEachMiddleware) {
                $mw = new \Quiote\Telemetry\MiddlewareSpanDecorator($mw, $entry['fqcn']);
            }

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
