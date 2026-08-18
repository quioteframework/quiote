<?php

declare(strict_types=1);

namespace Quiote\Support\Random;

/**
 * Static facade over the process-wide source of entropy, mirroring
 * {@see \Quiote\Support\Clock\Clock}.
 *
 * {@see \Quiote\Support\CorrelationId} is fully static (no constructor, no
 * container) by design -- its own docblock states it is kept pure and
 * dependency-free so it is unit testable without a bootstrapped
 * {@see \Quiote\Context}. This is its way in. Code that can accept a
 * collaborator should take a RandomnessInterface constructor parameter
 * instead -- it is injectable, swappable and testable in isolation -- and
 * reach for this only where threading one through is not practical.
 *
 * Deliberately not read from the DI container, for the same reason
 * {@see \Quiote\Support\Clock\Clock} is not: a fully static call site has no
 * request-scoped `Context` to resolve through in general, and
 * `Quiote\Context::registerCoreServicesInContainer()` seeds the container's
 * own `RandomnessInterface` binding from {@see instance()}, not the other way
 * round, so installing a source of randomness here before bootstrap also
 * reaches the container.
 */
final class Randomness
{
    /**
     * The source of randomness every static call delegates to.
     */
    private static ?RandomnessInterface $randomness = null;

    /**
     * The randomness backing the facade, created on first use.
     */
    public static function instance(): RandomnessInterface
    {
        return self::$randomness ??= new SystemRandomness();
    }

    /**
     * Install a source of randomness for the facade to delegate to.
     *
     * The seam for a test that needs reproducible output. Pass null to drop
     * the current one, so the next access starts from a fresh
     * SystemRandomness.
     *
     * @return     ?RandomnessInterface The source that was installed before
     *             this call, so a caller can restore it.
     */
    public static function useRandomness(?RandomnessInterface $randomness): ?RandomnessInterface
    {
        $previous = self::$randomness;
        self::$randomness = $randomness;

        return $previous;
    }
}
