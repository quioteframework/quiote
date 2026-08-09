<?php

namespace Quiote\Queue;

use RuntimeException;

/**
 * Process-global registry mapping short driver aliases (e.g. "sync", "db")
 * to the {@see QueueDriverInterface} class that implements them, so
 * `queue.default_driver`/`--driver` can say `db` instead of a fully-qualified
 * class name. Mirrors {@see \Quiote\Database\DatabaseDriverRegistry} exactly.
 *
 * Only `sync` ships in core. Persistent backends register their own alias
 * from their plugin (e.g. `quioteframework/queue-db`'s `QueueDbPlugin`).
 */
final class QueueDriverRegistry
{
    /** @var array<string, class-string<QueueDriverInterface>> */
    private static array $aliases = [
        'sync' => SyncQueueDriver::class,
    ];

    private function __construct()
    {
    }

    /** @param class-string<QueueDriverInterface> $driverClass */
    public static function register(string $alias, string $driverClass): void
    {
        self::$aliases[$alias] = $driverClass;
    }

    /**
     * Whether $alias has been registered.
     *
     * Only tests the alias table; a fully-qualified class name that
     * {@see resolve()} would happily pass through is not an alias and reports
     * false here.
     */
    public static function has(string $alias): bool
    {
        return isset(self::$aliases[$alias]);
    }

    /** A string that is not a registered alias is returned unchanged, so a fully-qualified class name passes through. */
    public static function resolve(string $aliasOrClass): string
    {
        return self::$aliases[$aliasOrClass] ?? $aliasOrClass;
    }

    /**
     * Resolves an alias or class name to a loadable {@see QueueDriverInterface}
     * implementation and returns its class name — nothing is instantiated here.
     *
     * @throws RuntimeException if the resolved class does not exist (the
     *         message distinguishes a registered alias whose package is missing
     *         from an unknown alias), or exists but does not implement
     *         {@see QueueDriverInterface}.
     */
    public static function instantiateClassFor(string $aliasOrClass): string
    {
        $class = self::resolve($aliasOrClass);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Queue driver "%s" resolves to class "%s", which does not exist.%s',
                $aliasOrClass,
                $class,
                self::has($aliasOrClass)
                    ? ' The registered driver class is missing — is its package installed?'
                    : ' No driver alias by that name is registered; did you mean a fully-qualified class name, or is a plugin missing?'
            ));
        }

        if (!is_a($class, QueueDriverInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Queue driver "%s" (class "%s") must implement %s.',
                $aliasOrClass,
                $class,
                QueueDriverInterface::class,
            ));
        }

        return $class;
    }

    /** @return array<string, class-string<QueueDriverInterface>> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /** Test isolation: restore the built-in aliases only. */
    public static function reset(): void
    {
        self::$aliases = ['sync' => SyncQueueDriver::class];
    }
}
