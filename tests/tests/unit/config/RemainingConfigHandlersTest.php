<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\CachingConfigHandler;
use Quiote\Config\CompileConfigHandler;
use Quiote\Config\ConfigHandlersConfigHandler;
use Quiote\Config\ReturnArrayConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Config;
use Quiote\Context;

class RemainingConfigHandlersTest extends TestCase
{
    private function envelope(string $inner, string $uri): XmlConfigDomDocument
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

    public function testCachingConfigHandlerMinimal(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/caching/1.1';
        $inner = <<<XML
<cachings xmlns="$ns">
  <caching>
    <groups>
      <group source="string">grp</group>
    </groups>
    <output_types>
      <output_type name="html">
        <layers>
          <layer name="layout" />
        </layers>
      </output_type>
    </output_types>
  </caching>
</cachings>
XML;
        $h = new CachingConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->envelope($inner, 'caching.xml'));
        $this->assertStringContainsString('grp', var_export($code, true));
    }

    public function testCompileConfigHandlerAggregates(): void
    {
    // Force non-debug mode so CompileConfigHandler inlines file contents
    \Quiote\Config\Config::set('core.debug', false, true, true);
        $tmpFile = tempnam(sys_get_temp_dir(), 'cmp');
        file_put_contents($tmpFile, "<?php\\n// comment\\necho 'X';");
        $ns = 'http://quiote.dev/quiote/config/parts/compile/1.1';
        // use absolute path for compile file
        $inner = <<<XML
<compiles xmlns="$ns">
  <compile>$tmpFile</compile>
</compiles>
XML;
        $h = new CompileConfigHandler();
        $h->initialize(null, []);
        $declaration = $h->execute($this->envelope($inner, 'compile.xml'));
        // In non-debug mode the declaration carries the formatted file contents keyed by path; debug
        // mode would carry a require() instead. We only need to see our echo statement.
        $this->assertIsArray($declaration);
        $contents = array_values($declaration)[0] ?? null;
        $this->assertIsString($contents);
        $this->assertStringContainsString("echo 'X';", $contents);
    }

    public function testConfigHandlersConfigHandlerListsEntry(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/config_handlers/1.1';
        $inner = <<<XML
<handlers xmlns="$ns">
  <handler pattern="%core.app_dir%/foo.xml" class="FooHandler" />
</handlers>
XML;
        $h = new ConfigHandlersConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->envelope($inner, 'config_handlers.xml'));
        $this->assertStringContainsString('FooHandler', var_export($code, true));
    }

    public function testReturnArrayConfigHandlerReturnsPhpArray(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/return_array/1.1';
        // Use an element with children; the handler places content into associative structure.
        $inner = <<<XML
<root xmlns="$ns">
  <simple name="simple">
    <a>1</a>
    <b>two</b>
  </simple>
</root>
XML;
        $h = new ReturnArrayConfigHandler();
        $h->initialize(null, ['namespace_uri' => $ns]);
        $code = $h->execute($this->envelope($inner, 'return_array.xml'));
        $this->assertStringContainsString('simple', var_export($code, true));
        $this->assertStringContainsString('two', var_export($code, true));
    }
}
