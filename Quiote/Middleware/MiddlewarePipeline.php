<?php

namespace Quiote\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\Compiler\MiddlewareAttributeScanner;
use Quiote\Middleware\Compiler\MiddlewareDefinition;
use Quiote\Middleware\Compiler\MiddlewareOrderResolver;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Support\Compiler\Diagnostic;
use Relay\Relay;

/**
 * MiddlewarePipeline builds and caches the PSR-15 middleware chain; safe for worker reuse.
 *
 * Worker reuse is the part with teeth for anyone writing a middleware: the
 * stack is built once per worker process and every instance in it then serves
 * every request that worker handles, however many users those come from. A
 * middleware is therefore process-scoped, not request-scoped, whatever the
 * usual PSR-15 mental model suggests -- so a `$this->currentUser = $request->
 * getAttribute(...)`, or a `$this->cached ??= lookup()` memo of anything
 * user-specific, is read back by the next request on that worker and hands one
 * caller another caller's data.
 *
 * Keep request-scoped values on the request's attribute bag or resolve them
 * per call from the container; reserve instance properties for what is
 * genuinely process-wide (config, a shared connection, a compiled table). A
 * middleware that must hold per-request state anyway can implement
 * {@see \Symfony\Contracts\Service\ResetInterface}, and {@see resetInstances()}
 * clears it at every request boundary.
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    /** @var list<class-string|string> */
    private array $debugStack = [];
    /**
     * The built stack's instances, in stack order, kept so {@see resetInstances()}
     * can reach the resettable ones -- Relay closes over them and hands them back out.
     * @var list<\Psr\Http\Server\MiddlewareInterface>
     */
    private array $instances = [];

    public function __construct(private readonly Context $context)
    {
    }

    /**
     * Runs the request through the middleware stack, building the stack first if needed.
     *
     * The built stack is cached on the instance for the life of the worker, so
     * only the first request pays for attribute scanning and order resolution;
     * call {@see reset()} to force a rebuild.
     *
     * @throws \Quiote\Exception\QuioteException If building the stack produced no handler.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->built) {
            $this->doBuild();
        }
        $handler = $this->handler;
        if ($handler === null) {
            throw new \Quiote\Exception\QuioteException('MiddlewarePipeline::doBuild() did not produce a handler.');
        }
        return $handler->handle($request);
    }

    /**
     * Discards the cached stack so the next {@see handle()} rebuilds it.
     *
     * Needed whenever the inputs to the build have changed — a catalog
     * registration, a middleware config entry, an enable/disable override —
     * since the stack is otherwise kept for the worker's lifetime. The
     * middleware instances themselves are dropped, not reset.
     */
    public function reset(): void
    {
        $this->handler = null;
        $this->built = false;
        $this->debugStack = [];
        $this->instances = [];
    }

    /**
     * Clears the per-request state of every middleware in the built stack that
     * declares any, by calling {@see \Symfony\Contracts\Service\ResetInterface::reset()}
     * on it. The stack itself is kept -- this is the request boundary, not a rebuild.
     *
     * Run for each request in a persistent worker, where the instances outlive
     * the request that populated them. A middleware that keeps nothing between
     * calls (the norm, and what {@see MiddlewarePipeline} asks for) implements
     * nothing and is skipped.
     *
     * @return     void
     * @since      4.0.0
     */
    public function resetInstances(): void
    {
        foreach ($this->instances as $middleware) {
            if (!$middleware instanceof \Symfony\Contracts\Service\ResetInterface) {
                continue;
            }
            try {
                $middleware->reset();
            } catch (\Throwable $e) {
                // Whatever this middleware was holding is now carried into the next
                // request on this worker, which is the leak reset() exists to close --
                // and the remaining middleware still get their turn.
                \Quiote\Logging\Log::for($this)->error(
                    '[MiddlewarePipeline] "' . $middleware::class . '" failed to reset at the request '
                    . 'boundary; its state carries into the next request: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * The framework's own shipped middleware classes.
     * @deprecated 3.2.0 Read {@see CoreMiddlewareRegistry::CORE} instead.
     * @return list<class-string<\Psr\Http\Server\MiddlewareInterface>>
     */
    public static function coreMiddlewareClasses(): array
    {
        return CoreMiddlewareRegistry::CORE;
    }

    /**
     * First-party middleware that ships in its own package rather than being built by core.
     * @deprecated 3.2.0 Use {@see CoreMiddlewareRegistry::pluginProvidedClasses()} instead.
     * @return list<string>
     */
    public static function protectedPackageMiddlewareClasses(): array
    {
        return CoreMiddlewareRegistry::pluginProvidedClasses();
    }

    /**
     * The full set {@see \Quiote\Middleware\Config\MiddlewareConfigRegistry} guards against
     * silent config-driven reordering or disabling.
     * @return list<string>
     */
    public static function guardedMiddlewareClasses(): array
    {
        return CoreMiddlewareRegistry::guardedClasses();
    }

    public const CODE_REGISTERED_OVERRIDES_ATTRIBUTE = 'REGISTERED_OVERRIDES_ATTRIBUTE';

    /**
     * Computes the resolved order of the default (non-overridden) middleware stack -- core
     * middleware plus attribute-scanned and config-driven app/plugin middleware -- purely from
     * static registries, without a Context and without constructing a single middleware
     * instance. {@see doBuild()} instantiates in exactly this order, so it calls this rather than
     * duplicating the scan/merge/resolve steps; `quiote middleware:list` reads it the same way,
     * so the two can never disagree.
     *
     * Excludes {@see MiddlewareCatalog::hasCoreStackOverride()}'s replacement stack and
     * externally {@see MiddlewareCatalog::register()}-ed middleware -- the former only exists by
     * invoking an app-supplied factory, and the latter's position depends on splicing into an
     * already-built stack (see {@see insertRegistered()}), not on phase/priority ordering.
     *
     * @return array{ordered: list<array{definition: \Quiote\Middleware\Compiler\MiddlewareDefinition, enabled: bool}>, diagnostics: list<Diagnostic>}
     */
    public static function resolveOrder(): array
    {
        $diagnostics = [];

        // Order is derived from each class's #[Middleware] attribute (phase +
        // before/after + priority), not a hand-maintained sequence. App middleware opts in
        // via MiddlewareCatalog::registerAttributed(). If the same FQCN is also
        // passed to MiddlewareCatalog::register(), register() wins outright: it's
        // excluded here and spliced in at build time by insertRegistered() instead.
        $registered = MiddlewareCatalog::getRegistered();
        $attributedCandidates = array_merge(CoreMiddlewareRegistry::CORE, MiddlewareCatalog::getAttributedCandidates());
        foreach ($attributedCandidates as $fqcn) {
            if (isset($registered[$fqcn])) {
                $diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_WARNING,
                    self::CODE_REGISTERED_OVERRIDES_ATTRIBUTE,
                    sprintf('"%s" is both attribute-scannable and MiddlewareCatalog::register()-ed; register() wins for placement.', $fqcn),
                    $fqcn,
                );
            }
        }
        $candidates = array_filter(
            $attributedCandidates,
            static fn(string $fqcn): bool => !isset($registered[$fqcn])
        );

        $scanner = new MiddlewareAttributeScanner();
        $definitions = $scanner->scan($candidates);
        $diagnostics = array_merge($diagnostics, $scanner->getDiagnostics());

        $definitions = self::mergeConfigDefinitions($definitions);

        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve($definitions);
        $diagnostics = array_merge($diagnostics, $resolver->getDiagnostics());

        return [
            'ordered' => array_values(array_map(static function (MiddlewareDefinition $definition): array {
                $fqcn = $definition->fqcn;
                $enabled = MiddlewareCatalog::hasOverride($fqcn) ? MiddlewareCatalog::isEnabled($fqcn) : $definition->enabled;
                return ['definition' => $definition, 'enabled' => $enabled];
            }, $ordered)),
            'diagnostics' => array_values($diagnostics),
        ];
    }

    private function doBuild(): void
    {
        $this->debugStack = [];
        $this->instances = [];
        $stack = [];

        $context = $this->context;

        if (MiddlewareCatalog::hasCoreStackOverride()) {
            // The app has explicitly replaced the entire default
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
                $this->instances[] = $mw;
                $this->debugStack[] = $mw::class;
            }
        } else {
            $controller = $context->getContainer()->get(\Quiote\Controller\Controller::class);
            $routing = $context->getContainer()->get(\Quiote\Routing\Routing::class);

            // Per-middleware spans, opt-in and high-cardinality — computed once
            // per build (the stack itself is
            // cached for the worker's lifetime, and telemetry is configured once
            // at worker startup, before any request runs, so this can't go stale
            // mid-worker) so a disabled pipeline never allocates the decorator.
            $spanEachMiddleware = \Quiote\Telemetry\Trace::enabled()
                && \Quiote\Config\Config::getBool('telemetry.spans.middleware', false);

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
                // Recorded before any decoration: resetInstances() has to reach the
                // middleware that holds the state, not the span wrapper around it.
                $this->instances[] = $mw;
                if ($spanEachMiddleware) {
                    $mw = new \Quiote\Telemetry\MiddlewareSpanDecorator($mw, $label);
                }
                $stack[] = $mw;
                $this->debugStack[] = $label;
            };

            $factories = CoreMiddlewareRegistry::factories($context);

            $resolved = self::resolveOrder();
            foreach ($resolved['diagnostics'] as $diagnostic) {
                $level = $diagnostic->severity === Diagnostic::SEVERITY_ERROR ? 'error' : 'warning';
                \Quiote\Logging\Log::for($this)->{$level}('[MiddlewarePipeline] middleware: ' . $diagnostic->message);
            }

            foreach ($resolved['ordered'] as $entry) {
                if (!$entry['enabled']) {
                    continue;
                }
                $fqcn = $entry['definition']->fqcn;
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
            /** Always throws: reaching the end of the stack means no middleware produced a response. */
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
            /** Enters the Relay chain built from the resolved stack. */
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
     * Overlays {@see MiddlewareConfigRegistry}'s validated `middleware.*`
     * contributions onto the attribute-scanned definitions: an entry naming
     * an already-scanned class replaces only the fields it explicitly sets
     * (null fields keep the scanned/default value), an entry naming a class
     * with no attribute at all is created fresh (defaulting exactly like
     * {@see \Quiote\Middleware\Attribute\Middleware}'s own constructor).
     * @param MiddlewareDefinition[] $scanned
     * @return MiddlewareDefinition[]
     */
    private static function mergeConfigDefinitions(array $scanned): array
    {
        $byFqcn = [];
        foreach ($scanned as $definition) {
            $byFqcn[$definition->fqcn] = $definition;
        }

        foreach (MiddlewareConfigRegistry::all() as $entry) {
            $fqcn = $entry['class'];
            $defaultPhase = 'pre';
            $defaultPriority = 0;
            $defaultBefore = null;
            $defaultAfter = null;
            $defaultEnabled = true;
            if (array_key_exists($fqcn, $byFqcn)) {
                $existing = $byFqcn[$fqcn];
                $defaultPhase = $existing->phase;
                $defaultPriority = $existing->priority;
                $defaultBefore = $existing->before;
                $defaultAfter = $existing->after;
                $defaultEnabled = $existing->enabled;
            }
            $byFqcn[$fqcn] = new MiddlewareDefinition(
                $fqcn,
                $entry['phase'] ?? $defaultPhase,
                $entry['priority'] ?? $defaultPriority,
                $entry['before'] ?? $defaultBefore,
                $entry['after'] ?? $defaultAfter,
                $entry['enabled'] ?? $defaultEnabled,
                $entry['sourceRef'],
            );
        }

        return array_values($byFqcn);
    }

    /**
     * Insert externally registered middleware into the stack at their requested positions.
     * @param list<\Psr\Http\Server\MiddlewareInterface> &$stack
     * @param-out list<\Psr\Http\Server\MiddlewareInterface> $stack
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
            && \Quiote\Config\Config::getBool('telemetry.spans.middleware', false);

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
            // See doBuild()'s $construct: the undecorated instance is the one
            // resetInstances() must be able to reach.
            $this->instances[] = $mw;
            if ($spanEachMiddleware) {
                $mw = new \Quiote\Telemetry\MiddlewareSpanDecorator($mw, $entry['fqcn']);
            }

            array_splice($stack, $pos, 0, [$mw]);
            array_splice($this->debugStack, $pos, 0, [$entry['fqcn']]);
        }
    }

    /**
     * Find the insertion index in debugStack based on after/before hints.
     * Falls back to just after ValidationMiddleware if no hints match, so a
     * custom middleware that doesn't ask for a specific placement is handed
     * already-validated parameters by default. Opt into running earlier
     * (e.g. before SecurityMiddleware) via explicit `after`/`before` hints on
     * {@see MiddlewareCatalog::register()} if that's genuinely required.
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

        // Default: after ValidationMiddleware
        $idx = array_search(ValidationMiddleware::class, $this->debugStack, true);
        if ($idx !== false) {
            return $idx + 1;
        }

        // Last resort: append at end
        return count($this->debugStack);
    }
}
