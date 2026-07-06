<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Translation\QuioteLocale;

/**
 * Happy + failure path coverage for QuioteLocale's large surface of CLDR-shaped
 * data accessors (calendars, time zones, number symbols/formats, currencies,
 * display names) plus the identifier-derived getters that fall back to PHP's
 * intl extension when no explicit $data override is present.
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

    /** @return array<string, mixed> */
    private function fullFixtureData(): array
    {
        return [
            'displayNames' => [
                'languages' => ['en' => 'English', 'de' => 'German'],
                'scripts' => ['Latn' => 'Latin'],
                'territories' => [
                    // 3+ char region codes come first in real CLDR data, then 2-char country codes.
                    '419' => 'Latin America',
                    'US' => 'United States',
                    'DE' => 'Germany',
                ],
                'variants' => ['POSIX' => 'Posix'],
                'measurementSystemNames' => ['metric' => 'Metric'],
            ],
            'layout' => [
                'orientation' => ['lines' => 'top-to-bottom', 'characters' => 'left-to-right'],
            ],
            'delimiters' => [
                'quotationStart' => '"',
                'quotationEnd' => '"',
                'altQuotationStart' => "'",
                'altQuotationEnd' => "'",
            ],
            'calendars' => [
                'default' => 'gregorian',
                'gregorian' => [
                    'months' => [
                        'format' => [
                            'wide' => [1 => 'January'],
                            'abbreviated' => [1 => 'Jan'],
                        ],
                        'stand-alone' => [
                            'narrow' => [1 => 'J'],
                        ],
                    ],
                    'days' => [
                        'format' => [
                            'wide' => ['sun' => 'Sunday'],
                            'abbreviated' => ['sun' => 'Sun'],
                        ],
                        'stand-alone' => [
                            'narrow' => ['sun' => 'S'],
                        ],
                    ],
                    'quarters' => [
                        'format' => [
                            'wide' => [1 => '1st quarter'],
                            'abbreviated' => [1 => 'Q1'],
                        ],
                        'stand-alone' => [
                            'narrow' => [1 => '1'],
                        ],
                    ],
                    'am' => 'AM',
                    'pm' => 'PM',
                    'eras' => [
                        'wide' => [0 => 'Before Christ', 1 => 'Anno Domini'],
                        'abbreviated' => [0 => 'BC', 1 => 'AD'],
                        'narrow' => [0 => 'B', 1 => 'A'],
                    ],
                    'dateFormats' => [
                        'default' => 'full',
                        'full' => ['pattern' => 'EEEE, MMMM d, y', 'displayName' => 'Full Date'],
                    ],
                    'timeFormats' => [
                        'default' => 'full',
                        'full' => ['pattern' => 'h:mm:ss a zzzz', 'displayName' => 'Full Time'],
                    ],
                    'dateTimeFormats' => [
                        'default' => 'full',
                        'formats' => [
                            'full' => ['pattern' => "{1} 'at' {0}", 'displayName' => 'Full DateTime'],
                        ],
                    ],
                    'fields' => [
                        'year' => [
                            'displayName' => 'Year',
                            'relatives' => ['0' => 'this year', '-1' => 'last year'],
                        ],
                    ],
                ],
            ],
            'timeZoneNames' => [
                'hourFormat' => '+HH:mm;-HH:mm',
                'hoursFormat' => '{0}/{1}',
                'gmtFormat' => 'GMT{0}',
                'regionFormat' => '{0} Time',
                'fallbackFormat' => '{1} ({0})',
                'abbreviationFormat' => '{0}',
                'zones' => [
                    'America/New_York' => [
                        'long' => [
                            'generic' => 'Eastern Time',
                            'standard' => 'Eastern Standard Time',
                            'daylight' => 'Eastern Daylight Time',
                        ],
                        'short' => [
                            'generic' => 'ET',
                            'standard' => 'EST',
                            'daylight' => 'EDT',
                        ],
                    ],
                ],
            ],
            'numbers' => [
                'symbols' => [
                    'decimal' => '.',
                    'group' => ',',
                    'list' => ';',
                    'percentSign' => '%',
                    'nativeZeroDigit' => '0',
                    'patternDigit' => '#',
                    'plusSign' => '+',
                    'minusSign' => '-',
                    'exponential' => 'E',
                    'perMille' => "\u{2030}",
                    'infinity' => "\u{221E}",
                    'nan' => 'NaN',
                ],
                'decimalFormats' => ['standard' => '#,##0.###'],
                'scientificFormats' => ['standard' => '#E0'],
                'percentFormats' => ['standard' => '#,##0%'],
                'currencyFormats' => ['standard' => "\u{00A4}#,##0.00"],
                'currencies' => [
                    'USD' => ['displayName' => 'US Dollar', 'symbol' => '$'],
                ],
            ],
        ];
    }

    public function testGetContextReturnsInitializedContext(): void
    {
        $ctx = $this->createStub(Context::class);
        $loc = new QuioteLocale();
        $loc->initialize($ctx, [], 'en_US', []);
        $this->assertSame($ctx, $loc->getContext());
    }

    public function testDisplayNameAccessorsReadFromData(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame(['en' => 'English', 'de' => 'German'], $loc->getLanguages());
        $this->assertSame('English', $loc->getLanguage('en'));
        $this->assertNull($loc->getLanguage('xx'));

        $this->assertSame(['Latn' => 'Latin'], $loc->getScripts());
        $this->assertSame('Latin', $loc->getScript('Latn'));

        $this->assertSame(['419' => 'Latin America', 'US' => 'United States', 'DE' => 'Germany'], $loc->getTerritories());
        $this->assertSame('Germany', $loc->getTerritory('DE'));

        $this->assertSame(['POSIX' => 'Posix'], $loc->getVariants());
        $this->assertSame('Posix', $loc->getVariant('POSIX'));

        $this->assertSame(['metric' => 'Metric'], $loc->getMeasurementSystemNames());
        $this->assertSame('Metric', $loc->getMeasurementSystemName('metric'));
    }

    public function testGenerateCountryListSplitsRegionsFromCountries(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $countries = $loc->getCountries();
        $this->assertSame(['US' => 'United States', 'DE' => 'Germany'], $countries);
        $this->assertSame('United States', $loc->getCountry('US'));
        $this->assertNull($loc->getCountry('419'));
    }

    public function testDisplayNameAccessorsReturnNullWhenDataMissing(): void
    {
        $loc = $this->makeLocale('en_US', []);

        $this->assertNull($loc->getLanguages());
        $this->assertNull($loc->getLanguage('en'));
        $this->assertNull($loc->getScripts());
        $this->assertNull($loc->getTerritories());
        $this->assertNull($loc->getVariants());
        $this->assertNull($loc->getMeasurementSystemNames());
        $this->assertNull($loc->getCountries());
        $this->assertNull($loc->getCountry('US'));
    }

    public function testLayoutAndDelimiterAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('top-to-bottom', $loc->getLineOrientation());
        $this->assertSame('left-to-right', $loc->getCharacterOrientation());
        $this->assertSame('"', $loc->getQuotationStart());
        $this->assertSame('"', $loc->getQuotationEnd());
        $this->assertSame("'", $loc->getAlternateQuotationStart());
        $this->assertSame("'", $loc->getAlternateQuotationEnd());
    }

    public function testLayoutAndDelimiterAccessorsReturnNullWhenMissing(): void
    {
        $loc = $this->makeLocale('en_US', []);

        $this->assertNull($loc->getLineOrientation());
        $this->assertNull($loc->getCharacterOrientation());
        $this->assertNull($loc->getQuotationStart());
        $this->assertNull($loc->getQuotationEnd());
        $this->assertNull($loc->getAlternateQuotationStart());
        $this->assertNull($loc->getAlternateQuotationEnd());
    }

    public function testCalendarMonthDayQuarterAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('gregorian', $loc->getDefaultCalendar());

        $this->assertSame([1 => 'January'], $loc->getCalendarMonthsWide('gregorian'));
        $this->assertSame('January', $loc->getCalendarMonthWide('gregorian', 1));
        $this->assertSame([1 => 'Jan'], $loc->getCalendarMonthsAbbreviated('gregorian'));
        $this->assertSame('Jan', $loc->getCalendarMonthAbbreviated('gregorian', 1));
        $this->assertSame([1 => 'J'], $loc->getCalendarMonthsNarrow('gregorian'));
        $this->assertSame('J', $loc->getCalendarMonthNarrow('gregorian', 1));

        $this->assertSame(['sun' => 'Sunday'], $loc->getCalendarDaysWide('gregorian'));
        $this->assertSame('Sunday', $loc->getCalendarDayWide('gregorian', 'sun'));
        $this->assertSame(['sun' => 'Sun'], $loc->getCalendarDaysAbbreviated('gregorian'));
        $this->assertSame('Sun', $loc->getCalendarDayAbbreviated('gregorian', 'sun'));
        $this->assertSame(['sun' => 'S'], $loc->getCalendarDaysNarrow('gregorian'));
        $this->assertSame('S', $loc->getCalendarDayNarrow('gregorian', 'sun'));

        $this->assertSame([1 => '1st quarter'], $loc->getCalendarQuartersWide('gregorian'));
        $this->assertSame('1st quarter', $loc->getCalendarQuarterWide('gregorian', 1));
        $this->assertSame([1 => 'Q1'], $loc->getCalendarQuartersAbbreviated('gregorian'));
        $this->assertSame('Q1', $loc->getCalendarQuarterAbbreviated('gregorian', 1));
        $this->assertSame([1 => '1'], $loc->getCalendarQuartersNarrow('gregorian'));
        $this->assertSame('1', $loc->getCalendarQuarterNarrow('gregorian', 1));

        $this->assertSame('AM', $loc->getCalendarAm('gregorian'));
        $this->assertSame('PM', $loc->getCalendarPm('gregorian'));

        $this->assertNull($loc->getCalendarMonthsWide('julian'));
        $this->assertNull($loc->getCalendarMonthWide('gregorian', 99));
    }

    public function testCalendarEraAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame([0 => 'Before Christ', 1 => 'Anno Domini'], $loc->getCalendarErasWide('gregorian'));
        $this->assertSame('Anno Domini', $loc->getCalendarEraWide('gregorian', 1));
        $this->assertSame([0 => 'BC', 1 => 'AD'], $loc->getCalendarErasAbbreviated('gregorian'));
        $this->assertSame('BC', $loc->getCalendarEraAbbreviated('gregorian', 0));
        $this->assertSame([0 => 'B', 1 => 'A'], $loc->getCalendarErasNarrow('gregorian'));
        $this->assertSame('A', $loc->getCalendarEraNarrow('gregorian', 1));
    }

    public function testCalendarDateTimeFormatAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('full', $loc->getCalendarDateFormatDefaultName('gregorian'));
        $this->assertSame(['default' => 'full', 'full' => ['pattern' => 'EEEE, MMMM d, y', 'displayName' => 'Full Date']], $loc->getCalendarDateFormats('gregorian'));
        $this->assertSame(['pattern' => 'EEEE, MMMM d, y', 'displayName' => 'Full Date'], $loc->getCalendarDateFormat('gregorian', 'full'));
        $this->assertSame('EEEE, MMMM d, y', $loc->getCalendarDateFormatPattern('gregorian', 'full'));
        $this->assertSame('Full Date', $loc->getCalendarDateFormatDisplayName('gregorian', 'full'));

        $this->assertSame('full', $loc->getCalendarTimeFormatDefaultName('gregorian'));
        $this->assertSame('h:mm:ss a zzzz', $loc->getCalendarTimeFormatPattern('gregorian', 'full'));
        $this->assertSame('Full Time', $loc->getCalendarTimeFormatDisplayName('gregorian', 'full'));

        $this->assertSame('full', $loc->getCalendarDateTimeFormatDefaultName('gregorian'));
        $this->assertSame(['full' => ['pattern' => "{1} 'at' {0}", 'displayName' => 'Full DateTime']], $loc->getCalendarDateTimeFormats('gregorian'));
        $this->assertSame(['pattern' => "{1} 'at' {0}", 'displayName' => 'Full DateTime'], $loc->getCalendarDateTimeFormat('gregorian', 'full'));
    }

    public function testCalendarFieldAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame(['year' => ['displayName' => 'Year', 'relatives' => ['0' => 'this year', '-1' => 'last year']]], $loc->getCalendarFields('gregorian', 'year'));
        $this->assertSame(['displayName' => 'Year', 'relatives' => ['0' => 'this year', '-1' => 'last year']], $loc->getCalendarField('gregorian', 'year'));
        $this->assertSame('Year', $loc->getCalendarFieldDisplayName('gregorian', 'year'));
        $this->assertSame(['0' => 'this year', '-1' => 'last year'], $loc->getCalendarFieldRelatives('gregorian', 'year'));
        $this->assertSame('this year', $loc->getCalendarFieldRelative('gregorian', 'year', '0'));
        $this->assertNull($loc->getCalendarFieldRelative('gregorian', 'year', '99'));
    }

    public function testTimeZoneFormatAndNameAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('+HH:mm;-HH:mm', $loc->getTimeZoneHourFormat());
        $this->assertSame('{0}/{1}', $loc->getTimeZoneHoursFormat());
        $this->assertSame('GMT{0}', $loc->getTimeZoneGmtFormat());
        $this->assertSame('{0} Time', $loc->getTimeZoneRegionFormat());
        $this->assertSame('{1} ({0})', $loc->getTimeZoneFallbackFormat());
        $this->assertSame('{0}', $loc->getTimeZoneAbbreviationFormat());

        $this->assertSame('Eastern Time', $loc->getTimeZoneLongGenericName('America/New_York'));
        $this->assertSame('Eastern Standard Time', $loc->getTimeZoneLongStandardName('America/New_York'));
        $this->assertSame('Eastern Daylight Time', $loc->getTimeZoneLongDaylightName('America/New_York'));
        $this->assertSame('ET', $loc->getTimeZoneShortGenericName('America/New_York'));
        $this->assertSame('EST', $loc->getTimeZoneShortStandardName('America/New_York'));
        $this->assertSame('EDT', $loc->getTimeZoneShortDaylightName('America/New_York'));

        $this->assertNull($loc->getTimeZoneLongGenericName('Europe/Nowhere'));
        $this->assertArrayHasKey('America/New_York', $loc->getTimeZoneNames());
    }

    public function testTimeZoneNamesReturnsEmptyArrayWhenMissing(): void
    {
        $loc = $this->makeLocale('en_US', []);
        $this->assertSame([], $loc->getTimeZoneNames());
    }

    public function testNumberSymbolAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('.', $loc->getNumberSymbolDecimal());
        $this->assertSame(',', $loc->getNumberSymbolGroup());
        $this->assertSame(';', $loc->getNumberSymbolList());
        $this->assertSame('%', $loc->getNumberSymbolPercentSign());
        $this->assertSame('0', $loc->getNumberSymbolZeroDigit());
        $this->assertSame('#', $loc->getNumberSymbolPatternDigit());
        $this->assertSame('+', $loc->getNumberSymbolPlusSign());
        $this->assertSame('-', $loc->getNumberSymbolMinusSign());
        $this->assertSame('E', $loc->getNumberSymbolExponential());
        $this->assertSame("\u{2030}", $loc->getNumberSymbolPerMille());
        $this->assertSame("\u{221E}", $loc->getNumberSymbolInfinity());
        $this->assertSame('NaN', $loc->getNumberSymbolNaN());
    }

    public function testNumberSymbolAccessorsReturnNullWhenMissing(): void
    {
        $loc = $this->makeLocale('en_US', []);

        $this->assertNull($loc->getNumberSymbolDecimal());
        $this->assertNull($loc->getNumberSymbolGroup());
        $this->assertNull($loc->getNumberSymbolNaN());
    }

    public function testNumberFormatAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame('#,##0.###', $loc->getDecimalFormat('standard'));
        $this->assertSame(['standard' => '#,##0.###'], $loc->getDecimalFormats());
        $this->assertSame('#E0', $loc->getScientificFormat('standard'));
        $this->assertSame(['standard' => '#E0'], $loc->getScientificFormats());
        $this->assertSame('#,##0%', $loc->getPercentFormat('standard'));
        $this->assertSame(['standard' => '#,##0%'], $loc->getPercentFormats());
        $this->assertSame("\u{00A4}#,##0.00", $loc->getCurrencyFormat('standard'));
        $this->assertSame(['standard' => "\u{00A4}#,##0.00"], $loc->getCurrencyFormats());

        $this->assertNull($loc->getDecimalFormat('bogus'));
    }

    public function testCurrencyAccessors(): void
    {
        $loc = $this->makeLocale('en_US', $this->fullFixtureData());

        $this->assertSame(['USD' => ['displayName' => 'US Dollar', 'symbol' => '$']], $loc->getCurrencies());
        $this->assertSame(['displayName' => 'US Dollar', 'symbol' => '$'], $loc->getCurrency('USD'));
        $this->assertSame('US Dollar', $loc->getCurrencyDisplayName('USD'));
        $this->assertSame('$', $loc->getCurrencySymbol('USD'));

        $this->assertNull($loc->getCurrency('EUR'));
        $this->assertNull($loc->getCurrencyDisplayName('EUR'));
        $this->assertNull($loc->getCurrencySymbol('EUR'));
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
        $loc = $this->makeLocale('en_US', ['locale' => ['calendar' => 'islamic'], 'calendars' => ['default' => 'gregorian']], ['calendar' => 'buddhist']);
        $this->assertSame('islamic', $loc->getLocaleCalendar());

        $loc2 = $this->makeLocale('en_US', ['calendars' => ['default' => 'gregorian']], ['calendar' => 'buddhist']);
        $this->assertSame('buddhist', $loc2->getLocaleCalendar());

        $loc3 = $this->makeLocale('en_US', ['calendars' => ['default' => 'gregorian']]);
        $this->assertSame('gregorian', $loc3->getLocaleCalendar());

        $loc4 = $this->makeLocale('en_US', []);
        $this->assertNull($loc4->getLocaleCalendar());
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
