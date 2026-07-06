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

    /** @var list<array{id: string, concrete: mixed, scope: string, aliases: list<string>}> deferred DI services */
    private static array $containerServices = [];

    /** @var array<string, callable> named HTTP client configurators */
    private static array $httpClientConfigs = [];

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
            try {
                $plugin->register(new PluginRegistrar($plugin->name()));
            } catch (\Throwable $e) {
                Log::for(self::class)->error(
                    '[PluginManager] plugin "' . $plugin->name() . '" (' . $plugin::class . ') register() threw: '
                    . $e::class . ': ' . $e->getMessage()
                );
                throw $e;
            }
        }
        self::$registered = true;
    }

    // --- deferred contribution stores (called by PluginRegistrar) -----------

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
    public static function addContainerService(string $id, mixed $concrete, string $scope, array $aliases): void
    {
        self::$containerServices[] = ['id' => $id, 'concrete' => $concrete, 'scope' => $scope, 'aliases' => $aliases];
    }

    public static function addHttpClientConfig(string $name, callable $configurator): void
    {
        self::$httpClientConfigs[$name] = $configurator;
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

    /** @return array<class-string, PluginInterface> */
    public static function registeredPlugins(): array
    {
        return self::$plugins;
    }

    public static function isBooted(): bool
    {
        return self::$registered;
    }

    /** Test isolation: clears every plugin + contribution and the booted flag. */
    public static function reset(): void
    {
        self::$plugins = [];
        self::$registered = false;
        self::$moduleDirs = [];
        self::$commands = [];
        self::$containerServices = [];
        self::$httpClientConfigs = [];
        \Quiote\Database\DatabaseDriverRegistry::reset();
        \Quiote\Exception\Rendering\ExceptionRendererRegistry::reset();
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
