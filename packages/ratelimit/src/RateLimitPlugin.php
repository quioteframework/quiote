<?php

namespace Quiote\Security\RateLimit;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Database\PdoDatabase;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Quiote\DI\Container;

/**
 * Registers {@see RateLimitMiddleware} through the generic plugin seam,
 * opt-in via `ratelimit.http.enabled`. Binds a default {@see StorageInterface}
 * (set-if-absent) selected by `ratelimit.storage`: `memory` (default,
 * {@see InMemoryStorage}), `redis` ({@see CacheStorage} wrapping a
 * {@see RedisAdapter} built from `ratelimit.redis.dsn`), or `pdo`
 * ({@see PdoRateLimiterStorage} on the `ratelimit.pdo.connection` database),
 * for state that survives across worker/process restarts without a Redis
 * dependency. An unrecognised value is an error rather than a fallback:
 * `memory` counts per process, so silently substituting it for a misspelled
 * backend multiplies the effective limit by the worker count.
 */
#[PluginAttribute(name: 'quiote/ratelimit')]
final class RateLimitPlugin implements PluginInterface
{
    /**
     * Registers the rate-limiting configuration defaults, the storage binding
     * and the middleware.
     *
     * `ratelimit.http.enabled` defaults to false, so installing the package
     * alone does not throttle anything. The `StorageInterface` binding is a
     * singleton because the in-memory backend counts per process — a
     * request-scoped one would reset every counter each request. The middleware
     * is registered with a factory that hands it that binding from the
     * context's own container.
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('ratelimit.http.enabled', false);
        $registrar->configDefault('ratelimit.http.max_requests', 60);
        $registrar->configDefault('ratelimit.http.window', '1 minute');
        $registrar->configDefault('ratelimit.http.policy', 'sliding_window');
        $registrar->configDefault('ratelimit.http.trust_forwarded_for', false);
        $registrar->configDefault('ratelimit.storage', 'memory');
        $registrar->configDefault('ratelimit.redis.dsn', 'redis://127.0.0.1:6379');
        $registrar->configDefault('ratelimit.pdo.connection', 'main');
        $registrar->configDefault('ratelimit.pdo.table', 'quiote_rate_limit');

        // Singleton, and this one is a security property rather than a cost: InMemoryStorage counts
        // per process, so a request-scoped binding would reset every counter on every request and the
        // throttle would stop throttling.
        $registrar->service(StorageInterface::class, static fn() => self::makeStorage(), Container::SCOPE_SINGLETON);

        $registrar->attributedMiddleware(
            RateLimitMiddleware::class,
            static function (Context $context): RateLimitMiddleware {
                // The container refuses a binding that is not an instance of the id asked for, so the
                // guard this used to carry here is held one level down now.
                return new RateLimitMiddleware($context->getContainer()->get(StorageInterface::class));
            },
        );
    }

    private static function makeStorage(): StorageInterface
    {
        $backend = Config::getString('ratelimit.storage', 'memory');

        if ($backend === 'memory') {
            return new InMemoryStorage();
        }

        if ($backend === 'pdo') {
            return new PdoRateLimiterStorage(
                self::resolvePdo(),
                Config::getString('ratelimit.pdo.table', 'quiote_rate_limit'),
            );
        }

        if ($backend !== 'redis') {
            // Never fall back to InMemoryStorage here. Its counters are
            // per-process, so on a typical multi-worker deployment each worker
            // counts to the limit independently and N times the configured rate
            // gets through — a silently disabled rate limiter, from a typo.
            throw new \RuntimeException(sprintf(
                'ratelimit.storage is "%s"; expected one of: memory, redis, pdo.',
                $backend,
            ));
        }

        if (!extension_loaded('redis') && !extension_loaded('relay') && !interface_exists(\Predis\ClientInterface::class)) {
            throw new \RuntimeException(
                'ratelimit.storage is "redis" but no Redis client is available. Install ext-redis, ext-relay, or predis/predis (e.g. `composer require predis/predis`).',
            );
        }

        $dsn = Config::getString('ratelimit.redis.dsn', 'redis://127.0.0.1:6379');
        $connection = RedisAdapter::createConnection($dsn);

        return new CacheStorage(new RedisAdapter($connection));
    }

    /**
     * The PDO handle behind `ratelimit.storage = "pdo"`, taken from the
     * connection named by `ratelimit.pdo.connection` on the current
     * {@see Context} — the app's already-initialized DatabaseManager, not a
     * container-autowired one, which would have no configured connections.
     * @return     \PDO The connection to store limiter state in.
     * @throws     \RuntimeException If there is no DatabaseManager, or the named connection is not PDO-backed.
     * @since      3.0.2
     */
    private static function resolvePdo(): \PDO
    {
        $connectionName = Config::getString('ratelimit.pdo.connection', 'main');
        $context = Context::getInstance(Config::getString('core.default_context', 'web'));
        $databaseManager = $context->getContainer()->tryGet(\Quiote\Database\DatabaseManager::class);
        if ($databaseManager === null) {
            throw new \RuntimeException(
                'ratelimit.storage is "pdo" but the current Context has no DatabaseManager; is databases.xml configured?',
            );
        }
        try {
            $database = $databaseManager->getDatabase($connectionName);
        } catch (\Throwable $e) {
            // getDatabase()'s own "Database "main" does not exist" says nothing
            // about where the name came from; name the config key here.
            throw new \RuntimeException(sprintf(
                'ratelimit.storage is "pdo" but database connection "%s" ("ratelimit.pdo.connection") is not available: %s',
                $connectionName,
                $e->getMessage(),
            ), 0, $e);
        }
        if (!$database instanceof PdoDatabase) {
            throw new \RuntimeException(sprintf(
                'ratelimit.storage is "pdo" but database connection "%s" ("ratelimit.pdo.connection") is %s, not a PDO-backed connection.',
                $connectionName,
                get_debug_type($database),
            ));
        }

        return $database->getPdo();
    }
}
