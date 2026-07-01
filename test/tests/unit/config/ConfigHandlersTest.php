<?php

use PHPUnit\Framework\TestCase;
use Agavi\Config\AgaviFactoryConfigHandler;
use Agavi\Config\AgaviFilterConfigHandler;
use Agavi\Config\Util\DOM\AgaviXmlConfigDomDocument;
use Agavi\Config\AgaviConfig;
use Agavi\Exception\AgaviConfigurationException;

class ConfigHandlersTest extends TestCase
{
    /** @var array Snapshot of AgaviConfig taken in setUp() and restored in tearDown(). */
    private array $configSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        // These tests flip global directives such as core.use_translation /
        // core.use_logging to drive the factory config handler's code generation.
        // AgaviConfig is process-wide; snapshot it here and restore in tearDown()
        // so the toggles don't bleed into unrelated tests (e.g. ones that compile
        // factories.xml and expect the translation manager to be present).
        $this->configSnapshot = AgaviConfig::toArray();
    }

    protected function tearDown(): void
    {
        AgaviConfig::clear();
        AgaviConfig::fromArray($this->configSnapshot);
        parent::tearDown();
    }

    private function makeEnvelope(string $innerXml, string $uriBasename): AgaviXmlConfigDomDocument
    {
        $xml = <<<XML
<?xml version="1.0"?>
<configurations xmlns="http://agavi.org/agavi/config/global/envelope/1.1">
  <configuration>
    $innerXml
  </configuration>
</configurations>
XML;
        $doc = new AgaviXmlConfigDomDocument();
        $doc->loadXml($xml);
        $r = new ReflectionProperty(AgaviXmlConfigDomDocument::class, 'documentURI');
        
        $r->setValue($doc, sys_get_temp_dir() . '/' . $uriBasename);
        return $doc;
    }

    public function testFactoryConfigHandlerGeneratesInitializationCode()
    {
        eval('namespace App\\Factory; class ValidationManager { function initialize(){} }');
        eval('namespace App\\Factory; class Response { function initialize(){} }');
        eval('namespace App\\Factory; class DatabaseManager { function initialize(){} function startup(){} }');
        eval('namespace App\\Factory; class Routing { function initialize(){} function startup(){} }');
        eval('namespace App\\Factory; class Request { function initialize(){} }');
        eval('namespace App\\Factory; class Controller { function initialize(){} function startup(){} }');
        eval('namespace App\\Factory; class Storage { function initialize(){} function startup(){} }');
        eval('namespace App\\Factory; class User { function initialize(){} function startup(){} }');
        AgaviConfig::set('core.use_logging', false);
        AgaviConfig::set('core.use_translation', false);
        $ns = 'http://agavi.org/agavi/config/parts/factories/1.1';
        $inner = <<<XML
<validation_manager xmlns="$ns" class="App\\Factory\\ValidationManager" />
<response xmlns="$ns" class="App\\Factory\\Response" />
<database_manager xmlns="$ns" class="App\\Factory\\DatabaseManager" />
<routing xmlns="$ns" class="App\\Factory\\Routing" />
<request xmlns="$ns" class="App\\Factory\\Request" />
<controller xmlns="$ns" class="App\\Factory\\Controller" />
<storage xmlns="$ns" class="App\\Factory\\Storage" />
<user xmlns="$ns" class="App\\Factory\\User" />
XML;
        $doc = $this->makeEnvelope($inner, 'factories.xml');
        $handler = new AgaviFactoryConfigHandler();
        $handler->initialize(null, []);
        $code = $handler->execute($doc);
        $this->assertStringContainsString('ValidationManager', $code);
        $this->assertStringContainsString('$this->controller->startup();', $code);
        $this->assertStringContainsString('$this->shutdownSequence', $code);
    }

    public function testFilterConfigHandlerThrowsOnReservedName()
    {
        $ns = 'http://agavi.org/agavi/config/parts/filters/1.1';
        $inner = <<<XML
<filters xmlns="$ns">
  <filter name="agaviFoo" class="FooFilter" />
</filters>
XML;
        $doc = $this->makeEnvelope($inner, 'app_filters.xml');
        $handler = new AgaviFilterConfigHandler();
        $handler->initialize(null, []);
        $this->expectException(AgaviConfigurationException::class);
        $handler->execute($doc);
    }

}
