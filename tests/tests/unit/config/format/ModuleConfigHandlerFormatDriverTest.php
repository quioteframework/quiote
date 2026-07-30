<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\ModuleConfigHandler;

/**
 * Proves a PHP-array file compiles through ModuleConfigHandler exactly
 * like the XML equivalent does -- phase 2.
 */
class ModuleConfigHandlerFormatDriverTest extends PhpUnitTestCase
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

	/**
	 * ModuleConfigHandler::executeArray() declares a precise array shape
	 * for $config, but FormatDriverRegistry::load() only knows how to
	 * promise array<string, mixed> since it is shared across every config
	 * type. Narrow the registry's generic result back into the shape the
	 * handler expects.
	 * @param array<string, mixed> $config
	 * @return array{enabled?: bool, settings?: array<string, mixed>}
	 */
	private function shapeModuleConfig(array $config): array
	{
		$shaped = [];

		if (array_key_exists('enabled', $config)) {
			self::assertIsBool($config['enabled']);
			$shaped['enabled'] = $config['enabled'];
		}

		if (array_key_exists('settings', $config)) {
			self::assertIsArray($config['settings']);
			$shaped['settings'] = $config['settings'];
		}

		return $shaped;
	}

	public function testModulePhpArrayFileCompilesThroughModuleConfigHandler(): void
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

		$config = $this->shapeModuleConfig($registry->load($this->dir . '/module.php', 'test'));
		$code = $handler->executeArray($config, $this->dir . '/module.php');

		$this->assertStringContainsString('$lcModuleName = strtolower($moduleName);', $code);
		$this->assertStringContainsString('modules.${moduleName}.enabled', $code);
		$this->assertStringContainsString('some_setting', $code);
	}
}
?>
