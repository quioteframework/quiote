<?php

use Quiote\Config\Config;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\PhpArrayFormatDriver;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\Format\YamlFormatDriver;
use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\XmlConfigHandler;
use Quiote\Config\XmlConfigParser;
use Quiote\Exception\ParseException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Fixture handler with a trivial canonical shape, just so XmlFormatDriver has
 * something to bind to; the point of these tests is the validation wiring,
 * not what the handler does with the parsed document.
 */
class FixtureValidatedHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		return ['name' => $document->documentElement?->getAttribute('name')];
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		return 'return ' . var_export($config, true) . ';';
	}
}

/**
 * Regression coverage for CONFIG_VALIDATION_FORMATDRIVER_FIX.md: the DOM path
 * (ConfigCache::executeHandler()'s IXmlConfigHandler branch) has always
 * applied a handler's declared XSD/RelaxNG/Schematron validations, but XML
 * reached through XmlFormatDriver -- either as a primary file resolved by
 * FormatDriverRegistry, or via a `parent`/`imports` reference from a PHP/YAML
 * config -- silently dropped them. These tests prove the fix: XmlFormatDriver
 * now threads a handler's validations into XmlConfigParser::run() exactly as
 * the DOM path already does, only XSD is shipped for any handler (see
 * config_handlers.xml), so RelaxNG/Schematron are not separately exercised.
 */
class XmlFormatDriverValidationTest extends PhpUnitTestCase
{
	private string $dir;

	private string $xsd;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'xfdv_');
		unlink($this->dir);
		mkdir($this->dir);

		$this->xsd = $this->dir . '/fixture.xsd';
		file_put_contents($this->xsd, <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="fixture">
    <xs:complexType>
      <xs:attribute name="name" type="xs:string" use="required"/>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('core.skip_config_validation');
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validations(): array
	{
		return [
			XmlConfigParser::STAGE_SINGLE => [
				XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
					XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [$this->xsd],
				],
			],
		];
	}

	private function writeValidXml(string $path): void
	{
		file_put_contents($path, '<fixture name="ok"/>');
	}

	private function writeInvalidXml(string $path): void
	{
		// Violates the XSD: "name" is a required attribute.
		file_put_contents($path, '<fixture/>');
	}

	public function testXmlFormatDriverAppliesDeclaredXsdValidationOnValidDocument(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeValidXml($xmlPath);

		$handler = new FixtureValidatedHandler();
		$driver = new XmlFormatDriver($handler, [], $this->validations());

		$result = $driver->load($xmlPath, 'test');

		$this->assertSame(['name' => 'ok'], $result);
	}

	public function testXmlFormatDriverRejectsDocumentViolatingDeclaredXsd(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeInvalidXml($xmlPath);

		$handler = new FixtureValidatedHandler();
		$driver = new XmlFormatDriver($handler, [], $this->validations());

		$this->expectException(ParseException::class);
		$driver->load($xmlPath, 'test');
	}

	public function testXmlFormatDriverWithNoDeclaredValidationsAcceptsAnything(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeInvalidXml($xmlPath);

		$handler = new FixtureValidatedHandler();
		$driver = new XmlFormatDriver($handler, []);

		$result = $driver->load($xmlPath, 'test');

		$this->assertSame(['name' => null], $result);
	}

	public function testFormatDriverRegistryThreadsValidationsThroughToXmlFormatDriver(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeInvalidXml($xmlPath);

		$handler = new FixtureValidatedHandler();
		$registry = FormatDriverRegistry::forHandler($handler, [], $this->validations());

		$this->expectException(ParseException::class);
		$registry->load($xmlPath, 'test');
	}

	public function testValidationParityBetweenPrimaryXmlAndXmlImportedFromYaml(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeInvalidXml($xmlPath);

		$yamlPath = $this->dir . '/fixture.yaml';
		file_put_contents($yamlPath, "parent: " . $xmlPath . "\n");

		$handler = new FixtureValidatedHandler();
		$registry = FormatDriverRegistry::forHandler($handler, [], $this->validations());

		$primaryFailed = false;
		try {
			$registry->load($xmlPath, 'test');
		} catch (ParseException) {
			$primaryFailed = true;
		}
		$this->assertTrue($primaryFailed, 'Malformed primary XML must be rejected.');

		$importedFailed = false;
		try {
			$registry->load($yamlPath, 'test');
		} catch (ParseException) {
			$importedFailed = true;
		}
		$this->assertTrue($importedFailed, 'Malformed XML pulled in via a YAML parent must be rejected identically to a malformed primary XML file.');
	}

	public function testValidXmlImportedFromYamlLoadsSuccessfully(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeValidXml($xmlPath);

		$yamlPath = $this->dir . '/fixture.yaml';
		file_put_contents($yamlPath, "parent: " . $xmlPath . "\n");

		$handler = new FixtureValidatedHandler();
		$registry = new FormatDriverRegistry([
			new PhpArrayFormatDriver(),
			new YamlFormatDriver(),
			new XmlFormatDriver($handler, [], $this->validations()),
		]);

		$result = $registry->load($yamlPath, 'test');

		$this->assertSame(['name' => 'ok'], $result);
	}

	public function testSkipConfigValidationFlagBypassesValidationForImportedXml(): void
	{
		$xmlPath = $this->dir . '/fixture.xml';
		$this->writeInvalidXml($xmlPath);

		$yamlPath = $this->dir . '/fixture.yaml';
		file_put_contents($yamlPath, "parent: " . $xmlPath . "\n");

		Config::set('core.skip_config_validation', true, true);

		$handler = new FixtureValidatedHandler();
		$registry = FormatDriverRegistry::forHandler($handler, [], $this->validations());

		$result = $registry->load($yamlPath, 'test');

		$this->assertSame(['name' => null], $result);
	}
}
