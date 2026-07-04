<?php

namespace Quiote\Database;

use Quiote\Exception\DatabaseException;

/**
 * Process-global registry mapping short driver aliases (e.g. "eloquent",
 * "doctrine", "cycle") to the {@see Database} adapter class that implements them,
 * so `databases.xml` can say `class="eloquent"` instead of a fully-qualified
 * class name.
 *
 * This is a *static seam* in the same spirit as {@see \Quiote\Middleware\MiddlewareCatalog}
 * and {@see \Quiote\Event\Events}: plugins contribute aliases during
 * {@see \Quiote\Plugin\PluginManager::bootFromConfig()} (via
 * {@see \Quiote\Plugin\PluginRegistrar::databaseDriver()}), which runs before any
 * context — and therefore before {@see DatabaseConfigHandler} compiles a
 * `databases.xml` — so aliases are known by the time they're resolved.
 *
 * Only `pdo` ships in core (the one always-available driver). ORM adapters
 * register their own aliases from their plugin.
 */
final class DatabaseDriverRegistry
{
    /** @var array<string, class-string<Database>> */
    private static array $aliases = [
        'pdo' => PdoDatabase::class,
    ];

    private function __construct() {}

    /**
     * Register (or override) a driver alias. Last writer wins; plugins that want
     * set-if-absent semantics should check {@see has()} first, but in practice the
     * app's explicit FQCN in `databases.xml` always wins because it never consults
     * the registry (only bare aliases are resolved).
     *
     * @param class-string<Database> $adapterClass
     */
    public static function register(string $alias, string $adapterClass): void
    {
        self::$aliases[$alias] = $adapterClass;
    }

    public static function has(string $alias): bool
    {
        return isset(self::$aliases[$alias]);
    }

    /**
     * Resolve an alias to its adapter class. A string that is not a registered
     * alias is returned unchanged, so fully-qualified class names in
     * `databases.xml` pass straight through.
     */
    public static function resolve(string $classOrAlias): string
    {
        return self::$aliases[$classOrAlias] ?? $classOrAlias;
    }

    /**
     * Resolve and instantiate an adapter. Used by callers that build a database
     * outside the compiled config path; the compiled config emits `new <FQCN>()`
     * with the alias already resolved at compile time.
     */
    public static function instantiate(string $classOrAlias): Database
    {
        $class = self::resolve($classOrAlias);

        if (!class_exists($class)) {
            throw new DatabaseException(sprintf(
                'Database driver "%s" resolves to class "%s", which does not exist.%s',
                $classOrAlias,
                $class,
                self::has($classOrAlias)
                    ? ' The registered adapter class is missing — is its package installed?'
                    : ' No driver alias by that name is registered; did you mean a fully-qualified class name, or is a plugin missing?'
            ));
        }

        if (!is_subclass_of($class, Database::class)) {
            throw new DatabaseException(sprintf(
                'Database driver "%s" (class "%s") must extend %s.',
                $classOrAlias,
                $class,
                Database::class
            ));
        }

        return new $class();
    }

    /** @return array<string, class-string<Database>> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /** Test isolation: restore the built-in aliases only. */
    public static function reset(): void
    {
        self::$aliases = ['pdo' => PdoDatabase::class];
    }
}
