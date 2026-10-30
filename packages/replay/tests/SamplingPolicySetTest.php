<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Recording\SamplingPolicy;
use Quiote\Replay\Recording\SamplingPolicySet;
use Quiote\Support\Random\RandomnessInterface;

final class SamplingPolicySetTest extends TestCase
{
    /** @return RandomnessInterface&object{intCalls: int} */
    private function randomness(int $fixedRoll): RandomnessInterface
    {
        return new class($fixedRoll) implements RandomnessInterface {
            public int $intCalls = 0;

            public function __construct(private readonly int $fixedRoll)
            {
            }

            public function bytes(int $length): string
            {
                return str_repeat("\x00", $length);
            }

            public function int(int $min, int $max): int
            {
                $this->intCalls++;

                return $this->fixedRoll;
            }
        };
    }

    public function testASingleValueResolvesToThatPolicy(): void
    {
        $this->assertSame([SamplingPolicy::Header], SamplingPolicySet::fromConfigValues(['header'])->policies());
    }

    public function testAnArrayOfValuesCombinesThem(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['header', 'error']);

        $this->assertSame([SamplingPolicy::Error, SamplingPolicy::Header], $set->policies());
    }

    public function testACommaSeparatedStringCombinesThem(): void
    {
        $set = SamplingPolicySet::fromConfigValues([' header , error ']);

        $this->assertSame([SamplingPolicy::Error, SamplingPolicy::Header], $set->policies());
    }

    public function testDuplicatesCollapse(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,error', 'error']);

        $this->assertSame([SamplingPolicy::Error], $set->policies());
    }

    public function testNeverOnItsOwnIsNever(): void
    {
        $this->assertTrue(SamplingPolicySet::fromConfigValues(['never'])->isNever());
        $this->assertTrue(SamplingPolicySet::never()->isNever());
        $this->assertFalse(SamplingPolicySet::fromConfigValues(['error'])->isNever());
    }

    public function testNeverCombinedWithAnotherPolicyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/combines "never"/');
        SamplingPolicySet::fromConfigValues(['never,error']);
    }

    public function testAnUnrecognisedEntryThrowsEvenAlongsideValidOnes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/replay\.record is "eror"/');
        SamplingPolicySet::fromConfigValues(['header,eror']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function emptyValues(): iterable
    {
        yield 'no entries' => [[]];
        yield 'empty string' => [['']];
        yield 'only separators' => [[' , ,']];
    }

    /** @param list<string> $values */
    #[DataProvider('emptyValues')]
    public function testNamingNoPolicyThrows(array $values): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/names no policy/');
        SamplingPolicySet::fromConfigValues($values);
    }

    public function testErrorAndHeaderKeepsAFailingRequestWithoutTheHeader(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,header']);

        $this->assertSame(SamplingPolicy::Error, $set->keptBy(500, false, 0.0, $this->randomness(0), false));
        $this->assertSame(SamplingPolicy::Error, $set->keptBy(200, true, 0.0, $this->randomness(0), false));
    }

    public function testErrorAndHeaderKeepsASuccessfulRequestThatCarriesTheHeader(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,header']);

        $this->assertSame(SamplingPolicy::Header, $set->keptBy(200, false, 0.0, $this->randomness(0), true));
    }

    public function testErrorAndHeaderDropsASuccessfulRequestWithoutTheHeader(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,header']);

        $this->assertNull($set->keptBy(200, false, 0.0, $this->randomness(0), false));
    }

    public function testErrorTakesPrecedenceWhenSeveralMembersWouldKeep(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['always,header,error']);

        $this->assertSame(SamplingPolicy::Error, $set->keptBy(503, false, 0.0, $this->randomness(0), true));
        $this->assertSame(SamplingPolicy::Header, $set->keptBy(200, false, 0.0, $this->randomness(0), true));
        $this->assertSame(SamplingPolicy::Always, $set->keptBy(200, false, 0.0, $this->randomness(0), false));
    }

    public function testNeverKeepsNothing(): void
    {
        $this->assertNull(SamplingPolicySet::never()->keptBy(500, true, 1.0, $this->randomness(0), true));
    }

    public function testRateAloneDeclinesUpFrontOnALostRoll(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['rate']);
        $rolled = false;

        $this->assertTrue($set->declinesUpFront(0.5, $this->randomness(600_000), $rolled));
        $this->assertFalse($rolled);
    }

    public function testRateAloneDoesNotDeclineUpFrontOnAWonRoll(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['rate']);
        $rolled = false;

        $this->assertFalse($set->declinesUpFront(0.5, $this->randomness(100_000), $rolled));
        $this->assertTrue($rolled);
    }

    public function testRateCombinedWithErrorDoesNotDeclineUpFrontButStillRolls(): void
    {
        // `error` needs the outcome, so a lost roll cannot skip the capture -- but the roll must
        // still be made here, so keptBy() reuses it rather than sampling a second time.
        $set = SamplingPolicySet::fromConfigValues(['error,rate']);
        $randomness = $this->randomness(600_000);
        $rolled = false;

        $this->assertFalse($set->declinesUpFront(0.5, $randomness, $rolled));
        $this->assertFalse($rolled);
        $this->assertSame(1, $randomness->intCalls);

        $this->assertNull($set->keptBy(200, false, 0.5, $randomness, false, $rolled));
        $this->assertSame(SamplingPolicy::Error, $set->keptBy(500, false, 0.5, $randomness, false, $rolled));
        $this->assertSame(1, $randomness->intCalls);
    }

    public function testRateCombinedWithErrorKeepsAWonRollAsRate(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,rate']);
        $randomness = $this->randomness(100_000);
        $rolled = false;
        $set->declinesUpFront(0.5, $randomness, $rolled);

        $this->assertSame(SamplingPolicy::Rate, $set->keptBy(200, false, 0.5, $randomness, false, $rolled));
    }

    public function testASetWithoutRateNeverDeclinesUpFrontAndNeverRolls(): void
    {
        $set = SamplingPolicySet::fromConfigValues(['error,header']);
        $randomness = $this->randomness(0);
        $rolled = false;

        $this->assertFalse($set->declinesUpFront(0.5, $randomness, $rolled));
        $this->assertSame(0, $randomness->intCalls);
    }
}
