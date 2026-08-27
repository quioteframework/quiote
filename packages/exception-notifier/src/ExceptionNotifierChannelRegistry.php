<?php

namespace Quiote\ExceptionNotifier;

use Quiote\Http\Client\HttpClientFactory;
use RuntimeException;

/**
 * Process-global registry mapping short channel driver aliases (e.g. "teams",
 * "webhook") to the class implementing both {@see NotifierChannelInterface}
 * and {@see NotifierChannelFactoryInterface}, so `exception_notifier.channels`
 * config can say `driver: teams` instead of a fully-qualified class name.
 * Mirrors {@see \Quiote\Database\DatabaseDriverRegistry} and
 * {@see \Quiote\Queue\QueueDriverRegistry}.
 *
 * Only `teams` and `webhook` ship in core. A third-party channel registers its
 * own alias from its own plugin's `register()` — no change here required.
 */
final class ExceptionNotifierChannelRegistry
{
    /** @var array<string, class-string> */
    private const array BUILT_IN = [
        'teams' => Channel\TeamsNotifierChannel::class,
        'webhook' => Channel\WebhookNotifierChannel::class,
    ];

    /** @var array<string, class-string> */
    private static array $aliases = self::BUILT_IN;

    private function __construct()
    {
    }

    /** @param class-string $channelClass must implement both {@see NotifierChannelInterface} and {@see NotifierChannelFactoryInterface} */
    public static function register(string $alias, string $channelClass): void
    {
        self::$aliases[$alias] = $channelClass;
    }

    /** Whether $alias has been registered. */
    public static function has(string $alias): bool
    {
        return isset(self::$aliases[$alias]);
    }

    /** @return array<string, class-string> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /**
     * Resolves $alias and builds a channel instance from one
     * `exception_notifier.channels` entry.
     *
     * @param array<string, mixed> $channelConfig
     * @throws RuntimeException if the alias is unregistered, or its class does not
     *         implement both {@see NotifierChannelInterface} and
     *         {@see NotifierChannelFactoryInterface}.
     */
    public static function instantiate(string $alias, array $channelConfig, HttpClientFactory $httpClientFactory): NotifierChannelInterface
    {
        $class = self::$aliases[$alias] ?? null;
        if ($class === null) {
            throw new RuntimeException(sprintf(
                'Exception notifier channel driver "%s" is not registered; did you mean a registered alias (%s), or is a plugin missing?',
                $alias,
                implode(', ', array_keys(self::$aliases)),
            ));
        }
        if (!is_a($class, NotifierChannelInterface::class, true) || !is_a($class, NotifierChannelFactoryInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Exception notifier channel driver "%s" (class "%s") must implement both %s and %s.',
                $alias,
                $class,
                NotifierChannelInterface::class,
                NotifierChannelFactoryInterface::class,
            ));
        }

        return $class::fromChannelConfig($channelConfig, $httpClientFactory);
    }

    /** Test isolation: restore the built-in aliases only. */
    public static function reset(): void
    {
        self::$aliases = self::BUILT_IN;
    }
}
