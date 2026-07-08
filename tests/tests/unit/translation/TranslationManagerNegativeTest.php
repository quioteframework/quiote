<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Context;
use Quiote\Translation\TranslationManager;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\QuioteException;

/**
 * Negative path and edge case tests for the intl-based TranslationManager.
 */
class TranslationManagerNegativeTest extends UnitTestCase
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
            $ro = new \ReflectionObject($ctx);
            $prop = $ro->getProperty('translationManager');
            
            $prop->setValue($ctx, $tm);
            $seqProp = $ro->getProperty('shutdownSequence');
            
            $seq = $seqProp->getValue($ctx);
            if (!in_array($tm, $seq, true)) { $seq[] = $tm; $seqProp->setValue($ctx, $seq); }
            $tm->startup();
        }
        $this->tm = $tm;
    }

    public function testInvalidLocaleIdentifierThrows(): void
    {
        $this->expectException(QuioteException::class);
        $this->tm->getLocale('!!invalid');
    }

    public function testEmptyLocaleIdentifierThrows(): void
    {
        $this->expectException(QuioteException::class);
        $this->tm->getLocale('');
    }

    public function testShortcutWithoutBaseLocaleFails(): void
    {
        // After intl refactor we now allow option-only shortcut if a default locale is configured.
        // So reset() then perform a shortcut should succeed (no exception) producing a locale using default base.
        $this->tm->reset();
        $loc = $this->tm->getLocale('@timezone=UTC');
        $this->assertEquals('UTC', $loc->getLocaleTimeZone());
    }

    public function testResolveTimeZoneIdInvalidReturnsOriginalOrNull(): void
    {
        $res = $this->tm->resolveTimeZoneId('Not/A_Real_Zone');
        // Our resolveTimeZoneId returns candidate or canonical; for invalid it returns the input string
        $this->assertEquals('Not/A_Real_Zone', $res);
    }

    public function testCreateTimeZoneInvalid(): void
    {
        $this->assertNull($this->tm->createTimeZone('')); // empty
        $this->assertNull($this->tm->createTimeZone('GMT+99:99')); // impossible offset
    }

    public function testCurrencyFractionEmptyFallback(): void
    {
        $this->assertEquals(['digits' => 2, 'rounding' => 0], $this->tm->getCurrencyFraction('')); // empty => default
    }
}
