<?php

namespace Quiote\ExceptionNotifier;

use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Event\Lifecycle\ExceptionCaughtEvent;
use Quiote\ExceptionNotifier\Channel\TeamsNotifierChannel;
use Quiote\ExceptionNotifier\Channel\WebhookNotifierChannel;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Support\Clock\ClockInterface;

/**
 * Registers the exception-notification subsystem: `exception_notifier.*`
 * config defaults, the built-in `teams`/`webhook` channel driver aliases, the
 * {@see ExceptionNotificationThrottle} singleton, and the
 * {@see ExceptionNotificationListener} that fans a notification out on
 * {@see ExceptionCaughtEvent}. Opt-in — `exception_notifier.enabled` defaults
 * to false, unlike security headers: sending exception details to a channel
 * an app hasn't configured is not a safe default.
 */
#[PluginAttribute(name: 'quiote/exception-notifier')]
final class ExceptionNotifierPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('exception_notifier.enabled', false);
        $registrar->configDefault('exception_notifier.min_status', 500);
        $registrar->configDefault('exception_notifier.throttle_seconds', 60);
        $registrar->configDefault('exception_notifier.ignore', []);
        $registrar->configDefault('exception_notifier.channels', []);

        ExceptionNotifierChannelRegistry::register('teams', TeamsNotifierChannel::class);
        ExceptionNotifierChannelRegistry::register('webhook', WebhookNotifierChannel::class);
        $registrar->stateReset('exception-notifier-channel-registry', static fn() => ExceptionNotifierChannelRegistry::reset());

        $registrar->service(
            ExceptionNotificationThrottle::class,
            static fn(Container $container) => new ExceptionNotificationThrottle(
                $container->get(ClockInterface::class),
                Config::getInt('exception_notifier.throttle_seconds', 60),
            ),
            Container::SCOPE_SINGLETON,
        );

        $registrar->listen(ExceptionCaughtEvent::class, new ExceptionNotificationListener());
    }
}
