<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;

/**
 * SystemRandomness is the production binding for {@see RandomnessInterface} --
 * it must delegate to PHP's real CSPRNG, producing the right length/range and
 * (for all practical purposes) never repeating.
 */
class SystemRandomnessTest extends TestCase
{
    public function testImplementsRandomnessInterface(): void
    {
        $this->assertInstanceOf(RandomnessInterface::class, new SystemRandomness());
    }

    public function testBytesReturnsExactlyTheRequestedLength(): void
    {
        $randomness = new SystemRandomness();

        $this->assertSame(16, strlen($randomness->bytes(16)));
    }

    public function testBytesAreNotTheSameAcrossCalls(): void
    {
        $randomness = new SystemRandomness();

        $this->assertNotSame($randomness->bytes(16), $randomness->bytes(16));
    }

    public function testIntStaysWithinTheInclusiveRange(): void
    {
        $randomness = new SystemRandomness();

        for ($i = 0; $i < 50; $i++) {
            $value = $randomness->int(1, 5);
            $this->assertGreaterThanOrEqual(1, $value);
            $this->assertLessThanOrEqual(5, $value);
        }
    }

    public function testIntAcceptsAZeroWidthRange(): void
    {
        $this->assertSame(7, (new SystemRandomness())->int(7, 7));
    }

    public function testIntRejectsAnInvertedRange(): void
    {
        $this->expectException(\Error::class);

        (new SystemRandomness())->int(5, 1);
    }
}
