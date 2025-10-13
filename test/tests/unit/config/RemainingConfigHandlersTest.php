<?php

use PHPUnit\Framework\TestCase;
use Agavi\Config\AgaviCachingConfigHandler;
use Agavi\Config\AgaviCompileConfigHandler;
use Agavi\Config\AgaviConfigHandlersConfigHandler;
use Agavi\Config\AgaviReturnArrayConfigHandler;
use Agavi\Config\AgaviRoutingConfigHandler;
use Agavi\Config\AgaviWsdlConfigHandler;
use Agavi\Config\Util\DOM\AgaviXmlConfigDomDocument;
use Agavi\Config\AgaviConfig;
use Agavi\AgaviContext;

class RemainingConfigHandlersTest extends TestCase
{
    private function envelope(string $inner, string $uri): AgaviXmlConfigDomDocument
    {
        $xml = <<<XML
<?xml version="1.0"?>
<configurations xmlns="http://agavi.org/agavi/config/global/envelope/1.1">
  <configuration>
    $inner
  </configuration>
</configurations>
XML;
        $doc = new AgaviXmlConfigDomDocument();
        $doc->loadXml($xml);
        $r = new ReflectionProperty(AgaviXmlConfigDomDocument::class, 'documentURI');
        $r->setAccessible(true);
        $r->setValue($doc, sys_get_temp_dir() . '/' . $uri);
        return $doc;
    }

    public function testCachingConfigHandlerMinimal()
    {
        $ns = 'http://agavi.org/agavi/config/parts/caching/1.1';
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
        $h = new AgaviCachingConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->envelope($inner, 'caching.xml'));
        $this->assertIsString($code);
        $this->assertStringContainsString('grp', $code);
    }

    public function testCompileConfigHandlerAggregates()
    {
    // Force non-debug mode so AgaviCompileConfigHandler inlines file contents
    \Agavi\Config\AgaviConfig::set('core.debug', false, true, true);
        $tmpFile = tempnam(sys_get_temp_dir(), 'cmp');
        file_put_contents($tmpFile, "<?php\\n// comment\\necho 'X';");
        $ns = 'http://agavi.org/agavi/config/parts/compile/1.1';
        // use absolute path for compile file
        $inner = <<<XML
<compiles xmlns="$ns">
  <compile>$tmpFile</compile>
</compiles>
XML;
        $h = new AgaviCompileConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->envelope($inner, 'compile.xml'));
        $this->assertIsString($code);
        // In non-debug mode formatted file contents are inlined; debug mode would use require(). We only need to see our echo statement.
        $this->assertStringContainsString("echo 'X';", $code);
    }

    public function testConfigHandlersConfigHandlerListsEntry()
    {
        $ns = 'http://agavi.org/agavi/config/parts/config_handlers/1.1';
        $inner = <<<XML
<handlers xmlns="$ns">
  <handler pattern="%core.app_dir%/foo.xml" class="FooHandler" />
</handlers>
XML;
        $h = new AgaviConfigHandlersConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->envelope($inner, 'config_handlers.xml'));
        $this->assertIsString($code);
        $this->assertStringContainsString('FooHandler', $code);
    }

    public function testReturnArrayConfigHandlerReturnsPhpArray()
    {
        $ns = 'http://agavi.org/agavi/config/parts/return_array/1.1';
        // Use an element with children; the handler places content into associative structure.
        $inner = <<<XML
<root xmlns="$ns">
  <simple name="simple">
    <a>1</a>
    <b>two</b>
  </simple>
</root>
XML;
        $h = new AgaviReturnArrayConfigHandler();
        $h->initialize(null, ['namespace_uri' => $ns]);
        $code = $h->execute($this->envelope($inner, 'return_array.xml'));
        $this->assertStringContainsString('simple', $code, $code);
        $this->assertStringContainsString('two', $code);
    }

   

    public function testWsdlConfigHandlerBasic()
    {
        $this->markTestSkipped('WSDL handler requires full definitions structure and routing parameters; skipped in basic coverage sweep.');
    }
}
