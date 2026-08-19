<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Store\Azure\ReplayAzurePlugin;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;

/**
 * `ReplayAzurePlugin::register()` -- config defaults and the `azure-blob`
 * store alias. Does not exercise the `CassetteStoreInterface` service
 * factory itself (it needs a real PSR-18 client bound in the container and
 * live Azure credentials) -- see `ObjectStoreCassetteStoreTest` for the
 * store's own behavior instead, matching `quioteframework/replay-pdo`'s
 * own `ReplayPdoPluginTest` precedent exactly.
 */
final class ReplayAzurePluginTest extends TestCase
{
    protected function setUp(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
        $this->clearConfig();
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
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
}
