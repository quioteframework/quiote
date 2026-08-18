<?php

declare(strict_types=1);

namespace Quiote\Support\Clock;

/**
 * Static facade over the process-wide clock, mirroring {@see \Quiote\Config\Config}.
 *
 * A handful of framework classes are fully static (no constructor, no container) and have no
 * seam to receive a {@see ClockInterface} through -- {@see \Quiote\Cache\CacheManager},
 * {@see \Quiote\Config\APCuConfigCache}, {@see \Quiote\Util\WorkerManager} among them. This is
 * their way in. Code that can accept a collaborator should take a ClockInterface constructor
 * parameter instead -- it is injectable, swappable and testable in isolation -- and reach for
 * this only where threading one through is not practical.
 *
 * Deliberately not read from the DI container: {@see \Quiote\Config\Config} isn't either, for
 * the same reason -- a fully static call site has no request-scoped `Context` to resolve
 * through in general, and `Quiote\Context::registerCoreServicesInContainer()` seeds the
 * container's own `ClockInterface` binding from {@see instance()}, not the other way round, so
 * installing a clock here before bootstrap also reaches the container.
 */
final class Clock
{
    /**
     * The clock every static call delegates to.
     */
    private static ?ClockInterface $clock = null;

    /**
     * The clock backing the facade, created on first use.
     */
    public static function instance(): ClockInterface
    {
        return self::$clock ??= new SystemClock();
    }

    /**
     * Install a clock for the facade to delegate to.
     *
     * The seam for a test that needs a clock of its own. Pass null to drop the current one, so
     * the next access starts from a fresh SystemClock.
     *
     * @return     ?ClockInterface The clock that was installed before this call, so a caller
     *             can restore it.
     */
    public static function useClock(?ClockInterface $clock): ?ClockInterface
    {
        $previous = self::$clock;
        self::$clock = $clock;

        return $previous;
    }
}
