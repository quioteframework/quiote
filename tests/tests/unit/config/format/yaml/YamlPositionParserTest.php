<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\Yaml\YamlPositionParser;

class YamlPositionParserTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'ypp_');
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

	private function write(string $content): string
	{
		$path = $this->dir . '/config.yaml';
		file_put_contents($path, $content);
		return $path;
	}

	public function testFlatMap(): void
	{
		$path = $this->write(<<<'YAML'
core.app_name: Demo
core.debug: true
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(1, $positions['core.app_name']['line']);
		$this->assertSame($path, $positions['core.app_name']['file']);
		$this->assertSame(2, $positions['core.debug']['line']);
	}

	public function testNestedMap(): void
	{
		$path = $this->write(<<<'YAML'
response:
  class: Quiote\Response\WebResponse
  params: {}
db:
  host: localhost
  port: 5432
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(2, $positions['response.class']['line']);
		// Flow-style value ({}) is a leaf, not descended into.
		$this->assertSame(3, $positions['response.params']['line']);
		$this->assertSame(5, $positions['db.host']['line']);
		$this->assertSame(6, $positions['db.port']['line']);
		$this->assertArrayNotHasKey('response', $positions);
	}

	public function testSequenceIndentedUnderItsKey(): void
	{
		$path = $this->write(<<<'YAML'
imports:
  - /a.yaml
  - /b.yaml
own: from-main
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(2, $positions['imports[0]']['line']);
		$this->assertSame(3, $positions['imports[1]']['line']);
		$this->assertSame(4, $positions['own']['line']);
	}

	public function testSequenceAtSameIndentAsItsKey(): void
	{
		$path = $this->write(<<<'YAML'
items:
- foo
- bar
next: value
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(2, $positions['items[0]']['line']);
		$this->assertSame(3, $positions['items[1]']['line']);
		$this->assertSame(4, $positions['next']['line']);
	}

	public function testDashListOfInlineMapsMultiKeyPerItem(): void
	{
		$path = $this->write(<<<'YAML'
- class: 'App\Plugin\One'
  enabled: true
- class: 'App\Plugin\Two'
- class: 'App\Plugin\Three'
  enabled: false
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(1, $positions['[0].class']['line']);
		$this->assertSame(2, $positions['[0].enabled']['line']);
		$this->assertSame(3, $positions['[1].class']['line']);
		$this->assertArrayNotHasKey('[1].enabled', $positions);
		$this->assertSame(4, $positions['[2].class']['line']);
		$this->assertSame(5, $positions['[2].enabled']['line']);
	}

	public function testScalarList(): void
	{
		$path = $this->write(<<<'YAML'
- one
- two
- three
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(1, $positions['[0]']['line']);
		$this->assertSame(2, $positions['[1]']['line']);
		$this->assertSame(3, $positions['[2]']['line']);
	}

	public function testSingleAndDoubleQuotedKeys(): void
	{
		$path = $this->write(<<<'YAML'
'core.app_name': Demo
"core.debug": true
'it''s escaped': value
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(1, $positions['core.app_name']['line']);
		$this->assertSame(2, $positions['core.debug']['line']);
		$this->assertSame(3, $positions["it's escaped"]['line']);
	}

	public function testFlowStyleValueIsLeafNotDescended(): void
	{
		$path = $this->write(<<<'YAML'
foo: {a: 1, b: 2}
bar: [1, 2, 3]
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(1, $positions['foo']['line']);
		$this->assertSame(2, $positions['bar']['line']);
		$this->assertArrayNotHasKey('foo.a', $positions);
	}

	public function testCommentsAndBlankLinesAreIgnored(): void
	{
		$path = $this->write(<<<'YAML'
# a leading comment

foo: bar

# another comment
baz: qux
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(3, $positions['foo']['line']);
		$this->assertSame(6, $positions['baz']['line']);
	}

	public function testEmptyDocumentYieldsNoPositions(): void
	{
		$path = $this->write('');

		$this->assertSame([], YamlPositionParser::parse($path));
	}

	public function testDocumentHeaderIsSkipped(): void
	{
		$path = $this->write(<<<'YAML'
---
foo: bar
YAML);

		$positions = YamlPositionParser::parse($path);

		$this->assertSame(2, $positions['foo']['line']);
	}

	public function testMissingFileYieldsNoPositions(): void
	{
		$this->assertSame([], YamlPositionParser::parse($this->dir . '/does-not-exist.yaml'));
	}
}
?>
