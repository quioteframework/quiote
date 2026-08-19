<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Adapter\Propulsion\PropulsionEffectSource;
use Quiote\Replay\Adapter\Propulsion\PropulsionQueryRecorder;
use Quiote\Replay\Adapter\Propulsion\ReplayPropulsionPlugin;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\ReplayPlugin;

/**
 * `ReplayPropulsionPlugin::register()` -- proves the Propulsion-specific
 * wiring (query observer, {@see PropulsionEffectSource} registration, state
 * reset) independently of `quioteframework/replay`'s own, now Propulsion-free
 * `ReplayPluginTest`.
 */
final class ReplayPropulsionPluginTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Propulsion::class)) {
            $this->markTestSkipped('quioteframework/propulsion not installed');
        }
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        EffectSourceRegistry::reset();
        Config::remove('replay.redact.params');
        Config::remove('replay.redact.mode');
        Propulsion::clearQueryObservers();
    }

    public function testRegistersAPropulsionQueryRecorderOnPropulsion(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayPropulsionPlugin());
        PluginManager::bootFromConfig();

        $observers = Propulsion::getQueryObservers();
        $registered = new ReflectionProperty($observers, 'observers');
        $list = $registered->getValue($observers);
        $this->assertIsArray($list);
        $this->assertCount(1, array_filter($list, static fn($o) => $o instanceof PropulsionQueryRecorder));
    }

    public function testRegistersAPropulsionEffectSource(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayPropulsionPlugin());
        PluginManager::bootFromConfig();

        $sources = EffectSourceRegistry::all();
        $this->assertCount(1, array_filter($sources, static fn($s) => $s instanceof PropulsionEffectSource));
    }

    public function testStateResetClearsPropulsionsQueryObservers(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::add(new ReplayPropulsionPlugin());
        PluginManager::bootFromConfig();

        PluginManager::reset();

        $observers = Propulsion::getQueryObservers();
        $registered = new ReflectionProperty($observers, 'observers');
        $this->assertSame([], $registered->getValue($observers));
    }
}
