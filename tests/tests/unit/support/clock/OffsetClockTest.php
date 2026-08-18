<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Support\Clock\OffsetClock;

/**
 * OffsetClock wraps another clock and shifts every reading by a fixed amount
 * -- "this client's clock is ten minutes fast". Built over a FrozenClock here
 * so the inner readings are themselves deterministic and the offset's effect
 * is the only thing under test.
 */
class OffsetClockTest extends TestCase
{
    public function testImplementsClockInterface(): void
    {
        $this->assertInstanceOf(ClockInterface::class, new OffsetClock(new FrozenClock()));
    }

    public function testZeroOffsetByDefaultMirrorsTheInnerClock(): void
    {
        $inner = new FrozenClock(1_000.0, 5.0);
        $clock = new OffsetClock($inner);

        $this->assertSame(0.0, $clock->offset());
        $this->assertSame(1_000.0, $clock->microtime());
        $this->assertSame(1_000, $clock->unixTimestamp());
        $this->assertSame(5.0, $clock->monotonic());
        $this->assertSame($inner->now()->format('c'), $clock->now()->format('c'));
    }

    public function testConstructorOffsetShiftsWallClockAndMonotonicReadings(): void
    {
        $inner = new FrozenClock(1_000.0, 5.0);
        $clock = new OffsetClock($inner, 600.0);

        $this->assertSame(1_600.0, $clock->microtime());
        $this->assertSame(1_600, $clock->unixTimestamp());
        $this->assertSame(605.0, $clock->monotonic());
        $this->assertSame(1_600, $clock->now()->getTimestamp());
    }

    public function testNegativeOffsetShiftsReadingsBackwards(): void
    {
        $clock = new OffsetClock(new FrozenClock(1_000.0, 5.0), -100.0);

        $this->assertSame(900.0, $clock->microtime());
        $this->assertSame(-95.0, $clock->monotonic());
    }

    public function testSetOffsetChangesTheOffsetAfterConstruction(): void
    {
        $clock = new OffsetClock(new FrozenClock(1_000.0));

        $clock->setOffset(50.0);

        $this->assertSame(50.0, $clock->offset());
        $this->assertSame(1_050.0, $clock->microtime());
    }

    public function testTracksTheInnerClockAsItMoves(): void
    {
        $inner = new FrozenClock(1_000.0, 5.0);
        $clock = new OffsetClock($inner, 10.0);

        $inner->advance(60.0);

        $this->assertSame(1_070.0, $clock->microtime());
        $this->assertSame(75.0, $clock->monotonic());
    }
}
