<?php

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * SystemClock is the production binding for {@see ClockInterface} -- it must
 * read the real wall clock and a real monotonic clock, and every reading it
 * hands out must agree with the others to within the time this test itself
 * takes to run.
 */
class SystemClockTest extends TestCase
{
    public function testImplementsBothClockInterfaces(): void
    {
        $clock = new SystemClock();

        $this->assertInstanceOf(ClockInterface::class, $clock);
        $this->assertInstanceOf(PsrClockInterface::class, $clock);
    }

    public function testNowReflectsTheRealWallClock(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $now->getTimestamp());
        $this->assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function testUnixTimestampMatchesTimeWithinASecond(): void
    {
        $expected = time();
        $actual = (new SystemClock())->unixTimestamp();

        $this->assertLessThanOrEqual(1, abs($expected - $actual));
    }

    public function testMicrotimeMatchesUnixTimestampWithinASecond(): void
    {
        $clock = new SystemClock();
        $seconds = $clock->unixTimestamp();
        $micro = $clock->microtime();

        $this->assertLessThanOrEqual(1.0, abs($seconds - $micro));
    }

    public function testMonotonicNeverGoesBackwardsBetweenTwoReads(): void
    {
        $clock = new SystemClock();
        $first = $clock->monotonic();
        $second = $clock->monotonic();

        $this->assertGreaterThanOrEqual($first, $second);
    }

    public function testMonotonicIsExpressedInSeconds(): void
    {
        // hrtime(true) is nanoseconds; a freshly-booted process is nowhere near
        // 1e9 seconds (~31 years) of monotonic uptime, so this also catches a
        // regression that forgets the /1_000_000_000 conversion.
        $this->assertLessThan(1_000_000_000.0, (new SystemClock())->monotonic());
    }
}
