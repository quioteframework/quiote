<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use RuntimeException;

/**
 * Process-global registry mapping short driver aliases (e.g. "local", "s3")
 * to the {@see FilesystemAdapterInterface} class that implements them.
 * Mirrors {@see \Quiote\Queue\QueueDriverRegistry} exactly.
 *
 * Only `local` ships in core. Cloud backends register their own alias from
 * their own plugin (e.g. `quioteframework/filesystem-s3`'s `S3FilesystemPlugin`).
 */
final class FilesystemDriverRegistry
{
    /** @var array<string, class-string<FilesystemAdapterInterface>> */
    private static array $aliases = [
        'local' => LocalFilesystemAdapter::class,
    ];

    private function __construct()
    {
    }

    /** @param class-string<FilesystemAdapterInterface> $driverClass */
    public static function register(string $alias, string $driverClass): void
    {
        self::$aliases[$alias] = $driverClass;
    }

    public static function has(string $alias): bool
    {
        return isset(self::$aliases[$alias]);
    }

    /** A string that is not a registered alias is returned unchanged, so a fully-qualified class name passes through. */
    public static function resolve(string $aliasOrClass): string
    {
        return self::$aliases[$aliasOrClass] ?? $aliasOrClass;
    }

    public static function instantiateClassFor(string $aliasOrClass): string
    {
        $class = self::resolve($aliasOrClass);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Filesystem driver "%s" resolves to class "%s", which does not exist.%s',
                $aliasOrClass,
                $class,
                self::has($aliasOrClass)
                    ? ' The registered driver class is missing — is its package installed?'
                    : ' No driver alias by that name is registered; did you mean a fully-qualified class name, or is a plugin missing?'
            ));
        }

        if (!is_a($class, FilesystemAdapterInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Filesystem driver "%s" (class "%s") must implement %s.',
                $aliasOrClass,
                $class,
                FilesystemAdapterInterface::class,
            ));
        }

        return $class;
    }

    /** @return array<string, class-string<FilesystemAdapterInterface>> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /** Test isolation: restore the built-in alias only. */
    public static function reset(): void
    {
        self::$aliases = ['local' => LocalFilesystemAdapter::class];
    }
}
