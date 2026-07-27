<?php

namespace Quiote\Security\RateLimit;

use Quiote\Context;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Registers {@see RateLimitMiddleware} through the generic plugin seam,
 * opt-in via `ratelimit.http.enabled`. Binds an {@see InMemoryStorage} as the
 * default {@see StorageInterface} (set-if-absent — an app can bind
 * {@see PdoRateLimiterStorage} instead for state that survives across
 * worker/process restarts and is shared between workers).
 */
#[PluginAttribute(name: 'quiote/ratelimit')]
final class RateLimitPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('ratelimit.http.enabled', false);
        $registrar->configDefault('ratelimit.http.max_requests', 60);
        $registrar->configDefault('ratelimit.http.window', '1 minute');
        $registrar->configDefault('ratelimit.http.policy', 'sliding_window');
        $registrar->configDefault('ratelimit.http.trust_forwarded_for', false);

        $registrar->service(StorageInterface::class, static fn() => new InMemoryStorage());

        $registrar->attributedMiddleware(
            RateLimitMiddleware::class,
            static function (Context $context): RateLimitMiddleware {
                $storage = $context->getContainer()->get(StorageInterface::class);
                if (!$storage instanceof StorageInterface) {
                    throw new \RuntimeException(sprintf(
                        'The "%s" service must resolve to a %s, got %s.',
                        StorageInterface::class,
                        StorageInterface::class,
                        get_debug_type($storage),
                    ));
                }
                return new RateLimitMiddleware($storage);
            },
        );
    }
}
