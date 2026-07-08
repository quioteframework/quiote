<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\YamlFormatDriver;

class YamlFormatDriverPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'yfdp_');
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

	public function testDashListDataMatchesPlainLoadAndPositionsAreCorrect(): void
	{
		$path = $this->dir . '/plugins.yaml';
		file_put_contents($path, <<<'YAML'
- class: 'App\Plugin\One'
  enabled: true
- class: 'App\Plugin\Two'
YAML);

		$driver = new YamlFormatDriver();
		$plain = $driver->load($path, 'test');
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($plain, $result['data']);
		$this->assertSame(1, $result['positions']['[0].class']['line']);
		$this->assertSame($path, $result['positions']['[0].class']['file']);
		$this->assertSame(3, $result['positions']['[1].class']['line']);
	}

	public function testMapDataMatchesPlainLoadAndPositionsAreCorrect(): void
	{
		$path = $this->dir . '/databases.yaml';
		file_put_contents($path, <<<'YAML'
db:
  host: localhost
  port: 5432
YAML);

		$driver = new YamlFormatDriver();
		$plain = $driver->load($path, 'test');
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($plain, $result['data']);
		$this->assertSame(2, $result['positions']['db.host']['line']);
		$this->assertSame(3, $result['positions']['db.port']['line']);
	}

	public function testParentReferencedKeysHaveDataButNoPosition(): void
	{
		$parentPath = $this->dir . '/base.yaml';
		$childPath = $this->dir . '/child.yaml';

		file_put_contents($parentPath, "db:\n  host: localhost\n  port: 5432\n");
		file_put_contents($childPath, "parent: {$parentPath}\ndb:\n  port: 6543\n");

		$driver = new YamlFormatDriver();
		new FormatDriverRegistry([$driver]);
		$result = $driver->loadWithPositions($childPath, 'test');

		$this->assertSame(['db' => ['host' => 'localhost', 'port' => 6543]], $result['data']);

		// "db.port" is overridden in the child, so it DOES get a position;
		// "db.host" only exists via the parent, so it doesn't.
		$this->assertSame(3, $result['positions']['db.port']['line']);
		$this->assertArrayNotHasKey('db.host', $result['positions']);
		$this->assertArrayNotHasKey('parent', $result['positions']);
	}
}
?>
