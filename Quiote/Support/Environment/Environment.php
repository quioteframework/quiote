<?php

declare(strict_types=1);

namespace Quiote\Support\Environment;

/**
 * Static facade over the process-wide environment reader, mirroring
 * {@see \Quiote\Support\Clock\Clock} and {@see \Quiote\Support\Random\Randomness}.
 *
 * A handful of framework classes are fully static or construct their own
 * collaborators before a container exists and have no seam to receive an
 * {@see EnvironmentReaderInterface} through. This is their way in. Code that
 * can accept a collaborator should take an EnvironmentReaderInterface
 * constructor parameter instead -- it is injectable, swappable and testable
 * in isolation -- and reach for this only where threading one through is not
 * practical.
 *
 * Deliberately not read from the DI container, for the same reason
 * {@see \Quiote\Support\Clock\Clock} is not: a fully static call site has no
 * request-scoped `Context` to resolve through in general, and
 * `Quiote\Context::registerCoreServicesInContainer()` seeds the container's
 * own `EnvironmentReaderInterface` binding from {@see instance()}, not the
 * other way round, so installing a reader here before bootstrap also reaches
 * the container.
 */
final class Environment
{
    /**
     * The reader every static call delegates to.
     */
    private static ?EnvironmentReaderInterface $reader = null;

    /**
     * The reader backing the facade, created on first use.
     */
    public static function instance(): EnvironmentReaderInterface
    {
        return self::$reader ??= new SystemEnvironmentReader();
    }

    /**
     * Install a reader for the facade to delegate to.
     *
     * The seam for a test that needs a reader of its own. Pass null to drop
     * the current one, so the next access starts from a fresh
     * SystemEnvironmentReader.
     *
     * @return     ?EnvironmentReaderInterface The reader that was installed
     *             before this call, so a caller can restore it.
     */
    public static function useEnvironmentReader(?EnvironmentReaderInterface $reader): ?EnvironmentReaderInterface
    {
        $previous = self::$reader;
        self::$reader = $reader;

        return $previous;
    }
}
