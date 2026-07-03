<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\RingBuffer;

class RingBufferTest extends TestCase
{
    public function testMissingSecondsAreZeroFilledByDefault(): void
    {
        $buffer = new RingBuffer(5);
        $series = $buffer->series(100, 'sum');

        $this->assertCount(5, $series);
        $this->assertSame([96 => 0.0, 97 => 0.0, 98 => 0.0, 99 => 0.0, 100 => 0.0], $series);
    }

    public function testCustomDefaultValueIsUsedForMissingSeconds(): void
    {
        $buffer = new RingBuffer(3);
        $series = $buffer->series(10, 'sum', default: -1.0);

        $this->assertSame([8 => -1.0, 9 => -1.0, 10 => -1.0], $series);
    }

    public function testSumAggregatesMultipleSamplesInTheSameSecond(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(100, 1.0);
        $buffer->record(100, 2.0);
        $buffer->record(100, 3.0);

        $this->assertSame(6.0, $buffer->series(100, 'sum')[100]);
    }

    public function testAvgAggregate(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(100, 2.0);
        $buffer->record(100, 4.0);

        $this->assertSame(3.0, $buffer->series(100, 'avg')[100]);
    }

    public function testMaxAggregate(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(100, 2.0);
        $buffer->record(100, 9.0);
        $buffer->record(100, 4.0);

        $this->assertSame(9.0, $buffer->series(100, 'max')[100]);
    }

    public function testLastAggregateReturnsMostRecentlyRecordedValueInBucket(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(100, 1.0);
        $buffer->record(100, 2.0);
        $buffer->record(100, 3.0);

        $this->assertSame(3.0, $buffer->series(100, 'last')[100]);
    }

    public function testCountAggregate(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(100, 1.0);
        $buffer->record(100, 1.0);
        $buffer->record(100, 1.0);

        $this->assertSame(3.0, $buffer->series(100, 'count')[100]);
    }

    public function testSeriesIsChronologicalAcrossTheWindow(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->record(10, 1.0);
        $buffer->record(11, 2.0);
        $buffer->record(12, 3.0);

        $this->assertSame([10 => 1.0, 11 => 2.0, 12 => 3.0], $buffer->series(12, 'sum'));
    }

    public function testBucketsOlderThanTheWindowArePrunedAndDoNotGrowUnbounded(): void
    {
        $buffer = new RingBuffer(2);
        for ($second = 0; $second < 10_000; $second++) {
            $buffer->record($second, 1.0);
        }

        $series = $buffer->series(9999, 'sum');
        $this->assertCount(2, $series);
        $this->assertSame([9998 => 1.0, 9999 => 1.0], $series);
    }

    public function testUnknownAggregateThrows(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->record(1, 1.0);

        $this->expectException(\InvalidArgumentException::class);
        $buffer->series(1, 'bogus');
    }
}
