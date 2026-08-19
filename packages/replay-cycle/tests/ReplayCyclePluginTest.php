<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Adapter\Cycle\CycleEffectSource;
use Quiote\Replay\Adapter\Cycle\ReplayCycleDatabase;
use Quiote\Replay\Adapter\Cycle\ReplayCyclePlugin;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\ReplayPlugin;

/**
 * `ReplayCyclePlugin::register()` -- proves the Cycle-specific wiring
 * (driver alias override, {@see CycleEffectSource} registration)
 * independently of `quioteframework/replay`'s own, ORM-free `ReplayPluginTest`.
 */
final class ReplayCyclePluginTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Cycle\Database\DatabaseManager::class)) {
            $this->markTestSkipped('cycle/database not installed');
        }
        DatabaseDriverRegistry::reset();
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        EffectSourceRegistry::reset();
        DatabaseDriverRegistry::reset();
        Config::remove('replay.redact.params');
        Config::remove('replay.redact.mode');
    }

    public function testOverridesTheCycleDriverAlias(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayCyclePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(ReplayCycleDatabase::class, DatabaseDriverRegistry::resolve('cycle'));
    }

    public function testRegistersACycleEffectSource(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayCyclePlugin());
        PluginManager::bootFromConfig();

        $sources = EffectSourceRegistry::all();
        $this->assertCount(1, array_filter($sources, static fn($s) => $s instanceof CycleEffectSource));
    }
}
