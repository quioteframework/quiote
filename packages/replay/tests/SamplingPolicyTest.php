<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Recording\SamplingPolicy;
use Quiote\Support\Random\RandomnessInterface;

final class SamplingPolicyTest extends TestCase
{
    private function randomness(int $fixedRoll): RandomnessInterface
    {
        return new class($fixedRoll) implements RandomnessInterface {
            public function __construct(private readonly int $fixedRoll)
            {
            }

            public function bytes(int $length): string
            {
                return str_repeat("\x00", $length);
            }

            public function int(int $min, int $max): int
            {
                return $this->fixedRoll;
            }
        };
    }

    public function testFromConfigValueResolvesEachDocumentedValue(): void
    {
        $this->assertSame(SamplingPolicy::Never, SamplingPolicy::fromConfigValue('never'));
        $this->assertSame(SamplingPolicy::Error, SamplingPolicy::fromConfigValue('error'));
        $this->assertSame(SamplingPolicy::Rate, SamplingPolicy::fromConfigValue('rate'));
        $this->assertSame(SamplingPolicy::Header, SamplingPolicy::fromConfigValue('header'));
        $this->assertSame(SamplingPolicy::Always, SamplingPolicy::fromConfigValue('always'));
    }

    public function testUnrecognisedValueThrowsRatherThanFallingBackToNever(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/replay\.record/');
        SamplingPolicy::fromConfigValue('sometimes');
    }

    public function testNeverNeverKeepsAnything(): void
    {
        $policy = SamplingPolicy::Never;

        $this->assertFalse($policy->shouldKeep(200, false, 1.0, $this->randomness(0), true));
        $this->assertFalse($policy->shouldKeep(500, true, 1.0, $this->randomness(0), true));
    }

    public function testAlwaysKeepsEverything(): void
    {
        $policy = SamplingPolicy::Always;

        $this->assertTrue($policy->shouldKeep(200, false, 0.0, $this->randomness(999_999), false));
    }

    public function testErrorKeepsOn5xxStatus(): void
    {
        $policy = SamplingPolicy::Error;

        $this->assertTrue($policy->shouldKeep(500, false, 0.0, $this->randomness(0), false));
    }

    public function testErrorKeepsOnAnEscapedException(): void
    {
        $policy = SamplingPolicy::Error;

        $this->assertTrue($policy->shouldKeep(200, true, 0.0, $this->randomness(0), false));
    }

    public function testErrorDropsA200(): void
    {
        $policy = SamplingPolicy::Error;

        $this->assertFalse($policy->shouldKeep(200, false, 0.0, $this->randomness(0), false));
    }

    public function testErrorDropsA404(): void
    {
        $policy = SamplingPolicy::Error;

        $this->assertFalse($policy->shouldKeep(404, false, 0.0, $this->randomness(0), false));
    }

    public function testHeaderKeepsOnlyWhenTheHeaderIsPresent(): void
    {
        $policy = SamplingPolicy::Header;

        $this->assertTrue($policy->shouldKeep(200, false, 0.0, $this->randomness(0), true));
        $this->assertFalse($policy->shouldKeep(200, false, 0.0, $this->randomness(0), false));
    }

    public function testRateWithASeededGeneratorBelowTheThresholdKeeps(): void
    {
        $policy = SamplingPolicy::Rate;

        $this->assertTrue($policy->shouldKeep(200, false, 0.5, $this->randomness(100_000), false));
    }

    public function testRateWithASeededGeneratorAtOrAboveTheThresholdDrops(): void
    {
        $policy = SamplingPolicy::Rate;

        $this->assertFalse($policy->shouldKeep(200, false, 0.5, $this->randomness(600_000), false));
    }

    public function testRateWithAZeroSampleRateNeverKeeps(): void
    {
        $policy = SamplingPolicy::Rate;

        $this->assertFalse($policy->shouldKeep(200, false, 0.0, $this->randomness(0), false));
    }

    public function testRateWithASampleRateOfOneAlwaysKeeps(): void
    {
        $policy = SamplingPolicy::Rate;

        $this->assertTrue($policy->shouldKeep(200, false, 1.0, $this->randomness(999_999), false));
    }
}
