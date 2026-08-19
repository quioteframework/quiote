<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use RuntimeException;

/**
 * Process-global registry mapping short store aliases (`file`, `azure-blob`,
 * `s3`, `gcs`, `pdo`) to the {@see CassetteStoreInterface} class that
 * implements them, so `replay.store` can say `file` instead of a
 * fully-qualified class name. Mirrors
 * {@see \Quiote\Queue\QueueDriverRegistry}/{@see \Quiote\Database\DatabaseDriverRegistry}
 * exactly.
 *
 * Only `file` ships here. `azure-blob`/`s3`/`gcs`/`pdo` register their own
 * alias from their own plugin once those stores exist (per
 * `docs/RECORD_REPLAY_PLAN.md` §15 items 6/9), with zero change to this
 * class.
 */
final class CassetteStoreRegistry
{
    /** @var array<string, class-string<CassetteStoreInterface>> */
    private static array $aliases = [
        'file' => FileCassetteStore::class,
    ];

    private function __construct()
    {
    }

    /** @param class-string<CassetteStoreInterface> $storeClass */
    public static function register(string $alias, string $storeClass): void
    {
        self::$aliases[$alias] = $storeClass;
    }

    /** Whether $alias has been registered -- a fully-qualified class name is not an alias. */
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
     * Resolves an alias or class name to a loadable {@see CassetteStoreInterface}
     * implementation and returns its class name -- nothing is instantiated here.
     *
     * @throws RuntimeException if the resolved class does not exist, or exists but does not
     *         implement {@see CassetteStoreInterface}.
     */
    public static function instantiateClassFor(string $aliasOrClass): string
    {
        $class = self::resolve($aliasOrClass);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Cassette store "%s" resolves to class "%s", which does not exist.%s',
                $aliasOrClass,
                $class,
                self::has($aliasOrClass)
                    ? ' The registered store class is missing -- is its package installed?'
                    : ' No store alias by that name is registered; did you mean a fully-qualified class name, or is a plugin missing?'
            ));
        }

        if (!is_a($class, CassetteStoreInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Cassette store "%s" (class "%s") must implement %s.',
                $aliasOrClass,
                $class,
                CassetteStoreInterface::class,
            ));
        }

        return $class;
    }

    /** @return array<string, class-string<CassetteStoreInterface>> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /** Test isolation: restore the built-in aliases only. */
    public static function reset(): void
    {
        self::$aliases = ['file' => FileCassetteStore::class];
    }
}
