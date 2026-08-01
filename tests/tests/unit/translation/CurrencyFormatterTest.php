<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\TranslationManager;
use Quiote\Translation\CurrencyFormatter;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\QuioteException;

/**
 * Happy + failure path coverage for Quiote\Translation\CurrencyFormatter,
 * in particular the PHPStan level 9 hardening of initialize()/translate()/
 * localeChanged() against non-scalar-as-expected parameter values.
 */
class CurrencyFormatterTest extends UnitTestCase
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
            $info = $ctx->getFactoryInfo('translation_manager');
            if ($info === null || empty($info['class'])) {
                $ctx->setFactoryInfo('translation_manager', [
                    'class' => TranslationManager::class,
                    'parameters' => [],
                ]);
            }
            /** @var TranslationManager $tm */
            $tm = $ctx->createInstanceFor('translation_manager');
            $ro = new ReflectionObject($ctx);
            $prop = $ro->getProperty('translationManager');
            $prop->setValue($ctx, $tm);
            $seqProp = $ro->getProperty('shutdownSequence');
            $seq = $seqProp->getValue($ctx);
            if (is_array($seq) && !in_array($tm, $seq, true)) {
                $seq[] = $tm;
                $seqProp->setValue($ctx, $seq);
            }
            $tm->startup();
        }
        $this->tm = $tm;
    }

    public function testInitializeAcceptsStringRoundingMode(): void
    {
        $cf = new CurrencyFormatter();
        $cf->initialize($this->getContext(), ['rounding_mode' => 'floor', 'currency_code' => 'USD']);
        $this->assertSame('USD', $cf->getCurrencyCode());
    }

    public function testInitializeThrowsWhenRoundingModeIsNotAString(): void
    {
        $cf = new CurrencyFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('CurrencyFormatter::initialize() expects the "rounding_mode" parameter to be a string, int given.');
        $cf->initialize($this->getContext(), ['rounding_mode' => 1]);
    }

    public function testInitializeThrowsWhenTranslationDomainIsNotAString(): void
    {
        $cf = new CurrencyFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('CurrencyFormatter::initialize() expects the "translation_domain" parameter to be a string, int given.');
        $cf->initialize($this->getContext(), ['translation_domain' => 42]);
    }

    public function testInitializeThrowsWhenFormatIsNeitherArrayNorString(): void
    {
        $cf = new CurrencyFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('CurrencyFormatter::initialize() expects the "format" parameter to be an array or a string, int given.');
        $cf->initialize($this->getContext(), ['format' => 42]);
    }

    public function testInitializeThrowsWhenCurrencyCodeIsNotAString(): void
    {
        $cf = new CurrencyFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('CurrencyFormatter::initialize() expects the "currency_code" parameter to be a string, array given.');
        $cf->initialize($this->getContext(), ['currency_code' => ['USD']]);
    }

    public function testTranslateThrowsWhenMessageIsNotAnIntOrFloat(): void
    {
        $cf = new CurrencyFormatter();
        $cf->initialize($this->getContext(), ['currency_code' => 'USD']);
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('CurrencyFormatter::translate() expects $message to be an int or a float, string given.');
        $cf->translate('12.50', '');
    }

    public function testTranslateFormatsAnIntAmountForTheGivenLocale(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $cf = new CurrencyFormatter();
        $cf->initialize($this->getContext(), ['currency_code' => 'USD']);
        $result = $cf->translate(1000, '', $locale);
        $this->assertStringContainsString('1,000', $result);
    }

    public function testTranslateFormatsAFloatAmountForTheGivenLocale(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $cf = new CurrencyFormatter();
        $cf->initialize($this->getContext(), ['currency_code' => 'USD']);
        $result = $cf->translate(10.5, '', $locale);
        $this->assertStringContainsString('10.5', $result);
    }

    public function testResetClearsLocaleButPreservesCurrencyConfiguration(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $cf = new CurrencyFormatter();
        $cf->initialize($this->getContext(), ['currency_code' => 'USD']);
        $cf->localeChanged($locale);
        $before = $cf->translate(1000, '', $locale);

        $cf->reset();

        // context and currency_code are configured once at initialize() time
        // and never restored between requests in worker mode -- previously
        // CurrencyFormatter had no reset() override at all, so nothing here
        // was ever cleared, including the locale itself.
        $this->assertNotNull($cf->getContext());
        $this->assertSame('USD', $cf->getCurrencyCode());
        $after = $cf->translate(1000, '', $locale);
        $this->assertSame($before, $after);

        $ro = new ReflectionObject($cf);
        $localeProp = $ro->getProperty('locale');
        $this->assertNull($localeProp->getValue($cf), 'reset() must clear the directly-set locale to prevent cross-request bleed');
    }
}
