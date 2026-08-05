<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Translation\QuioteLocale;

/**
 * `QuioteLocale::getCharacterOrientation()` -- the answer a template needs to decide `dir="rtl"`.
 *
 * The subject is script resolution rather than a lookup table. A bare `ar` or `ur` names no script, so
 * the direction has to come from what ICU says the language is written in by default; an identifier
 * that does name one has to be taken at its word, which is the case a language-keyed table gets wrong.
 */
class LocaleCharacterOrientationTest extends TestCase
{
    private function makeLocale(string $identifier): QuioteLocale
    {
        $locale = new QuioteLocale();
        (new ReflectionMethod($locale, 'initialize'))
            ->invoke($locale, $this->createStub(\Quiote\Context::class), [], $identifier, []);

        return $locale;
    }

    #[DataProvider('orientationProvider')]
    public function testOrientationIsResolvedFromTheScript(string $identifier, string $expected): void
    {
        $this->assertSame($expected, $this->makeLocale($identifier)->getCharacterOrientation());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function orientationProvider(): array
    {
        return [
            // No script in the identifier: ICU has to supply the likely one.
            'arabic' => ['ar', 'right-to-left'],
            'hebrew' => ['he', 'right-to-left'],
            'persian' => ['fa', 'right-to-left'],
            'urdu' => ['ur', 'right-to-left'],
            'dhivehi' => ['dv', 'right-to-left'],
            'arabic with territory' => ['ar_EG', 'right-to-left'],
            'hebrew with territory' => ['he_IL', 'right-to-left'],

            'english' => ['en', 'left-to-right'],
            'finnish' => ['fi', 'left-to-right'],
            'german with territory' => ['de_DE', 'left-to-right'],
            'japanese' => ['ja', 'left-to-right'],

            // The case a language-keyed table cannot answer: one language, three scripts, and the
            // identifier says which.
            'azerbaijani in arabic script' => ['az_Arab', 'right-to-left'],
            'azerbaijani in latin script' => ['az_Latn', 'left-to-right'],
            'azerbaijani in cyrillic script' => ['az_Cyrl', 'left-to-right'],
        ];
    }

    /**
     * An identifier carrying a keyword: the keyword is not part of the language tag, and stripping it
     * is what lets ICU resolve the rest.
     */
    public function testAKeywordDoesNotDefeatResolution(): void
    {
        $this->assertSame(
            'right-to-left',
            $this->makeLocale('ar_EG@calendar=islamic')->getCharacterOrientation(),
        );
    }

    /**
     * Nothing identifiable: left-to-right rather than an exception or an empty string. A page laid out
     * left to right for an unknown locale is a smaller wrong than one laid out backwards, and a
     * template comparing against `right-to-left` needs an answer, not a null.
     */
    #[DataProvider('unidentifiableProvider')]
    public function testAnUnidentifiableLocaleReadsLeftToRight(string $identifier): void
    {
        $this->assertSame('left-to-right', $this->makeLocale($identifier)->getCharacterOrientation());
    }

    /** @return array<string, array{0: string}> */
    public static function unidentifiableProvider(): array
    {
        return [
            'root' => ['root'],
            'undetermined' => ['und'],
            'not a language at all' => ['zz_ZZ'],
        ];
    }

    /**
     * A currency's name in this locale, which is the other accessor an application turned out to be
     * calling. ICU holds the data; there is no table here to go stale.
     */
    #[DataProvider('currencyNameProvider')]
    public function testCurrencyDisplayNameComesFromTheLocalesOwnData(
        string $identifier,
        string $code,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->makeLocale($identifier)->getCurrencyDisplayName($code));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function currencyNameProvider(): array
    {
        return [
            'euro in english' => ['en', 'EUR', 'Euro'],
            'euro in finnish' => ['fi', 'EUR', 'euro'],
            'dollar in english' => ['en', 'USD', 'US Dollar'],
            // A locale with no currency data of its own: ICU falls back to its parent rather than
            // answering nothing.
            'euro in a regional locale' => ['en_GB', 'EUR', 'Euro'],
        ];
    }

    public function testAnUnknownCurrencyCodeIsNull(): void
    {
        $this->assertNull($this->makeLocale('en')->getCurrencyDisplayName('ZZZ'));
    }

    /**
     * Declared data wins over ICU, so a caller that supplied its own name gets it back.
     */
    public function testADeclaredCurrencyNameWinsOverIcu(): void
    {
        $locale = new QuioteLocale();
        (new ReflectionMethod($locale, 'initialize'))->invoke(
            $locale,
            $this->createStub(\Quiote\Context::class),
            [],
            'en',
            ['numbers' => ['currencies' => ['EUR' => ['displayName' => 'Single Currency']]]],
        );

        $this->assertSame('Single Currency', $locale->getCurrencyDisplayName('EUR'));
    }

    /**
     * The declared script wins over ICU's guess, which is what makes an explicitly-tagged locale
     * trustworthy: the data a caller supplied is not overridden by a probe.
     */
    public function testADeclaredScriptIsNotOverriddenByTheLikelyOne(): void
    {
        $locale = new QuioteLocale();
        (new ReflectionMethod($locale, 'initialize'))->invoke(
            $locale,
            $this->createStub(\Quiote\Context::class),
            [],
            'ar_EG',
            ['locale' => ['script' => 'Latn']],
        );

        $this->assertSame('left-to-right', $locale->getCharacterOrientation());
    }
}
