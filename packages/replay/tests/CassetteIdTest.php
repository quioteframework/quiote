<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Support\CorrelationId;

/**
 * Per `docs/RECORD_REPLAY_PLAN.md` §11: a cassette id is untrusted input, and
 * `CorrelationId::sanitize()` passes `/`, `.` and `..` straight through --
 * verified against its real source in {@see CassetteIdTest::testEveryDangerousRawValueIsVerifiedAgainstRealCorrelationIdSanitize()}
 * rather than assumed. `CassetteId` is what must reduce that to a safe slug.
 */
final class CassetteIdTest extends TestCase
{
    public function testAWellFormedIdIsKeptAsTheSlugVerbatim(): void
    {
        $id = CassetteId::fromRaw('01JCXV8N4K');

        $this->assertSame('01JCXV8N4K', $id->raw);
        $this->assertSame('01JCXV8N4K', $id->slug);
    }

    public function testPathTraversalInARawIdIsReducedToASafeSlug(): void
    {
        $id = CassetteId::fromRaw('../../secrets/CRX2050');

        $this->assertSame('../../secrets/CRX2050', $id->raw);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
        $this->assertStringNotContainsString('/', $id->slug);
        $this->assertStringNotContainsString('.', $id->slug);
    }

    public function testABareSlashIsReducedToASafeSlug(): void
    {
        $id = CassetteId::fromRaw('a/b');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }

    public function testANullByteIsReducedToASafeSlug(): void
    {
        $id = CassetteId::fromRaw("evil\0id");

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }

    public function testA300CharacterValueIsReducedToASafeSlug(): void
    {
        $id = CassetteId::fromRaw(str_repeat('a', 300));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
        $this->assertLessThanOrEqual(64, strlen($id->slug));
    }

    public function testANonAsciiValueIsReducedToASafeSlug(): void
    {
        $id = CassetteId::fromRaw('café-☃');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }

    public function testTwoDifferentRawIdsNeverCollideOntoOneSlug(): void
    {
        $a = CassetteId::fromRaw('../secrets/A');
        $b = CassetteId::fromRaw('../secrets/B');

        $this->assertNotSame($a->slug, $b->slug);
    }

    public function testTheRawValueSurvivesEvenWhenTheSlugIsHashed(): void
    {
        $id = CassetteId::fromRaw('../../secrets/CRX2050');

        $this->assertSame('../../secrets/CRX2050', $id->raw);
    }

    /**
     * Verified, not assumed: `CorrelationId::sanitize()` really does pass
     * `/`, `.` and `..` straight through, which is exactly why `CassetteId`
     * cannot rely on it alone for filesystem/object-store safety.
     */
    public function testEveryDangerousRawValueIsVerifiedAgainstRealCorrelationIdSanitize(): void
    {
        $dangerous = '../../secrets/CRX2050';

        $this->assertSame($dangerous, CorrelationId::sanitize($dangerous));

        $id = CassetteId::fromCorrelationId($dangerous);
        $this->assertSame($dangerous, $id->raw);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }

    public function testFromCorrelationIdGeneratesAFreshIdWhenTheRawValueIsNull(): void
    {
        $id = CassetteId::fromCorrelationId(null);

        $this->assertNotSame('', $id->raw);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }

    public function testFromCorrelationIdGeneratesAFreshIdWhenTheRawValueSanitizesToNothing(): void
    {
        $id = CassetteId::fromCorrelationId("\x01\x02");

        $this->assertNotSame('', $id->raw);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id->slug);
    }
}
