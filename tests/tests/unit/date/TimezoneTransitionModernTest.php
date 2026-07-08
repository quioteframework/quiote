<?php

use PHPUnit\Framework\TestCase;

/**
 * Modern DST transition test using DateTimeZone::getTransitions.
 * Focuses on 1997 America/Los_Angeles spring & fall transitions (replacing binary searches).
 */
class TimezoneTransitionModernTest extends TestCase
{
    public function testPST1997Transitions(): void
    {
        $tz = new DateTimeZone('America/Los_Angeles');
        // Transitions around 1997
        $transitions = $tz->getTransitions(strtotime('1997-01-01 00:00:00 UTC'), strtotime('1998-01-01 00:00:00 UTC'));
        $this->assertNotEmpty($transitions);
        $dstStarts = array_filter($transitions, fn($t) => $t['isdst'] === true);
        $dstEnds   = array_filter($transitions, fn($t) => $t['isdst'] === false);
        // There should be at least one start and one end inside the year window
        $this->assertGreaterThanOrEqual(1, count($dstStarts));
        $this->assertGreaterThanOrEqual(1, count($dstEnds));
        // Find the first DST start after March 1 1997
        $march = strtotime('1997-03-01 00:00:00 UTC');
        $start = array_find($dstStarts, fn($t) => $t['ts'] >= $march);
        $this->assertNotNull($start, 'DST start not found for 1997');
        // Assert offset shift from -28800 (UTC-8) to -25200 (UTC-7)
        $this->assertEquals(-25200, $start['offset']);
    }
}
