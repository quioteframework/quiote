<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Random\Randomness;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SeededRandomness;
use Quiote\Support\Random\SystemRandomness;

/**
 * The static facade fully-static call sites (CorrelationId) reach for. Must
 * default to a real SystemRandomness, accept an installed override, and
 * restore cleanly so one test's override cannot leak into another's.
 */
class RandomnessTest extends TestCase
{
    protected function tearDown(): void
    {
        Randomness::useRandomness(null);
        parent::tearDown();
    }

    public function testInstanceDefaultsToSystemRandomness(): void
    {
        $this->assertInstanceOf(SystemRandomness::class, Randomness::instance());
    }

    public function testInstanceMemoizesTheDefault(): void
    {
        $this->assertSame(Randomness::instance(), Randomness::instance());
    }

    public function testUseRandomnessInstallsAnOverride(): void
    {
        $seeded = new SeededRandomness(1);

        Randomness::useRandomness($seeded);

        $this->assertSame($seeded, Randomness::instance());
    }

    public function testUseRandomnessReturnsThePreviouslyInstalledSource(): void
    {
        $first = new SeededRandomness(1);
        $second = new SeededRandomness(2);

        Randomness::useRandomness($first);
        $previous = Randomness::useRandomness($second);

        $this->assertSame($first, $previous);
        $this->assertSame($second, Randomness::instance());
    }

    public function testUseRandomnessWithNullDropsTheOverride(): void
    {
        Randomness::useRandomness(new SeededRandomness(1));

        Randomness::useRandomness(null);

        $this->assertInstanceOf(SystemRandomness::class, Randomness::instance());
    }

    public function testInstanceReturnsTheRandomnessInterfaceContract(): void
    {
        $this->assertInstanceOf(RandomnessInterface::class, Randomness::instance());
    }
}
