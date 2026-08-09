<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * Renders a value/ceiling ratio as a fixed-width filled/empty block bar --
 * the dashboard's stand-in for a Gauge widget (`symfony/tui` has none; see
 * {@see Spark}'s docblock for the same widget-gap note).
 */
final class Bars
{
    /**
     * Renders $value against $ceiling as exactly $width characters: the clamped
     * ratio (see {@see ratio()}) worth of $fill, padded out with $empty.
     *
     * Returns the empty string for a non-positive $width. A non-positive
     * ceiling or a non-finite input renders as an all-empty bar rather than
     * failing.
     */
    public static function render(float $value, float $ceiling, int $width = 20, string $fill = '█', string $empty = '░'): string
    {
        if ($width <= 0) {
            return '';
        }

        $ratio = self::ratio($value, $ceiling);
        $filled = (int) round($ratio * $width);

        return str_repeat($fill, $filled) . str_repeat($empty, $width - $filled);
    }

    /** The clamped [0.0, 1.0] fraction of $ceiling that $value represents. */
    public static function ratio(float $value, float $ceiling): float
    {
        if ($ceiling <= 0.0 || !is_finite($value) || !is_finite($ceiling)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value / $ceiling));
    }
}
