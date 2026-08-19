<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Replay\Store\Azure\ReplayAzurePlugin;
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
}

final class PluginTestNeverCalledHttpClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('No HTTP request should be sent while merely building the index chain.');
    }
}
