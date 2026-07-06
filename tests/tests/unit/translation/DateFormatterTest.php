<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\TranslationManager;
use Quiote\Translation\DateFormatter;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\QuioteException;

/**
 * Happy + failure path coverage for Quiote\Translation\DateFormatter, which
 * previously had no dedicated test file at all.
 */
class DateFormatterTest extends UnitTestCase
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

    public function testTranslateThrowsWhenNoLocalePrepared(): void
    {
        $df = new DateFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('DateFormatter has not been prepared with a locale yet.');
        $df->translate(new DateTimeImmutable('2024-03-15'), '');
    }

    public function testTranslateWithExplicitLocaleFormatsDate(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $result = $df->translate(new DateTimeImmutable('2024-03-15 14:30:00', new DateTimeZone('UTC')), '', $locale);
        $this->assertStringContainsString('2024', $result);
    }

    public function testTranslateWithUnixTimestampInt(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $result = $df->translate(1710512000, '', $locale);
        $this->assertNotEmpty($result);
    }

    public function testTranslateWithNumericStringTimestamp(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $result = $df->translate('1710512000', '', $locale);
        $this->assertNotEmpty($result);
    }

    public function testTranslateWithParsableDateString(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $result = $df->translate('2024-03-15', '', $locale);
        $this->assertStringContainsString('2024', $result);
    }

    public function testTranslateThrowsOnUnparsableDateString(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/Unable to parse date string/');
        $df->translate('not-a-real-date-string!!', '', $locale);
    }

    public function testTranslateThrowsOnUnsupportedValueType(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $df = new DateFormatter();
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('Unsupported datetime value supplied to DateFormatter.');
        $df->translate([1, 2, 3], '', $locale);
    }

    public function testResolveFormatReturnsSpecifierPatternForKnownSpecifiers(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $pattern = DateFormatter::resolveFormat('short', $locale);
        $this->assertNotEquals('short', $pattern);
    }

    public function testResolveFormatReturnsOriginalWhenNotASpecifier(): void
    {
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $pattern = DateFormatter::resolveFormat('yyyy-MM-dd', $locale);
        $this->assertEquals('yyyy-MM-dd', $pattern);
    }

    public function testGetContextReturnsInitializedContext(): void
    {
        $df = new DateFormatter();
        $df->initialize($this->getContext());
        $this->assertSame($this->getContext(), $df->getContext());
    }

    public function testInitializeAppliesTypeAndCustomFormatParameters(): void
    {
        $df = new DateFormatter();
        $df->initialize($this->getContext(), ['type' => 'date', 'format' => 'yyyy-MM-dd']);
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $result = $df->translate(new DateTimeImmutable('2024-03-15'), '', $locale);
        $this->assertEquals('2024-03-15', $result);
    }

    public function testInitializeWithArrayFormatMapPicksMostSpecificLocaleMatch(): void
    {
        $df = new DateFormatter();
        $df->initialize($this->getContext(), [
            'type' => 'date',
            'format' => ['en_US' => 'yyyy-MM-dd', 'en' => 'MM/dd/yyyy'],
        ]);
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $result = $df->translate(new DateTimeImmutable('2024-03-15'), '', $locale);
        $this->assertEquals('2024-03-15', $result);
    }

    public function testInitializeIgnoresUnknownTypeAndDefaultsToDatetime(): void
    {
        $df = new DateFormatter();
        $df->initialize($this->getContext(), ['type' => 'bogus']);
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $result = $df->translate(new DateTimeImmutable('2024-03-15 10:00:00'), '', $locale);
        $this->assertNotEmpty($result);
    }

    public function testResetRestoresDefaults(): void
    {
        $df = new DateFormatter();
        $df->initialize($this->getContext(), ['type' => 'date', 'format' => 'yyyy-MM-dd']);
        $locale = $this->tm->getLocale('en_US@timezone=UTC');
        $this->assertEquals('2024-03-15', $df->translate(new DateTimeImmutable('2024-03-15'), '', $locale));

        $df->reset();

        // After reset, the custom format is gone; formatting falls back to the default "medium" specifier pattern.
        $result = $df->translate(new DateTimeImmutable('2024-03-15'), '', $locale);
        $this->assertNotEquals('2024-03-15', $result);
    }

    public function testTranslateViaTranslationManagerDefaultDomain(): void
    {
        // Exercises the <date_formatter> configured on the sandbox app's default
        // translation domain (type=datetime, format=full) end to end.
        $result = $this->tm->_d(new DateTimeImmutable('2024-03-15 14:30:00'), null, 'en_US');
        $this->assertNotEmpty($result);
    }
}
