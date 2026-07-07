<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\QuioteLocale;
use Quiote\Translation\SimpleTranslator;

/**
 * Documents (and locks in) SimpleTranslator's domain-key model:
 * TranslationManager::getTranslators() passes whatever's LEFT of the
 * requested domain string after matching a registered translator name, not
 * the translator's own declared domain name. For a translator with no
 * nested children that's always the empty string -- a translator registered
 * as "default" receiving a request for domain "default" consumes the whole
 * string, leaving nothing over. Keying a translator's own messages by its
 * declared domain name (the natural first guess) silently returns
 * untranslated message keys instead of throwing. See TranslationManager's
 * getTranslators() docblock for the matching mechanics.
 */
class SimpleTranslatorTest extends UnitTestCase
{
    private function makeLocale(string $identifier): QuioteLocale
    {
        $loc = new QuioteLocale();
        $ctx = $this->createStub(Context::class);
        $loc->initialize($ctx, [], $identifier, []);
        return $loc;
    }

    public function testTopLevelTranslatorMessagesMustBeKeyedByEmptyStringDomain(): void
    {
        $translator = new SimpleTranslator();
        $translator->initialize($this->getContext(), [
            '' => ['en_US' => ['greeting' => 'Hello!']],
        ]);
        $translator->localeChanged($this->makeLocale('en_US'));

        $this->assertSame('Hello!', $translator->translate('greeting', ''));
    }

    public function testKeyingByTheTranslatorsOwnDeclaredDomainNameSilentlyFailsToTranslate(): void
    {
        $translator = new SimpleTranslator();
        $translator->initialize($this->getContext(), [
            'default' => ['en_US' => ['greeting' => 'Hello!']],
        ]);
        $translator->localeChanged($this->makeLocale('en_US'));

        // No exception, no error -- just the raw, untranslated message key.
        $this->assertSame('greeting', $translator->translate('greeting', ''));
    }

    public function testNestedTranslatorMessagesAreKeyedByTheLeftoverSuffix(): void
    {
        // Mirrors a <translator domain="errors"> nested inside <translator
        // domain="default">: TranslationManager passes "errors" (the segment
        // left over after "default" is matched by the parent) as the domain.
        $translator = new SimpleTranslator();
        $translator->initialize($this->getContext(), [
            'errors' => ['en_US' => ['login_failed' => 'Login failed.']],
        ]);
        $translator->localeChanged($this->makeLocale('en_US'));

        $this->assertSame('Login failed.', $translator->translate('login_failed', 'errors'));
    }

    public function testUnknownMessageKeyFallsBackToTheMessageItself(): void
    {
        $translator = new SimpleTranslator();
        $translator->initialize($this->getContext(), [
            '' => ['en_US' => ['greeting' => 'Hello!']],
        ]);
        $translator->localeChanged($this->makeLocale('en_US'));

        $this->assertSame('unknown_key', $translator->translate('unknown_key', ''));
    }
}
