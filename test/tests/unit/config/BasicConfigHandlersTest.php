<?php

use PHPUnit\Framework\TestCase;
use Agavi\Config\AgaviModuleConfigHandler;
use Agavi\Config\AgaviSettingConfigHandler;
use Agavi\Config\AgaviOutputTypeConfigHandler;
use Agavi\Config\AgaviTranslationConfigHandler;
use Agavi\Config\AgaviDatabaseConfigHandler;
use Agavi\Config\AgaviRbacDefinitionConfigHandler;
use Agavi\Config\AgaviTestSuitesConfigHandler;
use Agavi\Config\AgaviValidatorConfigHandler;
use Agavi\Config\Util\DOM\AgaviXmlConfigDomDocument;
use Agavi\Config\AgaviConfig;

/**
 * Basic smoke tests for remaining config handlers. These ensure they can initialize and execute
 * against minimal envelopes without throwing. We explicitly avoid routing handler XML since routing
 * now uses programmatic setup (no XML dependency expected in new tests).
 */
class BasicConfigHandlersTest extends TestCase
{
    private function wrap(string $inner, string $uri): AgaviXmlConfigDomDocument
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
        
        $r->setValue($doc, sys_get_temp_dir() . '/' . $uri);
        return $doc;
    }

  public function testModuleConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/modules/1.1';
        $inner = <<<XML
<modules xmlns="$ns">
  <module name="Default" enabled="true" />
</modules>
XML;
        $h = new AgaviModuleConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'modules.xml'));
    $this->assertIsString($code);
    $this->assertStringContainsString('AgaviModuleConfigHandler', $code);
    }

    public function testSettingConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/settings/1.1';
        $inner = <<<XML
<settings xmlns="$ns">
  <setting name="core.use_logging">true</setting>
</settings>
XML;
        $h = new AgaviSettingConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'settings.xml'));
        $this->assertStringContainsString('core.use_logging', $code);
    }

    public function testOutputTypeConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/output_types/1.1';
        $inner = <<<XML
<output_types xmlns="$ns" default="html">
  <output_type name="html" class="StdClass" />
</output_types>
XML;
        $h = new AgaviOutputTypeConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'output_types.xml'));
        $this->assertStringContainsString('html', $code);
    }

  public function testTranslationConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/translation/1.1';
        $inner = <<<XML
<catalogues xmlns="$ns">
  <catalogue name="messages" />
</catalogues>
XML;
        $h = new AgaviTranslationConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'translation.xml'));
    $this->assertIsString($code);
    $this->assertStringContainsString('AgaviTranslationConfigHandler', $code);
    }

    public function testDatabaseConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/databases/1.1';
        $inner = <<<XML
<databases xmlns="$ns" default="main">
  <database name="main" class="StdClass" />
</databases>
XML;
        $h = new AgaviDatabaseConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'databases.xml'));
        $this->assertStringContainsString('main', $code);
    }

    public function testRbacDefinitionConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/rbac/1.1';
        $inner = <<<XML
<rbac xmlns="$ns">
  <roles />
</rbac>
XML;
        $h = new AgaviRbacDefinitionConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'rbac.xml'));
        $this->assertIsString($code);
    }


  public function testTestSuitesConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/test_suites/1.1';
        $inner = <<<XML
<test_suites xmlns="$ns">
  <test_suite name="unit"/> 
</test_suites>
XML;
        $h = new AgaviTestSuitesConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'testsuites.xml'));
    $this->assertIsString($code);
    $this->assertStringContainsString('AgaviTestSuitesConfigHandler', $code);
    }

    public function testValidatorConfigHandler()
    {
        $ns = 'http://agavi.org/agavi/config/parts/validators/1.1';
        $inner = <<<XML
<validators xmlns="$ns">
  <validator class="StdClass" name="v1" />
</validators>
XML;
        $h = new AgaviValidatorConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'validators.xml'));
        $this->assertStringContainsString('v1', $code);
    }
}
