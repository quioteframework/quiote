<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\ModuleConfigHandler;
use Quiote\Config\SettingConfigHandler;
use Quiote\Config\OutputTypeConfigHandler;
use Quiote\Config\TranslationConfigHandler;
use Quiote\Config\DatabaseConfigHandler;
use Quiote\Config\RbacDefinitionConfigHandler;
use Quiote\Config\TestSuitesConfigHandler;
use Quiote\Config\ValidatorConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Config;

/**
 * Basic smoke tests for remaining config handlers. These ensure they can initialize and execute
 * against minimal envelopes without throwing. We explicitly avoid routing handler XML since routing
 * now uses programmatic setup (no XML dependency expected in new tests).
 */
class BasicConfigHandlersTest extends TestCase
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

  public function testModuleConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/modules/1.1';
        $inner = <<<XML
<modules xmlns="$ns">
  <module name="Default" enabled="true" />
</modules>
XML;
        $h = new ModuleConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'modules.xml'));
    $this->assertStringContainsString('ModuleConfigHandler', $code);
    }

    public function testSettingConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/settings/1.1';
        $inner = <<<XML
<settings xmlns="$ns">
  <setting name="core.use_logging">true</setting>
</settings>
XML;
        $h = new SettingConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'settings.xml'));
        $this->assertStringContainsString('core.use_logging', $code);
    }

    public function testOutputTypeConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/output_types/1.1';
        $inner = <<<XML
<output_types xmlns="$ns" default="html">
  <output_type name="html" class="StdClass" />
</output_types>
XML;
        $h = new OutputTypeConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'output_types.xml'));
        $this->assertStringContainsString('html', $code);
    }

  public function testTranslationConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/translation/1.1';
        $inner = <<<XML
<catalogues xmlns="$ns">
  <catalogue name="messages" />
</catalogues>
XML;
        $h = new TranslationConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'translation.xml'));
    $this->assertStringContainsString('TranslationConfigHandler', $code);
    }

    public function testDatabaseConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/databases/1.1';
        $inner = <<<XML
<databases xmlns="$ns" default="main">
  <database name="main" class="StdClass" />
</databases>
XML;
        $h = new DatabaseConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'databases.xml'));
        $this->assertStringContainsString('main', $code);
    }

    public function testRbacDefinitionConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/rbac/1.1';
        $inner = <<<XML
<rbac xmlns="$ns">
  <roles />
</rbac>
XML;
        $h = new RbacDefinitionConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'rbac.xml'));
        $this->assertStringContainsString('RbacDefinitionConfigHandler', $code);
    }


  public function testTestSuitesConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/test_suites/1.1';
        $inner = <<<XML
<test_suites xmlns="$ns">
  <test_suite name="unit"/> 
</test_suites>
XML;
        $h = new TestSuitesConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'testsuites.xml'));
    $this->assertStringContainsString('TestSuitesConfigHandler', $code);
    }

    public function testValidatorConfigHandler(): void
    {
        $ns = 'http://quiote.dev/quiote/config/parts/validators/1.1';
        $inner = <<<XML
<validators xmlns="$ns">
  <validator class="StdClass" name="v1" />
</validators>
XML;
        $h = new ValidatorConfigHandler();
        $h->initialize(null, []);
        $code = $h->execute($this->wrap($inner, 'validators.xml'));
        $this->assertStringContainsString('v1', $code);
    }
}
