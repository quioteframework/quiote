<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

use RuntimeException;

/**
 * Process-global registry mapping short runtime aliases (e.g. "frankenphp",
 * "roadrunner") to the {@see WorkerRuntimeInterface} class that implements them.
 * Mirrors {@see \Quiote\Filesystem\FilesystemDriverRegistry} exactly.
 *
 * Only `sapi` and `frankenphp` ship in core. Other hosts register their own
 * alias from their own plugin (e.g. `quioteframework/worker-roadrunner`'s
 * `WorkerRoadRunnerPlugin`), which is why {@see detect()} lives here rather
 * than as a hardcoded list in the Kernel.
 */
final class WorkerRuntimeRegistry
{
    /** @var array<string, class-string<WorkerRuntimeInterface>> */
    private static array $aliases = self::BUILTIN;

    private const BUILTIN = [
        'frankenphp' => FrankenPhpRuntime::class,
        'sapi' => SapiRuntime::class,
    ];

    private function __construct()
    {
    }

    /** @param class-string<WorkerRuntimeInterface> $runtimeClass */
    public static function register(string $alias, string $runtimeClass): void
    {
        self::$aliases[$alias] = $runtimeClass;
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

    /** @return class-string<WorkerRuntimeInterface> */
    public static function instantiateClassFor(string $aliasOrClass): string
    {
        $class = self::resolve($aliasOrClass);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Worker runtime "%s" resolves to class "%s", which does not exist.%s',
                $aliasOrClass,
                $class,
                self::has($aliasOrClass)
                    ? ' The registered runtime class is missing -- is its package installed?'
                    : ' No runtime alias by that name is registered; did you mean a fully-qualified class name, or is a plugin missing?'
            ));
        }

        if (!is_a($class, WorkerRuntimeInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Worker runtime "%s" (class "%s") must implement %s.',
                $aliasOrClass,
                $class,
                WorkerRuntimeInterface::class,
            ));
        }

        return $class;
    }

    /**
     * The registered runtime that claims the current process: the highest
     * detectionPriority() among those reporting isSupported(), ties broken by
     * registration order so the result is deterministic.
     *
     * Always resolves -- {@see SapiRuntime} claims every process at
     * PHP_INT_MIN -- so callers never have to handle "no runtime".
     *
     * @return class-string<WorkerRuntimeInterface>
     */
    public static function detect(): string
    {
        /** @var class-string<WorkerRuntimeInterface>|null $best */
        $best = null;
        $bestPriority = null;

        foreach (self::$aliases as $class) {
            // A registered alias whose package was uninstalled shouldn't stop
            // detection; instantiateClassFor() is the one that complains loudly,
            // and only when that alias was asked for by name.
            if (!class_exists($class)) {
                continue;
            }
            if (!$class::isSupported()) {
                continue;
            }
            $priority = $class::detectionPriority();
            if ($bestPriority === null || $priority > $bestPriority) {
                $best = $class;
                $bestPriority = $priority;
            }
        }

        return $best ?? SapiRuntime::class;
    }

    /** @return array<string, class-string<WorkerRuntimeInterface>> */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    /** Test isolation: restore the built-in aliases only. */
    public static function reset(): void
    {
        self::$aliases = self::BUILTIN;
    }
}
