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

    private mixed $previousUseTranslation = null;

    /** Whether setUp() forced a manager onto the shared Context and must take it back off. */
    private bool $installedManager = false;

    #[\Override]
    protected function setUp(): void
    {
        ConfigCache::clear();
        $this->previousUseTranslation = Config::get('core.use_translation');
        Config::set('core.use_translation', true, true);
        $ctx = Context::getInstance();
        $tm = $ctx->getTranslationManager();
        if ($tm === null) {
            $this->installedManager = true;
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
            $ctx->getShutdownSequence()->append($tm);
            $tm->startup();
        }
        $this->tm = $tm;
    }

    /**
     * Everything setUp() did reaches beyond this test class: core.use_translation
     * is a global directive, and the manager is forced onto the shared Context by
     * reflection. Left in place, later tests in the same process render templates
     * with translations enabled against a manager this class also reset() --
     * which is how a whole group of renderer tests used to fail depending on
     * execution order.
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->installedManager) {
            $ctx = Context::getInstance();
            $ro = new \ReflectionObject($ctx);
            $ro->getProperty('translationManager')->setValue($ctx, null);
            $installed = $this->tm;
            $ctx->getShutdownSequence()->remove(
                static fn(object $component): bool => $component === $installed
            );
            $this->installedManager = false;
        }
        Config::set('core.use_translation', $this->previousUseTranslation, true);
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
