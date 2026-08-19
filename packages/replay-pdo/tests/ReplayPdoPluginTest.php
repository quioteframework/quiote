<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\Pdo\PdoCassetteStore;
use Quiote\Replay\Store\Pdo\ReplayPdoPlugin;

/**
 * `ReplayPdoPlugin::register()` -- config defaults and the `pdo` store
 * alias. Does not exercise the `CassetteStoreInterface` service factory
 * itself (it needs a real, `initialize()`d `DatabaseManager` reachable via
 * `Context::getInstance()`) -- see `PdoCassetteStoreTest` for the PDO-level
 * behavior instead, matching `quioteframework/queue-db`'s own
 * `QueueDbPluginTest` precedent exactly.
 */
final class ReplayPdoPluginTest extends TestCase
{
    protected function setUp(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
        Config::remove('replay.store.pdo.connection');
        Config::remove('replay.store.pdo.table');
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        CassetteStoreRegistry::reset();
        Config::remove('replay.store.pdo.connection');
        Config::remove('replay.store.pdo.table');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new ReplayPdoPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('main', Config::getString('replay.store.pdo.connection'));
        $this->assertSame('quiote_cassettes', Config::getString('replay.store.pdo.table'));
    }

    public function testRegistersThePdoStoreAlias(): void
    {
        PluginManager::add(new ReplayPdoPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(CassetteStoreRegistry::has('pdo'));
        $this->assertSame(PdoCassetteStore::class, CassetteStoreRegistry::resolve('pdo'));
    }

    public function testStateResetClearsTheStoreRegistry(): void
    {
        PluginManager::add(new ReplayPdoPlugin());
        PluginManager::bootFromConfig();

        PluginManager::reset();

        $this->assertFalse(CassetteStoreRegistry::has('pdo'));
    }
}
