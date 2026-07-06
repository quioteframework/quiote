<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\ITranslator;
use Quiote\Translation\QuioteLocale;
use Quiote\Translation\SimpleTranslator;
use Quiote\Translation\TranslationManager;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;

/**
 * A minimal ITranslator double for exercising TranslationManager::_()/__() in
 * isolation, without depending on GettextTranslator's/SimpleTranslator's own
 * (separately tested) internal logic or real translation.xml fixture domains.
 */
class RecordingTestTranslator implements ITranslator
{
    /** @var array<int, mixed> */
    public array $seenMessages = [];

    public function getContext()
    {
        return null;
    }

    public function initialize(Context $context, array $parameters = [])
    {
    }

    public function translate($message, $domain, ?QuioteLocale $locale = null)
    {
        $this->seenMessages[] = $message;
        if (is_array($message)) {
            $parts = array_map(
                static fn (mixed $part): string => is_scalar($part) ? (string) $part : get_debug_type($part),
                $message
            );
            return 'plural:' . implode('|', $parts);
        }
        return 'translated:' . (is_scalar($message) ? (string) $message : get_debug_type($message));
    }

    public function localeChanged(QuioteLocale $newLocale)
    {
    }
}

/**
 * Happy + failure path coverage for TranslationManager gaps not already
 * exercised by TranslationManagerIntlTest/TranslationManagerNegativeTest:
 * simple getters, _()/__() message translation, and getDomainTranslator().
 */
class TranslationManagerAdditionalTest extends UnitTestCase
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

    /** Injects a translator double directly, bypassing translation.xml's config-driven wiring. */
    private function registerTranslator(string $domain, string $type, ITranslator $translator): void
    {
        $ro = new ReflectionObject($this->tm);
        $prop = $ro->getProperty('translators');
        $translators = $prop->getValue($this->tm);
        if (!is_array($translators)) {
            throw new \RuntimeException('Expected translators to be an array.');
        }
        $forDomain = $translators[$domain] ?? [];
        if (!is_array($forDomain)) {
            throw new \RuntimeException('Expected translators[domain] to be an array.');
        }
        $forDomain[$type] = $translator;
        $translators[$domain] = $forDomain;
        $prop->setValue($this->tm, $translators);
    }

    public function testGetAvailableLocalesReturnsConfiguredLocales(): void
    {
        $locales = $this->tm->getAvailableLocales();
        $this->assertNotEmpty($locales);
    }

    public function testGetCurrentLocaleReturnsAQuioteLocale(): void
    {
        $this->assertInstanceOf(QuioteLocale::class, $this->tm->getCurrentLocale());
    }

    public function testGetDefaultLocaleAndIdentifier(): void
    {
        $identifier = $this->tm->getDefaultLocaleIdentifier();
        $this->assertSame($identifier, $this->tm->getDefaultLocale()->getIdentifier());
    }

    public function testSetAndGetDefaultDomain(): void
    {
        $original = $this->tm->getDefaultDomain();
        $this->tm->setDefaultDomain('a_new_domain');
        $this->assertSame('a_new_domain', $this->tm->getDefaultDomain());
        $this->tm->setDefaultDomain($original);
    }

    public function testShutdownDoesNotThrow(): void
    {
        $this->tm->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testTranslateMessageDelegatesToDomainTranslator(): void
    {
        $translator = new RecordingTestTranslator();
        $this->registerTranslator('recording_domain', TranslationManager::MESSAGE, $translator);

        $result = $this->tm->_('Hello', 'recording_domain');

        $this->assertSame('translated:Hello', $result);
        $this->assertSame(['Hello'], $translator->seenMessages);
    }

    public function testTranslateMessageAppliesSprintfParameters(): void
    {
        $translator = new RecordingTestTranslator();
        $this->registerTranslator('recording_domain2', TranslationManager::MESSAGE, $translator);

        $result = $this->tm->_('Hello %s', 'recording_domain2', null, ['World']);

        // vsprintf() runs on the translator's output, so the placeholder is filled after translation.
        $this->assertSame('translated:Hello World', $result);
    }

    public function testTranslatePluralDelegatesAsThreeElementArray(): void
    {
        $translator = new RecordingTestTranslator();
        $this->registerTranslator('recording_domain3', TranslationManager::MESSAGE, $translator);

        $result = $this->tm->__('one item', 'many items', 5, 'recording_domain3');

        $this->assertSame('plural:one item|many items|5', $result);
        $this->assertSame([['one item', 'many items', 5]], $translator->seenMessages);
    }

    public function testGetDomainTranslatorReturnsRegisteredTranslator(): void
    {
        $translator = new RecordingTestTranslator();
        $this->registerTranslator('recording_domain4', TranslationManager::MESSAGE, $translator);

        $this->assertSame($translator, $this->tm->getDomainTranslator('recording_domain4', TranslationManager::MESSAGE));
    }

    public function testGetDomainTranslatorReturnsNullForUnknownDomain(): void
    {
        $this->assertNull($this->tm->getDomainTranslator('totally_unknown_domain', TranslationManager::MESSAGE));
    }

    public function testSetDefaultTimeZoneAcceptsStringOrDateTimeZone(): void
    {
        $this->tm->setDefaultTimeZone('UTC');
        // createTimeZone() canonicalizes via ICU; 'UTC' resolves to 'Etc/UTC'.
        $this->assertSame('Etc/UTC', $this->tm->getDefaultTimeZone()?->getName());

        $this->tm->setDefaultTimeZone(new DateTimeZone('Europe/Berlin'));
        $this->assertSame('Europe/Berlin', $this->tm->getDefaultTimeZone()?->getName());
    }

    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testGetCurrentTimeZoneDelegatesToGetDefaultTimeZone(): void
    {
        $this->tm->setDefaultTimeZone('UTC');
        $this->assertSame('Etc/UTC', $this->tm->getCurrentTimeZone()?->getName());
    }
}
