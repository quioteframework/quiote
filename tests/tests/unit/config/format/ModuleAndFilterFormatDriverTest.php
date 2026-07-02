<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\ModuleConfigHandler;
use Quiote\Config\FilterConfigHandler;

/**
 * Proves PHP-array files compile through ModuleConfigHandler and
 * FilterConfigHandler exactly like the XML equivalents do -- fifth and
 * sixth handlers migrated per docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2.
 */
class ModuleAndFilterFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'mf_');
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

	public function testModulePhpArrayFileCompilesThroughModuleConfigHandler()
	{
		file_put_contents($this->dir . '/module.php', <<<'PHP'
<?php
return [
    'enabled' => true,
    'settings' => ['modules.${moduleName}.some_setting' => 'value'],
];
PHP);

		$handler = new ModuleConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/module.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/module.php');

		$this->assertStringContainsString('$lcModuleName = strtolower($moduleName);', $code);
		$this->assertStringContainsString('modules.${moduleName}.enabled', $code);
		$this->assertStringContainsString('some_setting', $code);
	}

	public function testFilterPhpArrayFileCompilesThroughFilterConfigHandler()
	{
		// Filenames matter here: the interface check derives "IActionFilter"
		// from the *_filters basename, XML or not.
		file_put_contents($this->dir . '/action_filters.php', <<<'PHP'
<?php
return [
    'my_filter' => ['class' => 'Sandbox\\Testing\\NullActionFilter', 'enabled' => true, 'params' => []],
];
PHP);

		$handler = new FilterConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/action_filters.php', 'test');
		$this->assertSame(['my_filter' => ['class' => 'Sandbox\\Testing\\NullActionFilter', 'enabled' => true, 'params' => []]], $config);
	}

	public function testFilterNamesStartingWithQuioteAreRejectedRegardlessOfFormat()
	{
		file_put_contents($this->dir . '/action_filters.php', <<<'PHP'
<?php
return [
    'quiote_reserved' => ['class' => 'Anything', 'enabled' => true, 'params' => []],
];
PHP);

		$handler = new FilterConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/action_filters.php', 'test');

		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$handler->executeArray($config, $this->dir . '/action_filters.php');
	}
}
?>
