<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

/**
 * Canonical ordering of the `phase` values accepted by
 * `Quiote\Middleware\Attribute\Middleware`. Phase is the primary sort key for
 * MiddlewareOrderResolver — it groups middleware into the same coarse bands
 * the framework's hard-coded pipeline has always used, with `before`/`after`
 * edges and `priority` refining order within/across those bands.
 * @since      1.0.0
 */
final class MiddlewarePhase
{
    public const ORDER = [
        'bootstrap',
        'pre_routing',
        'pre',
        'routing',
        'before_action',
        'action',
        'after_action',
        'finalize',
    ];

    private function __construct()
    {
    }

    /** @throws \InvalidArgumentException if $phase isn't one of self::ORDER */
    public static function rank(string $phase): int
    {
        $rank = array_search($phase, self::ORDER, true);
        if ($rank === false) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown middleware phase "%s"; expected one of: %s',
                $phase,
                implode(', ', self::ORDER)
            ));
        }
        return $rank;
    }
}
