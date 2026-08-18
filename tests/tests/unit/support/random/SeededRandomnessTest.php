<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SeededRandomness;

/**
 * SeededRandomness is what a deterministic test or a replay engine reaches
 * for: the same seed must reproduce the exact same sequence of
 * bytes()/int() results, and a different seed must not.
 */
class SeededRandomnessTest extends TestCase
{
    public function testImplementsRandomnessInterface(): void
    {
        $this->assertInstanceOf(RandomnessInterface::class, new SeededRandomness(1));
    }

    public function testSameSeedProducesTheSameByteSequence(): void
    {
        $a = new SeededRandomness(42);
        $b = new SeededRandomness(42);

        $this->assertSame($a->bytes(16), $b->bytes(16));
        $this->assertSame($a->bytes(8), $b->bytes(8));
    }

    public function testSameSeedProducesTheSameIntSequence(): void
    {
        $a = new SeededRandomness(42);
        $b = new SeededRandomness(42);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($a->int(1, 1000), $b->int(1, 1000));
        }
    }

    public function testDifferentSeedsProduceDifferentSequences(): void
    {
        $a = new SeededRandomness(1);
        $b = new SeededRandomness(2);

        $this->assertNotSame($a->bytes(16), $b->bytes(16));
    }

    public function testBytesReturnsExactlyTheRequestedLength(): void
    {
        $randomness = new SeededRandomness(7);

        $this->assertSame(24, strlen($randomness->bytes(24)));
    }

    public function testIntStaysWithinTheInclusiveRange(): void
    {
        $randomness = new SeededRandomness(7);

        for ($i = 0; $i < 50; $i++) {
            $value = $randomness->int(10, 20);
            $this->assertGreaterThanOrEqual(10, $value);
            $this->assertLessThanOrEqual(20, $value);
        }
    }

    public function testSuccessiveReadsFromOneInstanceAdvanceThroughTheSequence(): void
    {
        // Reading twice from one instance must not repeat the first value --
        // otherwise a replay reproducing N recorded reads would collapse them
        // all onto the first one.
        $randomness = new SeededRandomness(99);

        $first = $randomness->bytes(16);
        $second = $randomness->bytes(16);

        $this->assertNotSame($first, $second);
    }
}
