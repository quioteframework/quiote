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
 * Only `file` ships here. `azure-blob` and `pdo` register their own alias
 * from their own plugin (`quioteframework/replay-azure`,
 * `quioteframework/replay-pdo`), with zero change to this class; `s3`/`gcs`
 * would do the same from their own plugin once one exists.
 */
final class CassetteStoreRegistry
{
    /** @var array<string, class-string<CassetteStoreInterface>> */
    private static array $aliases = [
        'file' => FileCassetteStore::class,
    ];

    /**
     * How to build each alias's store, when its own package knows and this class cannot.
     *
     * A store package used to claim the `CassetteStoreInterface` binding itself, with a
     * `PluginRegistrar::service()` call in its own plugin. That is set-if-absent, so it only took
     * effect when the package loaded *before* `ReplayPlugin` -- which both `replay-azure` and
     * `replay-pdo` documented as a requirement -- and having loaded first it then won
     * unconditionally, whatever `replay.store` actually said. Installing `quioteframework/replay-azure`
     * to have the option therefore forced every cassette through Azure, and a plain
     * `replay.store = file` app got a blob client built for a container it never named.
     *
     * Registering a factory here instead inverts that: one binding, in `ReplayPlugin`, resolves
     * whichever alias `replay.store` names to the factory its package left behind. Load order stops
     * mattering, and a registered-but-unselected store is never constructed.
     *
     * @var array<string, \Closure(\Quiote\DI\Container): CassetteStoreInterface>
     */
    private static array $factories = [];

    private function __construct()
    {
    }

    /**
     * @param class-string<CassetteStoreInterface> $storeClass
     * @param (\Closure(\Quiote\DI\Container): CassetteStoreInterface)|null $factory How to build it.
     *        Required for any store `ReplayPlugin` does not know how to construct itself, which is
     *        every store but the built-in file one -- see {@see $factories}.
     */
    public static function register(string $alias, string $storeClass, ?\Closure $factory = null): void
    {
        self::$aliases[$alias] = $storeClass;
        if ($factory !== null) {
            self::$factories[$alias] = $factory;
        }
    }

    /**
     * The factory for $alias, or null when its package registered none.
     *
     * @return (\Closure(\Quiote\DI\Container): CassetteStoreInterface)|null
     */
    public static function factoryFor(string $alias): ?\Closure
    {
        return self::$factories[$alias] ?? null;
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
        self::$factories = [];
    }
}
