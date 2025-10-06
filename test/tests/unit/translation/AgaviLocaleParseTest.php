<?php

use PHPUnit\Framework\TestCase;
use Agavi\Translation\AgaviLocale;
use Agavi\Exception\AgaviException;

class AgaviLocaleParseTest extends TestCase
{
    public function testMixedSeparatorsAndMultipleOptions(): void
    {
        $id = 'de_DE@timezone=Europe/Berlin;currency=EUR,numbers=latn';
        $data = AgaviLocale::parseLocaleIdentifier($id);
        $this->assertSame('de', $data['language']);
        $this->assertSame('DE', $data['territory']);
        $this->assertArrayHasKey('timezone', $data['options']);
        $this->assertArrayHasKey('currency', $data['options']);
        $this->assertArrayHasKey('numbers', $data['options']);
        $this->assertSame('Europe/Berlin', $data['options']['timezone']);
        $this->assertSame('EUR', $data['options']['currency']);
        $this->assertSame('latn', $data['options']['numbers']);
    }

    public function testOptionOnlyIdentifierRequiresFallback(): void
    {
        // parseLocaleIdentifier will throw because language component is mandatory.
        $this->expectException(AgaviException::class);
        AgaviLocale::parseLocaleIdentifier('@timezone=UTC');
    }

    public function testNormalizationCaseSensitivityIsNotApplied(): void
    {
        // Current parser preserves original case for language/script/territory parts.
        $id = 'zh_hans_cn@timezone=Asia/Shanghai';
        $data = AgaviLocale::parseLocaleIdentifier($id);
        $this->assertSame('zh', $data['language']);
        // Because of the flexible script group, a 4+ char piece after underscore and before next underscore becomes script.
        // Here 'hans' is taken as script and 'cn' as territory.
        $this->assertSame('hans', $data['script']);
        $this->assertSame('cn', $data['territory']);
    }

    public function testDuplicateOptionLastWins(): void
    {
        $id = 'en_US@currency=EUR,currency=USD';
        $data = AgaviLocale::parseLocaleIdentifier($id);
        // Later assignment overwrites earlier one in associative array.
        $this->assertSame('USD', $data['options']['currency']);
    }

    public function testFlagOptionWithoutValue(): void
    {
        $id = 'en_US@featureX';
        $data = AgaviLocale::parseLocaleIdentifier($id);
        $this->assertArrayHasKey('featureX', $data['options']);
        $this->assertSame('', $data['options']['featureX']);
    }

    public function testInvalidLocaleThrows(): void
    {
        $this->expectException(AgaviException::class);
        AgaviLocale::parseLocaleIdentifier('');
    }

    public function testLookupPathOrdering(): void
    {
        $paths = AgaviLocale::getLookupPath('en_US_POSIX');
        // getLookupPath returns reverse order (most specific first) per implementation then reversed again.
        // For en_US_POSIX (language=en, territory=US, variant=POSIX): expected reversed stack: [en_US_POSIX, en_US, en]
        $this->assertSame(['en_US_POSIX','en_US','en'], $paths);
    }

    public function testLookupPathWithScriptAndTerritory(): void
    {
        $paths = AgaviLocale::getLookupPath('zh_Hans_CN');
        // Expect script+territory variants ordered from most specific to least
        $this->assertSame(['zh_Hans_CN','zh_Hans','zh_CN','zh'], $paths);
    }

    public function testLookupPathWithScriptTerritoryAndVariant(): void
    {
        $paths = AgaviLocale::getLookupPath('sl_Latn_SI_NEDIS');
        // Order (most specific first) including script+territory+variant combinations
        $this->assertSame(['sl_Latn_SI_NEDIS','sl_Latn_SI','sl_Latn','sl_SI_NEDIS','sl_SI','sl'], $paths);
    }

    public function testParsedArrayAcceptedByGetLookupPath(): void
    {
        $parsed = AgaviLocale::parseLocaleIdentifier('de_AT');
        $paths = AgaviLocale::getLookupPath($parsed);
        $this->assertSame(['de_AT','de'], $paths);
    }

    public function testMultipleFlagOptions(): void
    {
        $data = AgaviLocale::parseLocaleIdentifier('en@featureX,featureY');
        $this->assertArrayHasKey('featureX', $data['options']);
        $this->assertArrayHasKey('featureY', $data['options']);
        $this->assertSame('', $data['options']['featureX']);
        $this->assertSame('', $data['options']['featureY']);
    }

    public function testLocaleStrBaseExtraction(): void
    {
        $data = AgaviLocale::parseLocaleIdentifier('fr_FR@timezone=Europe/Paris');
        $this->assertSame('fr_FR', $data['locale_str']);
        $this->assertSame('@timezone=Europe/Paris', $data['option_str']);
    }
}

?>