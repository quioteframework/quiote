<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\TranslationManager;
use Quiote\Translation\QuioteNumberFormatter;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\QuioteException;

/**
 * Happy + failure path coverage for Quiote\Translation\QuioteNumberFormatter,
 * in particular the PHPStan level 9 hardening of initialize()/translate()/
 * localeChanged() against non-scalar-as-expected parameter values.
 */
class QuioteNumberFormatterTest extends UnitTestCase
{
    private TranslationManager $tm;

    #[\Override]
    protected function setUp(): void
    {
        ConfigCache::clear();
        Config::set('core.use_translation', true, true);
        $ctx = Context::getInstance();
        $tm = $ctx->getTranslationManager();
        if ($tm === null) {
            $tm = $this->installTestTranslationManager();
            $tm->startup();
        }
        $this->tm = $tm;
    }

    public function testInitializeThrowsWhenRoundingModeIsNotAString(): void
    {
        $nf = new QuioteNumberFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('QuioteNumberFormatter::initialize() expects the "rounding_mode" parameter to be a string, int given.');
        $nf->initialize($this->getContext(), ['rounding_mode' => 1]);
    }

    public function testInitializeThrowsWhenTranslationDomainIsNotAString(): void
    {
        $nf = new QuioteNumberFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('QuioteNumberFormatter::initialize() expects the "translation_domain" parameter to be a string, int given.');
        $nf->initialize($this->getContext(), ['translation_domain' => 42]);
    }

    public function testInitializeThrowsWhenFormatIsNeitherArrayNorString(): void
    {
        $nf = new QuioteNumberFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('QuioteNumberFormatter::initialize() expects the "format" parameter to be an array or a string, int given.');
        $nf->initialize($this->getContext(), ['format' => 42]);
    }

    public function testTranslateThrowsWhenMessageIsNotAnIntFloatOrString(): void
    {
        $nf = new QuioteNumberFormatter();
        $nf->initialize($this->getContext());
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('QuioteNumberFormatter::translate() expects $message to be an int, float or string, array given.');
        $nf->translate([1, 2], '');
    }

    public function testTranslateFormatsAnIntForTheGivenLocale(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $nf = new QuioteNumberFormatter();
        $nf->initialize($this->getContext());
        $result = $nf->translate(1234, '', $locale);
        $this->assertStringContainsString('1,234', $result);
    }

    public function testTranslateFormatsAFloatForTheGivenLocale(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $nf = new QuioteNumberFormatter();
        $nf->initialize($this->getContext());
        $result = $nf->translate(1234.5, '', $locale);
        $this->assertStringContainsString('1,234.5', $result);
    }

    public function testResetClearsLocaleButPreservesConfiguredFormat(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $nf = new QuioteNumberFormatter();
        $nf->initialize($this->getContext(), ['format' => ['en_US' => '#,##0.0']]);
        $nf->localeChanged($locale);
        $before = $nf->translate(1234, '', $locale);

        $nf->reset();

        // context and the configured per-locale custom format are set once at
        // initialize() time and never restored between requests in worker
        // mode, so reset() must not discard them.
        $this->assertNotNull($nf->getContext());
        $after = $nf->translate(1234, '', $locale);
        $this->assertSame($before, $after);

        $ro = new ReflectionObject($nf);
        $localeProp = $ro->getProperty('locale');
        $this->assertNull($localeProp->getValue($nf), 'reset() must clear the directly-set locale to prevent cross-request bleed');
    }
}
