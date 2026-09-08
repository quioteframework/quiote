<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;

/**
 * `hourPrefix()`/`dayPrefix()`/`keyFor()` are already exercised indirectly through
 * {@see \Quiote\Replay\Store\Storage\ObjectStoreCassetteStore}'s own tests; this covers only
 * {@see CassetteKeyScheme::rootPrefix()}, which nothing else touches.
 */
final class CassetteKeySchemeTest extends TestCase
{
    public function testRootPrefixIsThePrefixAloneRegardlessOfEnvironment(): void
    {
        $scheme = new CassetteKeyScheme('quiote-cassettes', 'staging');

        $this->assertSame('quiote-cassettes/', $scheme->rootPrefix());
    }

    public function testRootPrefixStripsLeadingAndTrailingSlashesFromTheConfiguredPrefix(): void
    {
        $scheme = new CassetteKeyScheme('/quiote-cassettes/', 'staging');

        $this->assertSame('quiote-cassettes/', $scheme->rootPrefix());
    }

    public function testRootPrefixIsOneLevelAboveDayPrefix(): void
    {
        $scheme = new CassetteKeyScheme('quiote-cassettes', 'staging');
        $day = $scheme->dayPrefix(new DateTimeImmutable('2026-08-18T09:12:44+00:00'));

        $this->assertTrue(str_starts_with($day, $scheme->rootPrefix()));
        $this->assertNotSame($scheme->rootPrefix(), $day);
    }
}
