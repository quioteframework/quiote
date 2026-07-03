<?php

namespace Quiote\Plugin;

use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Event\Events;
use Quiote\Mcp\McpCatalog;
use Quiote\Middleware\MiddlewareCatalog;

/**
 * The fluent contribution API handed to {@see PluginInterface::register()}.
 *
 * Every method routes to a seam that already exists in the framework — this
 * class adds no new low-level mechanism, it just gives plugins one coherent,
 * discoverable surface for the seven contribution kinds. Contributions to
 * *static* seams (config, middleware, events) are applied immediately;
 * contributions that need a per-{@see \Quiote\Context} object (DI services,
 * named HTTP clients) are recorded on {@see PluginManager} and applied when
 * that object is built. Route/command contributions are recorded and consulted
 * by the route scanner / console.
 *
 * Override rules (see docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md): config defaults
 * and container services are *set-if-absent*, so app settings/bindings (loaded
 * before plugins) always win, and among plugins the first to contribute a given
 * key/id wins.
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

    /** Insert a middleware at a position (routes to {@see MiddlewareCatalog::register()}). */
    public function middleware(string $fqcn, callable $factory, ?string $after = null, ?string $before = null, int $priority = 0): self
    {
        MiddlewareCatalog::register($fqcn, $factory, $after, $before, $priority);
        return $this;
    }

    /** Add an app/plugin middleware class to `#[Middleware]` attribute scanning. */
    public function attributedMiddleware(string $fqcn): self
    {
        MiddlewareCatalog::registerAttributed($fqcn);
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
     * Register an MCP tool (routes to {@see McpCatalog::addTool()}), see
     * docs/MCP_SERVER_PLAN.md §6.2. $handlerFqcn is resolved through the DI
     * container at call time; $method defaults to `__invoke` when omitted.
     */
    public function mcpTool(
        string $handlerFqcn,
        ?string $method = null,
        ?string $name = null,
        ?string $description = null,
        ?array $inputSchema = null,
        ?array $outputSchema = null,
    ): self {
        McpCatalog::addTool(
            $method !== null ? [$handlerFqcn, $method] : $handlerFqcn,
            $name,
            null,
            $description,
            $inputSchema,
            $outputSchema,
        );
        return $this;
    }

    /** Register an MCP resource (routes to {@see McpCatalog::addResource()}). */
    public function mcpResource(
        string $handlerFqcn,
        string $uri,
        ?string $method = null,
        ?string $name = null,
        ?string $description = null,
        ?string $mimeType = null,
    ): self {
        McpCatalog::addResource(
            $method !== null ? [$handlerFqcn, $method] : $handlerFqcn,
            $uri,
            $name,
            null,
            $description,
            $mimeType,
        );
        return $this;
    }

    /** Register an MCP prompt (routes to {@see McpCatalog::addPrompt()}). */
    public function mcpPrompt(
        string $handlerFqcn,
        ?string $method = null,
        ?string $name = null,
        ?string $description = null,
    ): self {
        McpCatalog::addPrompt(
            $method !== null ? [$handlerFqcn, $method] : $handlerFqcn,
            $name,
            null,
            $description,
        );
        return $this;
    }
}
