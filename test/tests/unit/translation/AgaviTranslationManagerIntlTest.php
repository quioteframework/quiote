<?php

use Agavi\AgaviContext;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Translation\AgaviTranslationManager;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;

/**
 * Tests for the new intl-based AgaviTranslationManager implementation.
 *
 * These tests intentionally avoid legacy calendar/timezone classes that were removed.
 */
#[AgaviIsolationEnvironment('testing-use_translation_on')]
class AgaviTranslationManagerIntlTest extends AgaviUnitTestCase
{
    private AgaviTranslationManager $tm;

    /** @var string|null Locale active before this test, restored in tearDown(). */
    private ?string $originalLocaleIdentifier = null;

    protected function setUp(): void
    {
        // Ensure translation system is enabled for these tests regardless of environment mapping quirks
        AgaviConfigCache::clear();
        AgaviConfig::set('core.use_translation', true, true);

        $ctx = AgaviContext::getInstance();
        $tm = $ctx->getTranslationManager();
        if ($tm === null) {
            // If factory info missing, register translation manager class
            $info = $ctx->getFactoryInfo('translation_manager');
            if ($info === null || empty($info['class'])) {
                $ctx->setFactoryInfo('translation_manager', [
                    'class' => AgaviTranslationManager::class,
                    'parameters' => [],
                ]);
            }
            // Create and inject instance via reflection (pattern used in other tests)
            /** @var AgaviTranslationManager $tm */
            $tm = $ctx->createInstanceFor('translation_manager');
            $ro = new \ReflectionObject($ctx);
            $prop = $ro->getProperty('translationManager');

            $prop->setValue($ctx, $tm);
            // Ensure added to shutdown sequence
            $seqProp = $ro->getProperty('shutdownSequence');

            $seq = $seqProp->getValue($ctx);
            if (!in_array($tm, $seq, true)) {
                $seq[] = $tm;
                $seqProp->setValue($ctx, $seq);
            }
            $tm->startup();
        }
        $this->tm = $tm;
        // The translation manager is a shared context singleton. Some tests here
        // call setLocale() (e.g. de_DE); without restoring it, later tests that
        // depend on the default locale's number formatting (e.g. parsing "1.23"
        // with "." as the decimal separator) break. Capture the active locale so
        // tearDown() can put it back.
        $this->originalLocaleIdentifier = $this->tm->getCurrentLocaleIdentifier();
    }

    protected function tearDown(): void
    {
        // Restore whatever locale was active before this test. If none was set yet
        // (the manager had just been created), fall back to its default locale so a
        // test that called setLocale('de_DE') cannot leave the shared singleton on a
        // locale whose number format ("." as a thousands separator) breaks later
        // tests such as AgaviNumberValidatorTest.
        $restore = $this->originalLocaleIdentifier ?? $this->tm->getDefaultLocaleIdentifier();
        if ($restore !== null) {
            $this->tm->setLocale($restore);
        }
        parent::tearDown();
    }

    public function testLocaleBasicConstruction(): void
    {
        $loc = $this->tm->getLocale('en_US');
        $this->assertSame('en_US', $loc->getIdentifier());
        $this->assertEquals('en', $loc->getLocaleLanguage());
        $this->assertEquals('US', $loc->getLocaleTerritory());
    }

    public function testLocaleOptionMerging(): void
    {
        // Assume de_DE available; add timezone + currency override
        $loc = $this->tm->getLocale('de_DE@timezone=Europe/Berlin;currency=EUR');
        $this->assertStringContainsString('de_DE', $loc->getIdentifier());
        $this->assertEquals('Europe/Berlin', $loc->getLocaleTimeZone());
        $this->assertEquals('EUR', $loc->getLocaleCurrency());

    // Shortcut notation: reuse current locale's base (set to de_DE) add calendar option
    $this->tm->setLocale('de_DE'); // ensure currentLocaleIdentifier base is de_DE for shortcut
    $loc2 = $this->tm->getLocale('@calendar=gregorian');
    $this->assertStringContainsString('@', $loc2->getIdentifier());
    $this->assertEquals('de', $loc2->getLocaleLanguage());
    }

    public function testLocaleCaching(): void
    {
        $l1 = $this->tm->getLocale('en_US');
        $l2 = $this->tm->getLocale('en_US');
        $this->assertSame($l1, $l2, 'Expected locale cache hit to return identical instance');

        $l3 = $this->tm->getLocale('en_US', true);
        $this->assertNotSame($l1, $l3, 'Force new should bypass cache');
    }

    public function testTimeZoneCanonicalization(): void
    {
        $tz = $this->tm->createTimeZone('Europe/BERLIN'); // case insensitivity not guaranteed, but most ICU versions canonicalize
        $this->assertInstanceOf(DateTimeZone::class, $tz);

        $canonical = $this->tm->resolveTimeZoneId('GMT+02:00');
        $this->assertNotEmpty($canonical);

        // Offset style
        $tz2 = $this->tm->createTimeZone('+0200');
        $this->assertInstanceOf(DateTimeZone::class, $tz2);
    }

    public function testTimeZoneTerritoryLookup(): void
    {
        $hasMultiple = null;
        $territory = $this->tm->getTimeZoneTerritory('Europe/Berlin', $hasMultiple);
        if($territory !== null) { // Some ICU builds might vary
            $this->assertEquals('DE', $territory, 'Expected DE for Europe/Berlin');
            $this->assertIsBool($hasMultiple);
        }
    }

    public function testTerritoryData(): void
    {
        $data = $this->tm->getTerritoryData('US');
        $this->assertIsArray($data);
        if(isset($data['week'])) {
            $this->assertArrayHasKey('firstDay', $data['week']);
        }
        // caching path
        $data2 = $this->tm->getTerritoryData('US');
        $this->assertSame($data, $data2);
    }

    public function testCurrencyFraction(): void
    {
        $usd = $this->tm->getCurrencyFraction('USD');
        $this->assertArrayHasKey('digits', $usd);
        $this->assertArrayHasKey('rounding', $usd);
        $this->assertIsInt($usd['digits']);
        $this->assertIsInt($usd['rounding']);

        $jpy = $this->tm->getCurrencyFraction('jpy');
        $this->assertIsInt($jpy['digits']);
        // typical expectation is 0 fraction digits for JPY; don't assert hard requirement in case of ICU variation
    }

    public function testInvalidInputsGraceful(): void
    {
        $this->assertNull($this->tm->createTimeZone('')); // invalid
        $this->assertNull($this->tm->getTimeZoneTerritory('')); // invalid
        $this->assertEquals(['digits' => 2, 'rounding' => 0], $this->tm->getCurrencyFraction('')); // default fallback
    }

    public function testLocaleMixedOptionSeparators(): void
    {
        // Mix semicolon and comma separators to ensure parser accepts both
        $loc = $this->tm->getLocale('en_US@timezone=Europe/Berlin;currency=EUR,calendar=gregorian');
        $this->assertStringContainsString('@', $loc->getIdentifier());
        $this->assertEquals('Europe/Berlin', $loc->getLocaleTimeZone());
        $this->assertEquals('EUR', $loc->getLocaleCurrency());
        $this->assertEquals('gregorian', $loc->getLocaleCalendar());
    }
}
