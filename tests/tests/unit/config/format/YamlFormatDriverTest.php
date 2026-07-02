<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Exception\ConfigurationException;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\YamlFormatDriver;

class YamlFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'yfd_');
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

	public function testSupportsYamlAndYmlExtensions()
	{
		$driver = new YamlFormatDriver();
		$this->assertTrue($driver->supports('/a/b.yaml'));
		$this->assertTrue($driver->supports('/a/b.yml'));
		$this->assertFalse($driver->supports('/a/b.php'));
	}

	public function testParsesMappingIntoArrayWithNativeTypes()
	{
		file_put_contents($this->dir . '/s.yaml', <<<YAML
core.app_name: Demo
core.debug: true
core.max_items: 10
YAML);
		$registry = new FormatDriverRegistry([new YamlFormatDriver()]);
		$result = $registry->load($this->dir . '/s.yaml', 'test');

		$this->assertSame(['core.app_name' => 'Demo', 'core.debug' => true, 'core.max_items' => 10], $result);
	}

	public function testEmptyDocumentYieldsEmptyArray()
	{
		file_put_contents($this->dir . '/empty.yaml', "");
		$registry = new FormatDriverRegistry([new YamlFormatDriver()]);
		$this->assertSame([], $registry->load($this->dir . '/empty.yaml', 'test'));
	}

	public function testThrowsOnMalformedYaml()
	{
		file_put_contents($this->dir . '/bad.yaml', "core:\n  - unbalanced: [1, 2\n");
		$registry = new FormatDriverRegistry([new YamlFormatDriver()]);
		$this->expectException(ConfigurationException::class);
		$registry->load($this->dir . '/bad.yaml', 'test');
	}

	public function testParentChainAndNestedMerge()
	{
		file_put_contents($this->dir . '/base.yaml', <<<YAML
db:
  host: localhost
  port: 5432
YAML);
		file_put_contents($this->dir . '/child.yaml', <<<YAML
parent: {$this->dir}/base.yaml
db:
  port: 6543
YAML);
		$registry = new FormatDriverRegistry([new YamlFormatDriver()]);
		$result = $registry->load($this->dir . '/child.yaml', 'test');

		$this->assertSame(['db' => ['host' => 'localhost', 'port' => 6543]], $result);
	}

	public function testImportsKeyMergesAdditionalFiles()
	{
		file_put_contents($this->dir . '/import.yaml', "shared: from-import\n");
		file_put_contents($this->dir . '/main.yaml', <<<YAML
imports:
  - {$this->dir}/import.yaml
own: from-main
YAML);
		$registry = new FormatDriverRegistry([new YamlFormatDriver()]);
		$result = $registry->load($this->dir . '/main.yaml', 'test');

		$this->assertSame(['shared' => 'from-import', 'own' => 'from-main'], $result);
	}
}
?>
