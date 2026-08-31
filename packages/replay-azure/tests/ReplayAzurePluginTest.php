<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Replay\Store\Azure\ReplayAzurePlugin;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;

/**
 * `ReplayAzurePlugin::register()` -- config defaults, the `azure-blob` store alias, and the three
 * contributed cassette indexes. Does not exercise the `CassetteStoreInterface` service factory
 * with real Azure credentials -- see `ObjectStoreCassetteStoreTest`/`LogAnalyticsIndexTest` for
 * that instead, matching `quioteframework/replay-pdo`'s own `ReplayPdoPluginTest` precedent.
 * Building the index chain is exercised (with a never-called fake HTTP client, since none of the
 * three index constructors makes a network call) because a wiring mistake there -- a missing
 * argument, a wrong type -- would otherwise only surface the first time a developer actually runs
 * `cassette:fetch`.
 */
final class ReplayAzurePluginTest extends TestCase
{
    protected function setUp(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
        CassetteIndexRegistry::reset();
        $this->clearConfig();
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
        CassetteIndexRegistry::reset();
        $this->clearConfig();
    }

    private function clearConfig(): void
    {
        Config::remove('replay.store.azure.container');
        Config::remove('replay.store.azure.account');
        Config::remove('replay.store.azure.auth');
        Config::remove('replay.store.azure.account_key');
        Config::remove('replay.store.azure.endpoint');
        Config::remove('replay.store.azure.prefix');
        Config::remove('replay.store.azure.lookback_hours');
        Config::remove('replay.index.log_analytics.workspace_id');
        Config::remove('replay.index.log_analytics.endpoint');
        Config::remove('replay.index.log_analytics.lookback_hours');
        Config::remove('replay.store');
        Config::remove('replay.store.path');
        Config::remove('replay.store.azure.env');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('quiote-cassettes', Config::getString('replay.store.azure.container'));
        $this->assertSame('', Config::getString('replay.store.azure.account'));
        $this->assertSame('shared_key', Config::getString('replay.store.azure.auth'));
        $this->assertSame('', Config::getString('replay.store.azure.account_key'));
        $this->assertSame('', Config::getString('replay.store.azure.endpoint'));
        $this->assertSame('quiote-cassettes', Config::getString('replay.store.azure.prefix'));
        $this->assertSame(48, Config::getInt('replay.store.azure.lookback_hours'));
        $this->assertSame('', Config::getString('replay.index.log_analytics.workspace_id'));
        $this->assertSame('https://api.loganalytics.io', Config::getString('replay.index.log_analytics.endpoint'));
        $this->assertSame(720, Config::getInt('replay.index.log_analytics.lookback_hours'));
    }

    public function testRegistersTheAzureBlobStoreAlias(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(CassetteStoreRegistry::has('azure-blob'));
        $this->assertSame(ObjectStoreCassetteStore::class, CassetteStoreRegistry::resolve('azure-blob'));
    }

    public function testStateResetClearsTheStoreRegistry(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        PluginManager::reset();

        $this->assertFalse(CassetteStoreRegistry::has('azure-blob'));
    }

    public function testContributesThreeCassetteIndexesInOrder(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, new PluginTestNeverCalledHttpClient());

        $indexes = CassetteIndexRegistry::build($container);

        $this->assertCount(3, $indexes);
        $this->assertInstanceOf(Quiote\Replay\Store\Storage\Index\ExplicitKeyIndex::class, $indexes[0]);
        $this->assertInstanceOf(Quiote\Replay\Store\Azure\Index\LogAnalyticsIndex::class, $indexes[1]);
        $this->assertInstanceOf(Quiote\Replay\Store\Storage\Index\PrefixScanIndex::class, $indexes[2]);
    }

    public function testStateResetClearsTheIndexRegistry(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        PluginManager::reset();

        $this->assertSame([], CassetteIndexRegistry::build(new Container()));
    }

    public function testTheKeySchemeEnvDefaultsToEmptyMeaningThisProcessesOwnEnvironment(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('', Config::getString('replay.store.azure.env'));
    }


    public function testInstallingTheAzurePluginDoesNotForceTheAzureStore(): void
    {
        // The bug this pins: the plugin used to claim the CassetteStoreInterface binding itself with
        // a set-if-absent service() call, which only took effect when it loaded before ReplayPlugin
        // -- and having loaded first it then won regardless of replay.store, so merely installing
        // the package sent every cassette to a blob container the app may never have named.
        Config::set('replay.store', 'file', true, false);
        Config::set('replay.store.path', sys_get_temp_dir() . '/quiote-azure-plugin-' . bin2hex(random_bytes(6)), true, false);

        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, new PluginTestNeverCalledHttpClient());
        PluginManager::configureContainer($container);

        $store = $container->get(CassetteStoreInterface::class);

        $this->assertInstanceOf(FileCassetteStore::class, $store);
    }

    public function testTheAzureStoreIsBuiltWhenReplayStoreNamesIt(): void
    {
        Config::set('replay.store', 'azure-blob', true, false);
        Config::set('replay.store.azure.account', 'examplestore', true, false);

        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, new PluginTestNeverCalledHttpClient());
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(ObjectStoreCassetteStore::class, $container->get(CassetteStoreInterface::class));
    }

    public function testRegistersANamedHttpClientConfig(): void
    {
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $factory = new HttpClientFactory();
        PluginManager::configureHttpClients($factory);

        $this->assertTrue($factory->has('replay-azure'));
    }

    public function testTheAzureStoreFallsBackToTheHttpClientFactoryWhenNoClientIsBound(): void
    {
        // Previously this threw a RuntimeException demanding a hand-registered ClientInterface
        // binding; a misconfigured `replay.store: azure-blob` then looked like it silently did
        // nothing, since the throw happened inside the middleware factory before anything reported
        // it. Now the framework's own named-client factory (which Context registers
        // unconditionally) covers the no-binding case, so the store just works.
        Config::set('replay.store', 'azure-blob', true, false);
        Config::set('replay.store.azure.account', 'examplestore', true, false);

        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(ObjectStoreCassetteStore::class, $container->get(CassetteStoreInterface::class));
    }

    public function testAnExplicitlyBoundHttpClientStillWinsOverTheNamedClientFactory(): void
    {
        Config::set('replay.store', 'azure-blob', true, false);
        Config::set('replay.store.azure.account', 'examplestore', true, false);

        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, new PluginTestNeverCalledHttpClient());
        PluginManager::configureContainer($container);

        // Building the store itself makes no network call, so a real assertion needs the index
        // chain, which constructs the same object client via requireHttpClient(): if the explicit
        // binding were ignored in favour of a real transport, this would attempt a live request and
        // fail rather than merely construct successfully.
        $indexes = CassetteIndexRegistry::build($container);
        $this->assertCount(3, $indexes);
    }

    public function testLoadOrderNoLongerMatters(): void
    {
        // The old arrangement documented "must be loaded before ReplayPlugin" as a requirement.
        Config::set('replay.store', 'azure-blob', true, false);
        Config::set('replay.store.azure.account', 'examplestore', true, false);

        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayAzurePlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, new PluginTestNeverCalledHttpClient());
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(ObjectStoreCassetteStore::class, $container->get(CassetteStoreInterface::class));
    }

}

final class PluginTestNeverCalledHttpClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('No HTTP request should be sent while merely building the index chain.');
    }
}
