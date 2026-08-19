<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Adapter\Doctrine\DoctrineEffectSource;
use Quiote\Replay\Adapter\Doctrine\ReplayDoctrineDatabase;
use Quiote\Replay\Adapter\Doctrine\ReplayDoctrineDbalDatabase;
use Quiote\Replay\Adapter\Doctrine\ReplayDoctrinePlugin;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\ReplayPlugin;

/**
 * `ReplayDoctrinePlugin::register()` -- proves the Doctrine-specific wiring
 * (driver alias override, {@see DoctrineEffectSource} registration)
 * independently of `quioteframework/replay`'s own, ORM-free `ReplayPluginTest`.
 */
final class ReplayDoctrinePluginTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Doctrine\DBAL\DriverManager::class)) {
            $this->markTestSkipped('doctrine/dbal not installed');
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

    public function testOverridesTheDoctrineAndDoctrineDbalDriverAliases(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayDoctrinePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(ReplayDoctrineDatabase::class, DatabaseDriverRegistry::resolve('doctrine'));
        $this->assertSame(ReplayDoctrineDbalDatabase::class, DatabaseDriverRegistry::resolve('doctrine_dbal'));
    }

    public function testRegistersADoctrineEffectSource(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayDoctrinePlugin());
        PluginManager::bootFromConfig();

        $sources = EffectSourceRegistry::all();
        $this->assertCount(1, array_filter($sources, static fn($s) => $s instanceof DoctrineEffectSource));
    }
}
