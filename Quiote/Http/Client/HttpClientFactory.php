<?php

namespace Quiote\Http\Client;

use Psr\Http\Client\ClientInterface;

/**
 * Registry + factory for named HTTP clients, modelled on .NET's
 * `services.AddHttpClient("name", c => ...)` / `IHttpClientFactory`: you
 * register a named client's configuration once, then resolve it by name, and
 * the same {@see HttpClient} instance is reused for that name for the lifetime
 * of the process (a FrankenPHP worker keeps one per name) rather than being
 * rebuilt on every call.
 *
 * Registered as a container singleton (see
 * {@see \Quiote\Context::registerCoreServicesInContainer()}), so app/plugin
 * code can constructor-inject `HttpClientFactory` and pull named clients — and
 * plugins contribute named-client configs via
 * {@see \Quiote\Plugin\PluginRegistrar::httpClient()}.
 */
final class HttpClientFactory
{
    public const DEFAULT = 'default';

    /** @var array<string, callable(HttpClientConfig): void> */
    private array $configurators = [];

    /** @var array<string, HttpClient> memoized instances, keyed by name */
    private array $instances = [];

    /** @var (callable(): ClientInterface)|null */
    private $defaultTransportFactory = null;

    /**
     * Register (or overwrite) a named client's configuration. The callback runs
     * lazily, once, the first time {@see client()} builds that name; changing a
     * configurator after its client has been built has no effect until
     * {@see reset()}.
     *
     * @param callable(HttpClientConfig): void $configurator
     */
    public function configure(string $name, callable $configurator): void
    {
        $this->configurators[$name] = $configurator;
        unset($this->instances[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->configurators[$name]) || $name === self::DEFAULT;
    }

    /** Resolve a named client, building + memoizing it on first use. */
    public function client(string $name = self::DEFAULT): HttpClient
    {
        return $this->instances[$name] ??= $this->build($name);
    }

    /** Override the transport used by clients that don't set their own (default: {@see TransportFactory::default()}). */
    public function setDefaultTransportFactory(?callable $factory): void
    {
        $this->defaultTransportFactory = $factory;
        $this->instances = [];
    }

    public function reset(): void
    {
        $this->configurators = [];
        $this->instances = [];
        $this->defaultTransportFactory = null;
    }

    private function build(string $name): HttpClient
    {
        $config = new HttpClientConfig();
        if ($this->defaultTransportFactory !== null) {
            $config->transport(($this->defaultTransportFactory)());
        }
        if (isset($this->configurators[$name])) {
            ($this->configurators[$name])($config);
        }
        return HttpClient::fromConfig($config);
    }
}
