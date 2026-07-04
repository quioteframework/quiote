<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\Spark;

class SparkTest extends TestCase
{
    // --- renderBars() ----------------------------------------------------

    public function testEmptySeriesRendersBlankLinesForTheRequestedHeight(): void
    {
        $this->assertSame(['', '', ''], Spark::renderBars([], 3));
    }

    public function testHeightIsClampedToAtLeastOne(): void
    {
        $this->assertCount(1, Spark::renderBars([1.0], 0));
        $this->assertCount(1, Spark::renderBars([1.0], -5));
    }

    public function testAllZeroValuesRenderAsBlankRatherThanAVisibleBaseline(): void
    {
        // Deliberate divergence from a classic min-max sparkline: zero is a
        // meaningful "nothing happened" reading for these non-negative
        // count/duration series, not just "the smallest value observed".
        $rendered = Spark::renderBars([0.0, 0.0, 0.0], 2);

        $this->assertSame(['   ', '   '], $rendered);
    }

    public function testMaxValueFillsTheFullColumnHeight(): void
    {
        $rendered = Spark::renderBars([10.0], 3);

        $this->assertSame(['█', '█', '█'], $rendered);
    }

    public function testHalfOfMaxFillsExactlyTheBottomRowOfATwoRowChart(): void
    {
        // column 0 (value 5, ratio 0.5 of max 10) fills exactly the bottom
        // row and leaves the top row blank; column 1 (value 10, ratio 1.0)
        // fills both rows.
        $rendered = Spark::renderBars([5.0, 10.0], 2);

        $this->assertSame(' █', $rendered[0]); // top row
        $this->assertSame('██', $rendered[1]); // bottom row
    }

    public function testPartialRowUsesAnEighthBlockGlyphAtTheTransition(): void
    {
        // height=1: sub-resolution lives entirely in row 0. ratio 0.5 of max
        // -> 4/8 sub-units -> the "half block" glyph, not a full block.
        $rendered = Spark::renderBars([5.0, 10.0], 1);

        $this->assertSame('▄█', $rendered[0]);
    }

    public function testEveryLineIsTheSameLengthAsTheInputSeries(): void
    {
        $rendered = Spark::renderBars([1.0, 2.0, 3.0, 4.0], 4);

        foreach ($rendered as $line) {
            $this->assertSame(4, mb_strlen($line));
        }
    }

    public function testNonFiniteAndNegativeValuesAreTreatedAsZeroRatherThanCrashing(): void
    {
        $rendered = Spark::renderBars([NAN, INF, -INF, -5.0, 10.0], 1);

        $this->assertSame(5, mb_strlen($rendered[0]));
        $this->assertSame(' ', mb_substr($rendered[0], 0, 1));
    }

    // --- resample() --------------------------------------------------------

    public function testResampleReturnsTheOriginalSeriesUnchangedWhenItAlreadyFits(): void
    {
        $this->assertSame([1.0, 2.0, 3.0], Spark::resample([1.0, 2.0, 3.0], 10));
        $this->assertSame([1.0, 2.0, 3.0], Spark::resample([1.0, 2.0, 3.0], 3));
    }

    public function testResampleDownsamplesToExactlyTheTargetColumnCount(): void
    {
        $series = range(1, 100);
        $resampled = Spark::resample(array_map('floatval', $series), 10);

        $this->assertCount(10, $resampled);
    }

    public function testResampleAveragesEachBucket(): void
    {
        $resampled = Spark::resample([0.0, 10.0, 0.0, 10.0], 2);

        $this->assertSame([5.0, 5.0], $resampled);
    }

    public function testResampleOfEmptySeriesIsEmpty(): void
    {
        $this->assertSame([], Spark::resample([], 10));
    }

    public function testResampleWithZeroOrNegativeTargetIsEmpty(): void
    {
        $this->assertSame([], Spark::resample([1.0, 2.0], 0));
        $this->assertSame([], Spark::resample([1.0, 2.0], -1));
    }
}
