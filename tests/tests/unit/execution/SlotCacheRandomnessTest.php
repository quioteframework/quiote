<?php

use PHPUnit\Framework\TestCase;
use Quiote\Execution\Slot\SlotCache;
use Quiote\Logging\Log;
use Quiote\Support\Random\SeededRandomness;

/**
 * SlotCache::keyFor()'s uncacheable-key fallback -- reached when the slot's
 * parameters cannot be json_encode()d -- goes through the injected
 * RandomnessInterface seam rather than a direct random_bytes() call, per §6.2
 * of the record/replay determinism plan.
 */
final class SlotCacheRandomnessTest extends TestCase
{
    /**
     * A value json_encode() cannot represent, forcing the fallback branch.
     *
     * @return array<string, string>
     */
    private function unencodableParameters(): array
    {
        return ['bad' => "\xB1\x31"];
    }

    public function testFallsBackToARandomDigestWhenParametersCannotBeEncoded(): void
    {
        $cache = new SlotCache(Log::create(self::class), 'slot-key', new SeededRandomness(42));

        $key = $cache->keyFor('Module', 'Action', 'html', $this->unencodableParameters(), []);

        $this->assertStringContainsString('uncacheable-' . bin2hex((new SeededRandomness(42))->bytes(8)), $key);
    }

    public function testSameSeedProducesTheSameFallbackKeyAcrossTwoInstances(): void
    {
        $a = new SlotCache(Log::create(self::class), 'slot-key', new SeededRandomness(7));
        $b = new SlotCache(Log::create(self::class), 'slot-key', new SeededRandomness(7));

        $keyA = $a->keyFor('Module', 'Action', 'html', $this->unencodableParameters(), []);
        $keyB = $b->keyFor('Module', 'Action', 'html', $this->unencodableParameters(), []);

        $this->assertSame($keyA, $keyB);
    }

    public function testEncodableParametersDoNotConsumeRandomnessAndStayStable(): void
    {
        $cache = new SlotCache(Log::create(self::class), 'slot-key', new SeededRandomness(1));

        $first = $cache->keyFor('Module', 'Action', 'html', ['ok' => 'value'], []);
        $second = $cache->keyFor('Module', 'Action', 'html', ['ok' => 'value'], []);

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('uncacheable-', $first);
    }
}
