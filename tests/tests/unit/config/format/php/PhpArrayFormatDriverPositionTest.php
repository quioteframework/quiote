<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\PhpArrayFormatDriver;

class PhpArrayFormatDriverPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'pafdp_');
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

	public function testDataMatchesPlainLoadAndPositionsAreCorrect(): void
	{
		$path = $this->dir . '/plugins.php';
		file_put_contents($path, <<<'PHP'
<?php
return [
    ['class' => 'App\Plugin\One', 'enabled' => true],
    ['class' => 'App\Plugin\Two'],
];
PHP);

		$driver = new PhpArrayFormatDriver();
		$plain = $driver->load($path, 'test');
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($plain, $result['data']);
		$this->assertSame(3, $result['positions']['[0].class']['line']);
		$this->assertSame($path, $result['positions']['[0].class']['file']);
		$this->assertSame(4, $result['positions']['[1].class']['line']);
	}

	public function testFactoriesMapDataMatchesAndPositionsAreCorrect(): void
	{
		$path = $this->dir . '/factories.php';
		file_put_contents($path, <<<'PHP'
<?php
return [
    'response' => ['class' => 'Quiote\Response\WebResponse', 'params' => []],
];
PHP);

		$driver = new PhpArrayFormatDriver();
		$plain = $driver->load($path, 'test');
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($plain, $result['data']);
		$this->assertSame(3, $result['positions']['response.class']['line']);
	}

	public function testParentReferencedKeysHaveDataButNoPosition(): void
	{
		$parentPath = $this->dir . '/parent.php';
		$childPath = $this->dir . '/plugins.php';

		file_put_contents($parentPath, <<<'PHP'
<?php
return [
    ['class' => 'App\Plugin\FromParent'],
];
PHP);

		file_put_contents($childPath, <<<PHP
<?php
return [
    'parent' => '{$parentPath}',
];
PHP);

		$driver = new PhpArrayFormatDriver();
		new FormatDriverRegistry([$driver]);
		$result = $driver->loadWithPositions($childPath, 'test');

		// Data is fully merged (parent's list survives, per ArrayMergeStrategy's
		// wholesale-list-replace semantics -- nothing here overrides it).
		$this->assertSame([
			['class' => 'App\\Plugin\\FromParent'],
		], $result['data']);

		// But positions only ever cover the child file's OWN literal contents
		// -- the documented scope limit for this pass.
		$this->assertSame([], $result['positions']);
	}
}
?>
