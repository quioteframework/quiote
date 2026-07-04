<?php

namespace Quiote\Plugin;

use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Event\Events;
use Quiote\Middleware\MiddlewareCatalog;

/**
 * The fluent contribution API handed to {@see PluginInterface::register()}.
 *
 * Every method routes to a seam that already exists in the framework — this
 * class adds no new low-level mechanism, it just gives plugins one coherent,
 * discoverable surface for the contribution kinds core itself knows about.
 * A plugin package that owns its own registry (e.g. MCP's {@see
 * \Quiote\Mcp\McpCatalog}) is registered by calling that registry directly
 * from {@see PluginInterface::register()} instead of gaining a bespoke method
 * here — this class must not grow a method per plugin package, or every
 * package would force a core release to gain a contribution seam. Contributions
 * to *static* seams (config, middleware, events) are applied immediately;
 * contributions that need a per-{@see \Quiote\Context} object (DI services,
 * named HTTP clients) are recorded on {@see PluginManager} and applied when
 * that object is built. Route/command contributions are recorded and consulted
 * by the route scanner / console.
 *
 * Override rules: config defaults and container services are *set-if-absent*,
 * so app settings/bindings (loaded before plugins) always win, and among
 * plugins the first to contribute a given key/id wins.
 */
final class PluginRegistrar
{
    public function __construct(private readonly string $pluginName) {}

    public function pluginName(): string
    {
        return $this->pluginName;
    }

    /** A config default (set-if-absent: app `settings.*` and earlier plugins win). */
    public function configDefault(string $key, mixed $value): self
    {
        Config::set($key, $value, overwrite: false);
        return $this;
    }

    /**
     * A DI service default, applied to each context's container when built, and
     * only if that id isn't already bound (app/core win; first plugin wins).
     * $concrete is anything {@see Container::set()} accepts (instance, class-string,
     * or factory closure). Extra $aliases are bound to $id if not already present.
     */
    public function service(string $id, mixed $concrete, string $scope = Container::SCOPE_SINGLETON, string ...$aliases): self
    {
        PluginManager::addContainerService($id, $concrete, $scope, $aliases);
        return $this;
    }

    /**
     * Insert a middleware at a position (routes to {@see MiddlewareCatalog::register()}).
     * $factory is called with the building pipeline's {@see \Quiote\Context} as its
     * argument (ignore it if unneeded — e.g. a single-context feature like MCP
     * captures a fixed context name instead; see `Quiote\Mcp\McpPlugin`).
     */
    public function middleware(string $fqcn, callable $factory, ?string $after = null, ?string $before = null, int $priority = 0): self
    {
        MiddlewareCatalog::register($fqcn, $factory, $after, $before, $priority);
        return $this;
    }

    /**
     * Add an app/plugin middleware class to `#[Middleware]` attribute scanning
     * — ordering comes from the class's own attribute. By default it's built
     * by the DI container (`$container->get($fqcn)`); pass `$factory` when the
     * class needs the building pipeline's {@see \Quiote\Context} itself (e.g.
     * to pull that context's own `Controller` instance rather than risk the
     * container autowiring an unrelated one) — see
     * {@see MiddlewareCatalog::registerAttributed()}.
     *
     * @param ?callable(\Quiote\Context): \Psr\Http\Server\MiddlewareInterface $factory
     */
    public function attributedMiddleware(string $fqcn, ?callable $factory = null): self
    {
        MiddlewareCatalog::registerAttributed($fqcn, $factory);
        return $this;
    }

    /** Register an event listener (routes to {@see Events::listen()}). */
    public function listen(string $eventClass, callable $listener, int $priority = 0): self
    {
        Events::listen($eventClass, $listener, $priority);
        return $this;
    }

    /**
     * Contribute a module directory. Its `#[Route]` action classes are then
     * discovered by the attribute route scanner alongside the app's own modules.
     */
    public function moduleDirectory(string $dir): self
    {
        PluginManager::addModuleDirectory($dir);
        return $this;
    }

    /** Contribute a console command class (see PluginManager::contributedCommands()). */
    public function command(string $fqcn): self
    {
        PluginManager::addCommand($fqcn);
        return $this;
    }

    /**
     * Register a database driver alias, so `databases.xml` can reference the
     * adapter by a short name (`class="eloquent"`) instead of a fully-qualified
     * class name. Routes to the static {@see \Quiote\Database\DatabaseDriverRegistry}
     * (applied immediately, like config/middleware/event contributions). The
     * alias must be a valid PHP label (no hyphens) to satisfy the databases.xsd
     * `class` attribute pattern — use e.g. `doctrine_dbal`, not `doctrine-dbal`.
     *
     * @param class-string<\Quiote\Database\Database> $adapterClass
     */
    public function databaseDriver(string $alias, string $adapterClass): self
    {
        \Quiote\Database\DatabaseDriverRegistry::register($alias, $adapterClass);
        return $this;
    }

    /**
     * Configure a named HTTP client (applied to the container's
     * {@see \Quiote\Http\Client\HttpClientFactory}). Same signature as
     * {@see \Quiote\Http\Client\HttpClientFactory::configure()}.
     */
    public function httpClient(string $name, callable $configurator): self
    {
        PluginManager::addHttpClientConfig($name, $configurator);
        return $this;
    }

    /**
     * Register the "developer" exception renderer used by
     * {@see \Quiote\Middleware\ErrorHandlingMiddleware} when
     * `core.developer_exceptions` is true. Routes to the static
     * {@see \Quiote\Exception\Rendering\ExceptionRendererRegistry} (applied
     * immediately, like {@see databaseDriver()}). Set-if-absent: first
     * registration wins.
     *
     * @param callable(): \Quiote\Exception\Rendering\ExceptionRenderer $factory
     */
    public function developerExceptionRenderer(callable $factory): self
    {
        \Quiote\Exception\Rendering\ExceptionRendererRegistry::setDeveloperRenderer($factory);
        return $this;
    }
}
