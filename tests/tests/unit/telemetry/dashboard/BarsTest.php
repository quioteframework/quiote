<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\Bars;

class BarsTest extends TestCase
{
    public function testFullyEmptyAtZero(): void
    {
        $this->assertSame('░░░░░░░░░░', Bars::render(0.0, 100.0, 10));
    }

    public function testFullyFilledAtCeiling(): void
    {
        $this->assertSame('██████████', Bars::render(100.0, 100.0, 10));
    }

    public function testHalfFilledAtHalfCeiling(): void
    {
        $this->assertSame('█████░░░░░', Bars::render(50.0, 100.0, 10));
    }

    public function testValueAboveCeilingClampsToFull(): void
    {
        $this->assertSame('██████████', Bars::render(150.0, 100.0, 10));
    }

    public function testNegativeValueClampsToEmpty(): void
    {
        $this->assertSame('░░░░░░░░░░', Bars::render(-5.0, 100.0, 10));
    }

    public function testZeroOrNegativeCeilingRendersEmptyRatherThanDividingByZero(): void
    {
        $this->assertSame('░░░░░░░░░░', Bars::render(5.0, 0.0, 10));
        $this->assertSame(0.0, Bars::ratio(5.0, -1.0));
    }

    public function testZeroWidthRendersEmptyString(): void
    {
        $this->assertSame('', Bars::render(5.0, 10.0, 0));
    }

    public function testNonFiniteInputsDoNotCrash(): void
    {
        $this->assertSame(0.0, Bars::ratio(NAN, 10.0));
        $this->assertSame(0.0, Bars::ratio(5.0, NAN));
        $this->assertSame(0.0, Bars::ratio(5.0, INF));
    }

    public function testCustomFillAndEmptyCharacters(): void
    {
        $this->assertSame('##--', Bars::render(50.0, 100.0, 4, '#', '-'));
    }
}
