<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Console\CassetteListCommand;
use Quiote\Replay\Console\CassettePruneCommand;
use Quiote\Replay\Console\CassetteShowCommand;
use Quiote\Replay\Console\ReplayCommand;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Store\UnavailableCassetteStore;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;

/**
 * `ReplayPlugin::register()` -- config defaults, the cassette store service,
 * the recorder middleware registration and the two console commands.
 */
final class ReplayPluginTest extends TestCase
{
    /** @var list<string> */
    private const REPLAY_KEYS = [
        'replay.enabled', 'replay.record', 'replay.sample_rate', 'replay.trigger_header',
        'replay.store', 'replay.store.path', 'replay.tests_path', 'replay.retention_days',
        'replay.local_path', 'replay.max_bytes', 'replay.max_effects',
        'replay.capture_body', 'replay.capture_session',
        'replay.redact.headers', 'replay.redact.params', 'replay.redact.session', 'replay.redact.mode',
        'replay.redact.env', 'replay.redact.hash_salt',
        'replay.allow_live', 'replay.tests_allow_live',
    ];

    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        foreach (self::REPLAY_KEYS as $key) {
            Config::remove($key);
        }
        \Quiote\Replay\Recording\EffectSourceRegistry::reset();
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $this->assertFalse(Config::get('replay.enabled'));
        $this->assertSame('never', Config::getString('replay.record'));
        $this->assertSame('file', Config::getString('replay.store'));
        $this->assertSame('var/cassettes', Config::getString('replay.store.path'));
        $this->assertSame('tests/Replay', Config::getString('replay.tests_path'));
        $this->assertSame(14, Config::getInt('replay.retention_days'));
        $this->assertSame(2_097_152, Config::getInt('replay.max_bytes'));
        $this->assertSame(2000, Config::getInt('replay.max_effects'));
        $this->assertTrue(Config::getBool('replay.capture_body'));
        $this->assertTrue(Config::getBool('replay.capture_session'));
        $this->assertSame('drop', Config::getString('replay.redact.mode'));
        $this->assertFalse(Config::getBool('replay.allow_live'));
    }

    public function testRegistersTheRecorderMiddlewareAsAnAttributedCandidate(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(RecorderMiddleware::class, MiddlewareCatalog::getAttributedCandidates());
        $this->assertNotNull(MiddlewareCatalog::attributedFactory(RecorderMiddleware::class));
    }

    public function testRegistersAllFourConsoleCommands(): void
    {
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(CassetteListCommand::class, PluginManager::contributedCommands());
        $this->assertContains(CassetteShowCommand::class, PluginManager::contributedCommands());
        $this->assertContains(CassettePruneCommand::class, PluginManager::contributedCommands());
        $this->assertContains(ReplayCommand::class, PluginManager::contributedCommands());
    }

    public function testCassetteStoreServiceResolvesToTheFileStoreByDefault(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-replay-plugin-' . bin2hex(random_bytes(6));
        Config::set('replay.store.path', $path, true, false);
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        try {
            $this->assertInstanceOf(FileCassetteStore::class, $container->get(CassetteStoreInterface::class));
        } finally {
            @rmdir($path);
        }
    }

    public function testUnrecognisedStoreAliasThrows(): void
    {
        Config::set('replay.store', 'not-a-real-store', true, false);
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->expectException(RuntimeException::class);
        $container->get(CassetteStoreInterface::class);
    }

    public function testRecorderMiddlewareFactoryBuildsAnUnavailableStoreInsteadOfThrowingWhenTheStoreCannotBeBuilt(): void
    {
        Config::set('replay.store', 'not-a-real-store', true, false);
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClockInterface::class, new SystemClock());
        $container->set(RandomnessInterface::class, new SystemRandomness());
        PluginManager::configureContainer($container);

        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $factory = MiddlewareCatalog::attributedFactory(RecorderMiddleware::class);
        $this->assertNotNull($factory);

        // The factory itself must not throw: a misconfigured store must not abort pipeline
        // construction for every request. Resolving the interface directly (as the console
        // commands do) still throws hard -- see testUnrecognisedStoreAliasThrows.
        $middleware = $factory($context);
        $this->assertInstanceOf(RecorderMiddleware::class, $middleware);
    }

    public function testRecorderMiddlewareFactoryBuildsAWorkingInstance(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-replay-plugin-' . bin2hex(random_bytes(6));
        Config::set('replay.store.path', $path, true, false);
        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClockInterface::class, new SystemClock());
        $container->set(RandomnessInterface::class, new SystemRandomness());
        PluginManager::configureContainer($container);

        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $factory = MiddlewareCatalog::attributedFactory(RecorderMiddleware::class);
        $this->assertNotNull($factory);

        try {
            $middleware = $factory($context);
            $this->assertInstanceOf(RecorderMiddleware::class, $middleware);
        } finally {
            @rmdir($path);
        }
    }
}
