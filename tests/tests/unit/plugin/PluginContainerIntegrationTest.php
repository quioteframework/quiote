<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginManager;
use Quiote\Plugin\PluginRegistrar;

/**
 * End-to-end wiring: a real Context's container exposes the HttpClientFactory
 * singleton, applies plugin-contributed named HTTP clients, and applies plugin
 * DI-service contributions (register-if-absent). Run in separate processes —
 * these touch the process-global PluginManager + Context registry.
 */
class PluginContainerIntegrationTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        PluginManager::reset();
    }

    #[RunInSeparateProcess]
    public function testContainerExposesHttpClientFactorySingleton(): void
    {
        $container = Context::getInstance('test')->getContainer();

        $factory = $container->get(HttpClientFactory::class);
        $this->assertInstanceOf(HttpClientFactory::class, $factory);
        // Singleton: same instance, and the 'http_client_factory' alias resolves to it.
        $this->assertSame($factory, $container->get(HttpClientFactory::class));
        $this->assertSame($factory, $container->get('http_client_factory'));
    }

    #[RunInSeparateProcess]
    public function testPluginHttpClientConfigReachesTheContainerFactory(): void
    {
        PluginManager::add(new ContainerDemoPlugin());
        PluginManager::bootFromConfig();

        // Fresh context in this isolated process picks up the plugin contribution.
        $container = Context::getInstance('test')->getContainer();
        $factory = $container->get(HttpClientFactory::class);
        $this->assertInstanceOf(HttpClientFactory::class, $factory);

        $this->assertTrue($factory->has('demo-api'));
        $client = $factory->client('demo-api');
        $this->assertInstanceOf(\Quiote\Http\Client\HttpClient::class, $client);
    }

    #[RunInSeparateProcess]
    public function testPluginServiceContributionResolvesFromContainer(): void
    {
        PluginManager::add(new ContainerDemoPlugin());
        PluginManager::bootFromConfig();

        $container = Context::getInstance('test')->getContainer();

        $this->assertTrue($container->has('demo.container.service'));
        $this->assertInstanceOf(\stdClass::class, $container->get('demo.container.service'));
    }
}

#[PluginAttribute(name: 'container-demo')]
final class ContainerDemoPlugin implements PluginInterface
{
    public function register(PluginRegistrar $r): void
    {
        $r->service('demo.container.service', fn() => new \stdClass(), Container::SCOPE_SINGLETON)
            ->httpClient('demo-api', fn($c) => $c->baseUri('https://demo.example'));
    }
}
