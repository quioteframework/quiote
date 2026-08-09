<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Config\SettingConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * A `<setting>` element isn't scalar-only: nested unnamed `<ae:parameter>`
 * children compile it to a list array, the same shape a YAML sequence or a
 * PHP array literal produces for the same key. This locks in that a
 * list-valued core setting (e.g. `core.stealth_additional_headers`) behaves
 * identically regardless of which settings format an app uses.
 */
class SettingConfigHandlerListValueTest extends TestCase
{
    private function wrap(string $inner, string $uri): XmlConfigDomDocument
    {
        $xml = <<<XML
<?xml version="1.0"?>
<configurations xmlns="http://quiote.dev/quiote/config/global/envelope/1.1">
  <configuration>
    $inner
  </configuration>
</configurations>
XML;
        $doc = new XmlConfigDomDocument();
        $doc->loadXml($xml);
        $r = new ReflectionProperty(XmlConfigDomDocument::class, 'documentURI');
        $r->setValue($doc, sys_get_temp_dir() . '/' . $uri);
        return $doc;
    }

    public function testNestedUnnamedParametersCompileToListArray(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/settings/1.1';
        $envelopeNs = 'http://quiote.dev/quiote/config/global/envelope/1.1';
        $inner = <<<XML
<settings xmlns="$ns" xmlns:ae="$envelopeNs">
  <setting name="stealth_additional_headers">
    <ae:parameter>X-Powered-By</ae:parameter>
    <ae:parameter>X-Custom-Backend</ae:parameter>
  </setting>
</settings>
XML;
        $h = new SettingConfigHandler();
        $h->initialize(null, []);
        $declaration = $h->execute($this->wrap($inner, 'settings_list.xml'));

        $this->assertIsArray($declaration);
        $this->assertArrayHasKey('core.stealth_additional_headers', $declaration);
        $this->assertSame(['X-Powered-By', 'X-Custom-Backend'], $declaration['core.stealth_additional_headers']);
    }

    public function testCompiledListValueReadsAsStringListThroughConfig(): void
    {
        $hadKey = Config::has('core.stealth_additional_headers');
        $previous = $hadKey ? Config::get('core.stealth_additional_headers') : null;

        Config::set('core.stealth_additional_headers', ['X-Powered-By', 'X-Custom-Backend']);
        try {
            $this->assertSame(
                ['X-Powered-By', 'X-Custom-Backend'],
                Config::getStringList('core.stealth_additional_headers')
            );
        } finally {
            if ($hadKey) {
                Config::set('core.stealth_additional_headers', $previous);
            } else {
                Config::remove('core.stealth_additional_headers');
            }
        }
    }
}
