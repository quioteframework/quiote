<?php

namespace Quiote\Plugin;

use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Logging\Log;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;

/**
 * Process-global registry + lifecycle for {@see PluginInterface}s, mirroring the
 * static, worker-lifetime pattern of {@see \Quiote\Middleware\MiddlewareCatalog}
 * and {@see \Quiote\Event\Events}: plugins are registered once and their
 * contributions persist for the life of the process.
 *
 * Lifecycle:
 *  - {@see add()} — programmatic registration (before bootstrap).
 *  - {@see bootFromConfig()} — called by {@see \Quiote\Quiote::bootstrap()} after
 *    settings load and before contexts are created (the one seam between those
 *    steps): reads the `plugins` config key, instantiates + adds them, then calls
 *    {@see PluginInterface::register()} on every plugin in deterministic order,
 *    de-duped by class. Idempotent.
 *  - {@see configureContainer()} — applies deferred DI-service contributions to a
 *    context's container (register-if-absent).
 *  - {@see configureHttpClients()} — applies named-HTTP-client contributions to a
 *    container's {@see HttpClientFactory}.
 *  - {@see moduleDirectories()} / {@see contributedCommands()} — read by the
 *    attribute route scanner / console application.
 */
final class PluginManager
{
    /** @var array<class-string, PluginInterface> registered plugins, keyed by class (dedupe + declared order) */
    private static array $plugins = [];

    private static bool $registered = false;

    /** @var list<string> contributed module directories */
    private static array $moduleDirs = [];

    /** @var list<string> contributed console command FQCNs */
    private static array $commands = [];

    /** @var list<array{id: string, concrete: mixed, scope: ?string, aliases: list<string>}> deferred DI services */
    private static array $containerServices = [];

    /** @var array<string, callable> named HTTP client configurators */
    private static array $httpClientConfigs = [];

    /** @var array<string, \Closure(): void> contributed end-of-request clears, keyed by label */
    private static array $requestEndClears = [];

    /** @var array<string, \Closure(): void> contributed static-state resets, keyed by label */
    private static array $stateResets = [];

    private function __construct() {}

    /** Register a plugin (instance or class-string). De-duped by class; declared order preserved. */
    public static function add(PluginInterface|string $plugin): void
    {
        $instance = is_string($plugin) ? self::instantiate($plugin) : $plugin;
        if ($instance === null) {
            return;
        }
        self::$plugins[$instance::class] ??= $instance;
    }

    /**
     * Boot phase: pull plugins from the `plugins` config key, then invoke
     * register() on every plugin once, in order. Called from Quiote::bootstrap()
     * after settings load. Idempotent — safe if bootstrap runs more than once.
     */
    public static function bootFromConfig(): void
    {
        if (self::$registered) {
            return;
        }

        $configured = Config::getArray('plugins', []);
        foreach ($configured as $pluginClass) {
            if (is_string($pluginClass) || $pluginClass instanceof PluginInterface) {
                self::add($pluginClass);
            }
        }

        foreach (self::$plugins as $plugin) {
            $name = self::resolveName($plugin);
            try {
                $plugin->register(new PluginRegistrar($name));
            } catch (\Throwable $e) {
                Log::for(self::class)->error(
                    '[PluginManager] plugin "' . $name . '" (' . $plugin::class . ') register() threw: '
                    . $e::class . ': ' . $e->getMessage()
                );
                throw $e;
            }
        }
        self::$registered = true;
    }

    /**
     * A plugin's diagnostics/logging name: {@see NamedPlugin::name()} if the
     * plugin implements it (needed when the name can't be a compile-time
     * constant), otherwise {@see PluginAttribute}'s `name` argument.
     */
    private static function resolveName(PluginInterface $plugin): string
    {
        if ($plugin instanceof NamedPlugin) {
            return $plugin->name();
        }

        $attributes = (new \ReflectionClass($plugin))->getAttributes(PluginAttribute::class);
        $name = $attributes === [] ? null : $attributes[0]->newInstance()->name;
        if ($name === null) {
            throw new \Quiote\Exception\QuioteException(sprintf(
                'Plugin "%s" has no resolvable name: it does not implement %s and either carries no #[%s] '
                . 'attribute or the attribute has no `name` argument.',
                $plugin::class,
                NamedPlugin::class,
                PluginAttribute::class,
            ));
        }
        return $name;
    }

    // --- deferred contribution stores (called by PluginRegistrar) -----------

    /**
     * Records a directory a plugin contributes as a module search root.
     *
     * De-duplicated on the exact string, so re-registering the same directory is a
     * no-op. The contribution is stored statically and applied later by whoever
     * reads {@see moduleDirectories()}, not at the moment of the call.
     */
    public static function addModuleDirectory(string $dir): void
    {
        if (!in_array($dir, self::$moduleDirs, true)) {
            self::$moduleDirs[] = $dir;
        }
    }

    /** @return list<string> */
    public static function moduleDirectories(): array
    {
        return self::$moduleDirs;
    }

    /**
     * Records a console command class a plugin contributes to the CLI application.
     *
     * De-duplicated on the class name, so registering the same command twice adds it
     * once. The class is not loaded or instantiated here; it is handed over when the
     * console application reads {@see contributedCommands()}.
     */
    public static function addCommand(string $fqcn): void
    {
        if (!in_array($fqcn, self::$commands, true)) {
            self::$commands[] = $fqcn;
        }
    }

    /** @return list<string> */
    public static function contributedCommands(): array
    {
        return self::$commands;
    }

    /** @param list<string> $aliases */
    public static function addContainerService(string $id, mixed $concrete, ?string $scope, array $aliases): void
    {
        self::$containerServices[] = ['id' => $id, 'concrete' => $concrete, 'scope' => $scope, 'aliases' => $aliases];
    }

    /**
     * Records a configurator for a named HTTP client, keyed by $name.
     *
     * Registering the same name twice replaces the earlier configurator rather than
     * stacking. The configurator is only invoked when
     * {@see configureHttpClients()} applies it to a factory, and only for a name the
     * factory has not already configured.
     */
    public static function addHttpClientConfig(string $name, callable $configurator): void
    {
        self::$httpClientConfigs[$name] = $configurator;
    }

    /**
     * Contribute a clear that runs when a request on any context ends.
     *
     * For a plugin holding request-scoped state of its own -- a per-request cache, a memo keyed on
     * the current user. Without this there is no way to hook the boundary, and such state survives
     * into the next request served by the process.
     *
     * Keyed by $label, so registering the same label twice replaces rather than clearing twice.
     *
     * @param      \Closure(): void $clear
     * @since      4.0.0
     */
    public static function addRequestEndClear(string $label, \Closure $clear): void
    {
        self::$requestEndClears[$label] = $clear;
    }

    /**
     * Contribute a callback that clears a plugin-owned static registry, run as part of
     * {@see reset()}.
     *
     * For a driver registry that accumulates aliases across possibly-disjoint plugin instances
     * (e.g. a cloud filesystem package's own plugin calling its driver registry directly, the way
     * {@see PluginInterface}'s own docblock says a plugin-owned registry should): without this,
     * PluginManager would need to import and call every such registry by name itself, coupling
     * core to every optional subsystem that happens to keep one.
     *
     * Keyed by $label, so registering the same label twice replaces rather than clearing twice.
     *
     * @param      \Closure(): void $reset
     */
    public static function addStateReset(string $label, \Closure $reset): void
    {
        self::$stateResets[$label] = $reset;
    }

    // --- application phases -------------------------------------------------

    /**
     * Apply deferred DI-service contributions to a container, register-if-absent
     * so app/core bindings (and the first contributing plugin) win. Safe to call
     * repeatedly for the same container (idempotent).
     */
    public static function configureContainer(Container $container): void
    {
        foreach (self::$containerServices as $service) {
            if (!$container->has($service['id'])) {
                $container->set($service['id'], $service['concrete'], $service['scope']);
            }
            foreach ($service['aliases'] as $alias) {
                if (!$container->has($alias)) {
                    $container->alias($alias, $service['id']);
                }
            }
        }
    }

    /** Apply named-HTTP-client contributions to a factory (does not overwrite an already-configured name). */
    public static function configureHttpClients(HttpClientFactory $factory): void
    {
        foreach (self::$httpClientConfigs as $name => $configurator) {
            if (!$factory->has($name) || $name === HttpClientFactory::DEFAULT) {
                $factory->configure($name, $configurator);
            }
        }
    }

    /**
     * Append plugin-contributed clears to a context's lifecycle, after the framework's own -- the
     * identity clears must not be displaced by a plugin.
     *
     * @since      4.0.0
     */
    public static function configureLifecycle(\Quiote\ContextLifecycle $lifecycle): void
    {
        foreach (self::$requestEndClears as $label => $clear) {
            $lifecycle->onRequestEnd($label, $clear);
        }
    }

    /** @return array<class-string, PluginInterface> */
    public static function registeredPlugins(): array
    {
        return self::$plugins;
    }

    /**
     * Reports whether plugin registration has already run in this process.
     *
     * The flag guards registration against running twice per worker; {@see reset()}
     * clears it along with the contributions.
     */
    public static function isBooted(): bool
    {
        return self::$registered;
    }

    /**
     * Test isolation: clears every plugin + contribution and the booted flag.
     *
     * Middleware contributions are cleared with the rest. They live in their own
     * registries rather than in this class, and leaving them behind produced a
     * half-registered plugin: the pipeline still advertised the plugin's
     * middleware while the container had lost the service that middleware's
     * factory resolves, so the next dispatch died on a missing service rather
     * than simply running without the plugin.
     */
    public static function reset(): void
    {
        foreach (self::$stateResets as $reset) {
            $reset();
        }

        self::$plugins = [];
        self::$registered = false;
        self::$moduleDirs = [];
        self::$commands = [];
        self::$containerServices = [];
        self::$httpClientConfigs = [];
        self::$requestEndClears = [];
        self::$stateResets = [];
        \Quiote\Middleware\MiddlewareCatalog::reset();
        \Quiote\Middleware\Config\MiddlewareConfigRegistry::reset();
        \Quiote\Database\DatabaseDriverRegistry::reset();
        \Quiote\Exception\Rendering\ExceptionRendererRegistry::reset();
        \Quiote\Runtime\Worker\WorkerRuntimeRegistry::reset();
        \Quiote\Runtime\Worker\WorkerRuntimeInfo::reset();
    }

    /**
     * Turns a plugin class-string (from `plugins.*` or a string passed to
     * {@see add()}) into an instance -- the one path that requires the class
     * to carry {@see PluginAttribute}, since the string could originate from
     * a config file rather than code the caller wrote directly. An
     * already-constructed instance passed to {@see add()} skips this check
     * entirely (see that attribute's own docblock for why).
     */
    private static function instantiate(string $class): ?PluginInterface
    {
        if (!class_exists($class) || !is_subclass_of($class, PluginInterface::class)) {
            Log::for(self::class)->error('[PluginManager] configured plugin "' . $class . '" is not a ' . PluginInterface::class);
            return null;
        }
        if (!(new \ReflectionClass($class))->getAttributes(PluginAttribute::class)) {
            Log::for(self::class)->error(
                '[PluginManager] configured plugin "' . $class . '" does not carry #[' . PluginAttribute::class . '] '
                . '-- a class-string activation source (plugins.* or an add() call) requires the class to have '
                . 'deliberately opted in with this attribute; refusing to register it.'
            );
            return null;
        }
        return new $class();
    }
}
