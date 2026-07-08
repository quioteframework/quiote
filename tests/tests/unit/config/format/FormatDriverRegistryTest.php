<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Exception\ConfigurationException;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\PhpArrayFormatDriver;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\Format\YamlFormatDriver;
use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\XmlConfigHandler;

/** Minimal fake handler, just so an XmlFormatDriver can be constructed for these priority-ordering tests. */
class FakeArrayHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		return [];
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		return 'return ' . var_export($config, true) . ';';
	}
}

class FormatDriverRegistryTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'fdr_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	public function testResolveThrowsWhenNoDriverSupportsThePath(): void
	{
		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);
		$this->expectException(ConfigurationException::class);
		$registry->resolve('/some/file.yaml');
	}

	public function testLoadDelegatesToTheMatchingDriver(): void
	{
		file_put_contents($this->dir . '/config.php', "<?php\nreturn ['foo' => 'bar'];\n");
		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver(), new YamlFormatDriver()]);

		$result = $registry->load($this->dir . '/config.php', 'test');
		$this->assertSame(['foo' => 'bar'], $result);
	}

	private function registryWithAllThreeFormats(): FormatDriverRegistry
	{
		return FormatDriverRegistry::forHandler(new FakeArrayHandler());
	}

	public function testLocateFindsYamlWhenPhpIsAbsentButXmlAlsoExists(): void
	{
		file_put_contents($this->dir . '/settings.yaml', "foo: bar\n");
		file_put_contents($this->dir . '/settings.xml', '<x/>');

		$found = $this->registryWithAllThreeFormats()->locate($this->dir . '/settings');

		$this->assertSame($this->dir . '/settings.yaml', $found, 'YAML must win over XML when PHP is absent (PHP > YAML > XML).');
	}

	public function testLocatePrefersPhpOverYamlAndXml(): void
	{
		file_put_contents($this->dir . '/settings.php', "<?php\nreturn [];\n");
		file_put_contents($this->dir . '/settings.yaml', "foo: bar\n");
		file_put_contents($this->dir . '/settings.xml', '<x/>');

		$found = $this->registryWithAllThreeFormats()->locate($this->dir . '/settings');

		$this->assertSame($this->dir . '/settings.php', $found);
	}

	public function testLocateFallsBackToXmlWhenNeitherPhpNorYamlExists(): void
	{
		file_put_contents($this->dir . '/settings.xml', '<x/>');

		$found = $this->registryWithAllThreeFormats()->locate($this->dir . '/settings');

		$this->assertSame($this->dir . '/settings.xml', $found);
	}

	public function testLocateReturnsNullWhenNoCandidateExists(): void
	{
		$this->assertNull($this->registryWithAllThreeFormats()->locate($this->dir . '/nonexistent'));
	}

	public function testCrossFormatParentPhpFileWithYamlParent(): void
	{
		file_put_contents($this->dir . '/base.yaml', "core.app_name: BaseApp\ncore.debug: false\n");
		file_put_contents($this->dir . '/child.php', "<?php\nreturn ['parent' => __DIR__ . '/base.yaml', 'core.debug' => true];\n");

		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver(), new YamlFormatDriver()]);
		$result = $registry->load($this->dir . '/child.php', 'test');

		$this->assertSame(['core.app_name' => 'BaseApp', 'core.debug' => true], $result);
	}
}
?>
