<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Event\Events;
use Quiote\Event\Lifecycle\ExceptionCaughtEvent;
use Quiote\ExceptionNotifier\ExceptionNotificationThrottle;
use Quiote\ExceptionNotifier\ExceptionNotifierChannelRegistry;
use Quiote\ExceptionNotifier\ExceptionNotifierPlugin;
use Quiote\Plugin\PluginManager;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * ExceptionNotifierPlugin::register() -- config defaults, the built-in
 * channel driver aliases, the throttle service, and the event listener.
 * Mirrors QueuePluginTest's/QueueDbPluginTest's shape.
 */
final class ExceptionNotifierPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        Events::reset();
        ExceptionNotifierChannelRegistry::reset();
        Config::remove('exception_notifier.enabled');
        Config::remove('exception_notifier.min_status');
        Config::remove('exception_notifier.throttle_seconds');
        Config::remove('exception_notifier.ignore');
        Config::remove('exception_notifier.channels');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new ExceptionNotifierPlugin());
        PluginManager::bootFromConfig();

        $this->assertFalse(Config::getBool('exception_notifier.enabled'));
        $this->assertSame(500, Config::getInt('exception_notifier.min_status'));
        $this->assertSame(60, Config::getInt('exception_notifier.throttle_seconds'));
        $this->assertSame([], Config::getArray('exception_notifier.ignore'));
        $this->assertSame([], Config::getArray('exception_notifier.channels'));
    }

    public function testRegistersTheBuiltInChannelDrivers(): void
    {
        PluginManager::add(new ExceptionNotifierPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(ExceptionNotifierChannelRegistry::has('teams'));
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('webhook'));
    }

    public function testWiresTheThrottleIntoTheContainer(): void
    {
        PluginManager::add(new ExceptionNotifierPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        // ClockInterface is a core service normally bound by Context; a bare
        // Container here needs it supplied for the throttle factory to resolve.
        $container->set(ClockInterface::class, new SystemClock());
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(ExceptionNotificationThrottle::class, $container->get(ExceptionNotificationThrottle::class));
    }

    public function testRegistersAListenerForExceptionCaughtEvent(): void
    {
        PluginManager::add(new ExceptionNotifierPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(Events::hasListeners(ExceptionCaughtEvent::class));
    }

    public function testAnAppConfiguredMinStatusIsNotOverwritten(): void
    {
        Config::set('exception_notifier.min_status', 400);

        PluginManager::add(new ExceptionNotifierPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(400, Config::getInt('exception_notifier.min_status'));
    }
}
