<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Adapter\Eloquent\EloquentEffectSource;
use Quiote\Replay\Adapter\Eloquent\ReplayEloquentDatabase;
use Quiote\Replay\Adapter\Eloquent\ReplayEloquentPlugin;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\ReplayPlugin;

/**
 * `ReplayEloquentPlugin::register()` -- proves the Eloquent-specific wiring
 * (driver alias override, {@see EloquentEffectSource} registration)
 * independently of `quioteframework/replay`'s own, ORM-free `ReplayPluginTest`.
 */
final class ReplayEloquentPluginTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped('illuminate/database not installed');
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

    public function testOverridesTheEloquentDriverAlias(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayEloquentPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(ReplayEloquentDatabase::class, DatabaseDriverRegistry::resolve('eloquent'));
    }

    public function testRegistersAnEloquentEffectSource(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayEloquentPlugin());
        PluginManager::bootFromConfig();

        $sources = EffectSourceRegistry::all();
        $this->assertCount(1, array_filter($sources, static fn($s) => $s instanceof EloquentEffectSource));
    }
}
