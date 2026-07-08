<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Exception\ConfigurationException;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\Format\PhpArrayFormatDriver;
use Quiote\Config\Format\YamlFormatDriver;

class PhpArrayFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'pafd_');
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

	public function testSupportsOnlyPhpExtension(): void
	{
		$driver = new PhpArrayFormatDriver();
		$this->assertTrue($driver->supports('/a/b.php'));
		$this->assertFalse($driver->supports('/a/b.yaml'));
		$this->assertFalse($driver->supports('/a/b.xml'));
	}

	public function testLoadReturnsFlatArrayUnchanged(): void
	{
		file_put_contents($this->dir . '/c.php', "<?php\nreturn ['core.app_name' => 'Demo'];\n");
		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);

		$this->assertSame(['core.app_name' => 'Demo'], $registry->load($this->dir . '/c.php', 'test'));
	}

	public function testThrowsWhenFileDoesNotReturnAnArray(): void
	{
		file_put_contents($this->dir . '/c.php', "<?php\nreturn 'not-an-array';\n");
		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);

		$this->expectException(ConfigurationException::class);
		$registry->load($this->dir . '/c.php', 'test');
	}

	public function testThrowsWhenFileDoesNotExist(): void
	{
		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);
		$this->expectException(ConfigurationException::class);
		$registry->load($this->dir . '/missing.php', 'test');
	}

	public function testParentChainMergesAcrossMultipleGenerations(): void
	{
		file_put_contents($this->dir . '/grandparent.php', "<?php\nreturn ['a' => 1, 'b' => 1];\n");
		file_put_contents($this->dir . '/parent.php', "<?php\nreturn ['parent' => __DIR__ . '/grandparent.php', 'b' => 2, 'c' => 2];\n");
		file_put_contents($this->dir . '/child.php', "<?php\nreturn ['parent' => __DIR__ . '/parent.php', 'c' => 3];\n");

		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);
		$result = $registry->load($this->dir . '/child.php', 'test');

		$this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
	}

	public function testImportsMergeBeforeParentWithChildDataWinningOverImports(): void
	{
		file_put_contents($this->dir . '/base.php', "<?php\nreturn ['x' => 'from-parent'];\n");
		file_put_contents($this->dir . '/import1.php', "<?php\nreturn ['y' => 'from-import', 'z' => 'from-import'];\n");
		file_put_contents($this->dir . '/main.php', "<?php\nreturn [\n    'parent' => __DIR__ . '/base.php',\n    'imports' => [__DIR__ . '/import1.php'],\n    'z' => 'from-main',\n];\n");

		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);
		$result = $registry->load($this->dir . '/main.php', 'test');

		$this->assertSame(['x' => 'from-parent', 'y' => 'from-import', 'z' => 'from-main'], $result);
	}

	public function testParentPathSupportsDirectiveExpansion(): void
	{
		\Quiote\Config\Config::set('test.php_array_driver_dir', $this->dir, true);
		file_put_contents($this->dir . '/base.php', "<?php\nreturn ['a' => 1];\n");
		file_put_contents($this->dir . '/child.php', "<?php\nreturn ['parent' => '%test.php_array_driver_dir%/base.php', 'b' => 2];\n");

		$registry = new FormatDriverRegistry([new PhpArrayFormatDriver()]);
		$result = $registry->load($this->dir . '/child.php', 'test');

		$this->assertSame(['a' => 1, 'b' => 2], $result);
		\Quiote\Config\Config::remove('test.php_array_driver_dir');
	}
}
?>
