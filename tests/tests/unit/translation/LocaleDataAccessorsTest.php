<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Translation\QuioteLocale;

/**
 * Happy + failure path coverage for QuioteLocale's identifier-derived getters:
 * the language/territory/script/variant/currency/calendar/timezone accessors
 * that read an explicit $data override when present and otherwise fall back to
 * PHP's intl extension. The former CLDR-shaped data accessors (calendar names,
 * number symbols/formats, display names, …) were removed in favour of ext/intl,
 * so they are no longer exercised here.
 */
class LocaleDataAccessorsTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $params
     */
    private function makeLocale(string $identifier, array $data = [], array $params = []): QuioteLocale
    {
        $loc = new QuioteLocale();
        $ctx = $this->createStub(Context::class);
        $loc->initialize($ctx, $params, $identifier, $data);
        return $loc;
    }

    public function testGetContextReturnsInitializedContext(): void
    {
        $ctx = $this->createStub(Context::class);
        $loc = new QuioteLocale();
        $loc->initialize($ctx, [], 'en_US', []);
        $this->assertSame($ctx, $loc->getContext());
    }

    public function testLocaleLanguageTerritoryFallBackToIntlWhenNoDataOverride(): void
    {
        $loc = $this->makeLocale('en_US', []);

        $this->assertSame('en', $loc->getLocaleLanguage());
        $this->assertSame('US', $loc->getLocaleTerritory());
    }

    public function testLocaleLanguageTerritoryPreferExplicitDataOverride(): void
    {
        $loc = $this->makeLocale('en_US', ['locale' => ['language' => 'xx', 'territory' => 'YY']]);

        $this->assertSame('xx', $loc->getLocaleLanguage());
        $this->assertSame('YY', $loc->getLocaleTerritory());
    }

    public function testLocaleScriptFallsBackToParsedIdentifier(): void
    {
        $loc = $this->makeLocale('zh_Hant_TW', []);
        $this->assertSame('Hant', $loc->getLocaleScript());

        // No script subtag and none derivable -> null.
        $loc2 = $this->makeLocale('en_US', []);
        $this->assertNull($loc2->getLocaleScript());
    }

    public function testLocaleScriptPrefersExplicitDataOverride(): void
    {
        $loc = $this->makeLocale('zh_Hant_TW', ['locale' => ['script' => 'Cyrl']]);
        $this->assertSame('Cyrl', $loc->getLocaleScript());
    }

    public function testLocaleVariantFallsBackToParsedIdentifier(): void
    {
        $loc = $this->makeLocale('en_US_POSIX', []);
        $this->assertSame('POSIX', $loc->getLocaleVariant());
    }

    public function testLocaleVariantPrefersExplicitDataOverride(): void
    {
        $loc = $this->makeLocale('en_US_POSIX', ['locale' => ['variant' => 'CUSTOM']]);
        $this->assertSame('CUSTOM', $loc->getLocaleVariant());
    }

    public function testLocaleCurrencyPrecedenceChain(): void
    {
        // data override wins over currencyOverride, parameters, and intl fallback
        $loc = $this->makeLocale('en_US', ['locale' => ['currency' => 'AAA', 'currencyOverride' => 'BBB']], ['currency' => 'CCC']);
        $this->assertSame('AAA', $loc->getLocaleCurrency());

        // currencyOverride wins over parameters and intl fallback
        $loc2 = $this->makeLocale('en_US', ['locale' => ['currencyOverride' => 'BBB']], ['currency' => 'CCC']);
        $this->assertSame('BBB', $loc2->getLocaleCurrency());

        // parameters win over the intl fallback
        $loc3 = $this->makeLocale('en_US', [], ['currency' => 'CCC']);
        $this->assertSame('CCC', $loc3->getLocaleCurrency());

        // with nothing set at all, falls back to NumberFormatter's currency code for the locale
        $loc4 = $this->makeLocale('en_US', []);
        $this->assertSame('USD', $loc4->getLocaleCurrency());
    }

    public function testLocaleCalendarPrecedenceChain(): void
    {
        // locale.calendar override wins over the calendar parameter
        $loc = $this->makeLocale('en_US', ['locale' => ['calendar' => 'islamic']], ['calendar' => 'buddhist']);
        $this->assertSame('islamic', $loc->getLocaleCalendar());

        // the calendar parameter is used when no data override is present
        $loc2 = $this->makeLocale('en_US', [], ['calendar' => 'buddhist']);
        $this->assertSame('buddhist', $loc2->getLocaleCalendar());

        // nothing configured -> null (calendar selection is otherwise left to intl)
        $loc3 = $this->makeLocale('en_US', []);
        $this->assertNull($loc3->getLocaleCalendar());
    }

    public function testLocaleTimeZonePrecedenceChain(): void
    {
        $loc = $this->makeLocale('en_US', ['locale' => ['timezone' => 'Europe/Berlin']], ['timezone' => 'America/New_York']);
        $this->assertSame('Europe/Berlin', $loc->getLocaleTimeZone());

        $loc2 = $this->makeLocale('en_US', [], ['timezone' => 'America/New_York']);
        $this->assertSame('America/New_York', $loc2->getLocaleTimeZone());

        $loc3 = $this->makeLocale('en_US', []);
        $this->assertNull($loc3->getLocaleTimeZone());
    }
}
