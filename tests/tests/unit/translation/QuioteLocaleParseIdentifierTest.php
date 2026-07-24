<?php

use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\QuioteLocale;

/**
 * Covers QuioteLocale::parseLocaleIdentifier() — the hoisted-regex + memoized
 * pure parser. Verifies the parse result, that repeated calls return the same
 * (cached) data, and that invalid identifiers still throw (and are not cached).
 */
class QuioteLocaleParseIdentifierTest extends UnitTestCase
{
    public function testParsesLanguageTerritoryAndOptions(): void
    {
        $data = QuioteLocale::parseLocaleIdentifier('de_DE@timezone=Europe/Berlin;currency=EUR');

        $this->assertSame('de', $data['language']);
        $this->assertSame('DE', $data['territory']);
        $this->assertSame('de_DE', $data['locale_str']);
        $this->assertSame('Europe/Berlin', $data['options']['timezone']);
        $this->assertSame('EUR', $data['options']['currency']);
    }

    public function testParsesPlainLanguageTerritory(): void
    {
        $data = QuioteLocale::parseLocaleIdentifier('en_US');

        $this->assertSame('en', $data['language']);
        $this->assertSame('US', $data['territory']);
        $this->assertSame([], $data['options']);
        $this->assertNull($data['option_str']);
    }

    public function testRepeatedParseReturnsEqualResult(): void
    {
        $first = QuioteLocale::parseLocaleIdentifier('fr_FR@currency=EUR');
        $second = QuioteLocale::parseLocaleIdentifier('fr_FR@currency=EUR');

        $this->assertSame($first, $second);
    }

    public function testInvalidIdentifierThrows(): void
    {
        $this->expectException(QuioteException::class);
        QuioteLocale::parseLocaleIdentifier('@');
    }

    public function testInvalidIdentifierThrowsOnEveryCall(): void
    {
        // A thrown (invalid) result must not be cached: it must throw again.
        try {
            QuioteLocale::parseLocaleIdentifier('@');
            $this->fail('Expected QuioteException on first call.');
        } catch (QuioteException) {
            // expected
        }

        $this->expectException(QuioteException::class);
        QuioteLocale::parseLocaleIdentifier('@');
    }
}
