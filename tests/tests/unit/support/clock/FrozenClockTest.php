<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\FrozenClock;

/**
 * FrozenClock is what a deterministic expiry test reaches for: every reading
 * must answer exactly what was set, however much wall-clock time actually
 * elapses while the test runs, and must move only when explicitly told to.
 */
class FrozenClockTest extends TestCase
{
    public function testImplementsClockInterface(): void
    {
        $this->assertInstanceOf(ClockInterface::class, new FrozenClock());
    }

    public function testDefaultsToTheUnixEpoch(): void
    {
        $clock = new FrozenClock();

        $this->assertSame(0, $clock->unixTimestamp());
        $this->assertSame(0.0, $clock->microtime());
        $this->assertSame(0.0, $clock->monotonic());
        $this->assertSame('1970-01-01T00:00:00+00:00', $clock->now()->format('c'));
    }

    public function testConstructedWithAFixedWallClockAndMonotonicReading(): void
    {
        $clock = new FrozenClock(1_700_000_000.5, 42.0);

        $this->assertSame(1_700_000_000, $clock->unixTimestamp());
        $this->assertSame(1_700_000_000.5, $clock->microtime());
        $this->assertSame(42.0, $clock->monotonic());
        $this->assertSame(1_700_000_000, $clock->now()->getTimestamp());
        $this->assertSame(500000, (int) $clock->now()->format('u'));
    }

    public function testFromDateTimeDerivesTheWallClockReading(): void
    {
        $clock = FrozenClock::fromDateTime(new DateTimeImmutable('2026-08-18T09:12:44Z'));

        $this->assertSame('2026-08-18T09:12:44+00:00', $clock->now()->format('c'));
    }

    public function testRepeatedReadsDoNotDriftWithRealElapsedTime(): void
    {
        $clock = new FrozenClock(1_000.0);

        $first = $clock->unixTimestamp();
        usleep(2_000);
        $second = $clock->unixTimestamp();

        $this->assertSame($first, $second);
    }

    public function testSetJumpsTheWallClockWithoutMovingMonotonic(): void
    {
        $clock = new FrozenClock(1_000.0, 5.0);

        $clock->set(2_000.0);

        $this->assertSame(2_000.0, $clock->microtime());
        $this->assertSame(5.0, $clock->monotonic());
    }

    public function testSetMonotonicMovesOnlyTheMonotonicReading(): void
    {
        $clock = new FrozenClock(1_000.0, 5.0);

        $clock->setMonotonic(10.0);

        $this->assertSame(1_000.0, $clock->microtime());
        $this->assertSame(10.0, $clock->monotonic());
    }

    public function testAdvanceMovesBothClocksBySameAmount(): void
    {
        $clock = new FrozenClock(1_000.0, 5.0);

        $clock->advance(30.0);

        $this->assertSame(1_030.0, $clock->microtime());
        $this->assertSame(1_030, $clock->unixTimestamp());
        $this->assertSame(35.0, $clock->monotonic());
    }

    public function testAdvanceAcceptsANegativeAmountToSimulateAClockStepBackwards(): void
    {
        $clock = new FrozenClock(1_000.0, 5.0);

        $clock->advance(-100.0);

        $this->assertSame(900.0, $clock->microtime());
        $this->assertSame(-95.0, $clock->monotonic());
    }
}
