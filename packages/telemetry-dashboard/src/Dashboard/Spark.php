<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * Renders a numeric series as a Unicode block-glyph bar chart, using eighth
 * blocks (`▁▂▃▄▅▆▇█`) per character row to get sub-row vertical resolution
 * across an arbitrary number of text rows. `symfony/tui` has no built-in
 * Chart/Sparkline widget -- this, plus {@see Bars} and {@see ChartWidget} (which
 * wraps this class to make it fill its assigned space and react to terminal
 * resizes), is what stands in for one.
 *
 * Scaled against an **absolute zero baseline** (`value / max`), not a
 * relative min-max range: every value here is a non-negative count or
 * duration (requests/s, latency ms), where "zero" is a meaningful, distinct
 * reading -- a quiet second should draw no bar, not a token minimum-height
 * bar that makes it look identical to "the smallest amount of *something*
 * happened." Min-max normalization (as a single-glyph sparkline typically
 * uses, to guarantee every column shows *some* visible signal in the one
 * character it has) would blur exactly that distinction.
 */
final class Spark
{
    private const LEVELS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    private const SUBLEVELS_PER_ROW = 8;

    /**
     * @param float[] $values
     * @return string[] exactly $height lines, top row first, each one
     *         character per value (after {@see resample()} if needed to fit
     *         a target width)
     */
    public static function renderBars(array $values, int $height): array
    {
        $height = max(1, $height);

        if ($values === []) {
            return array_fill(0, $height, '');
        }

        $clean = array_map(static fn(float $v) => is_finite($v) && $v > 0.0 ? $v : 0.0, $values);
        $max = max($clean);
        $maxSubUnits = $height * self::SUBLEVELS_PER_ROW;

        $rowsFromBottom = array_fill(0, $height, '');
        foreach ($clean as $value) {
            $ratio = $max > 0.0 ? $value / $max : 0.0;
            $subUnits = (int) round($ratio * $maxSubUnits);
            $subUnits = max(0, min($maxSubUnits, $subUnits));

            $fullRows = intdiv($subUnits, self::SUBLEVELS_PER_ROW);
            $remainder = $subUnits % self::SUBLEVELS_PER_ROW;

            for ($row = 0; $row < $height; $row++) {
                $rowsFromBottom[$row] .= match (true) {
                    $row < $fullRows => self::LEVELS[self::SUBLEVELS_PER_ROW - 1],
                    $row === $fullRows && $remainder > 0 => self::LEVELS[$remainder - 1],
                    default => ' ',
                };
            }
        }

        return array_reverse($rowsFromBottom);
    }

    /**
     * Downsamples (bucket-averages) a series to at most $targetColumns
     * values, so a chart always exactly fits its assigned width instead of
     * overflowing or leaving it unused. Series shorter than or equal to the
     * target are returned unchanged -- this never upsamples/stretches.
     *
     * @param float[] $values
     * @return float[]
     */
    public static function resample(array $values, int $targetColumns): array
    {
        $count = count($values);
        if ($targetColumns <= 0 || $count === 0) {
            return [];
        }
        if ($count <= $targetColumns) {
            return $values;
        }

        $result = [];
        for ($i = 0; $i < $targetColumns; $i++) {
            $start = (int) floor($i * $count / $targetColumns);
            $end = max($start + 1, (int) floor(($i + 1) * $count / $targetColumns));
            $slice = array_slice($values, $start, $end - $start);
            $result[] = array_sum($slice) / count($slice);
        }

        return $result;
    }
}
