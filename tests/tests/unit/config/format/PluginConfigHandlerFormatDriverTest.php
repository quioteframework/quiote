<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\PluginConfigHandler;
use Quiote\Config\Format\FormatDriverRegistry;

/**
 * plugins.xml compiles to the same appended `plugins` config key whether
 * the source is XML, a plain PHP array, or YAML -- the same multi-format
 * guarantee already covered for settings.xml.
 */
class PluginConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'pchfd_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('plugins');
		parent::tearDown();
	}

	/**
	 * FormatDriverRegistry::load() returns array<string,mixed> generically
	 * (the shape is format-driver-agnostic), but the fixtures in this test
	 * always load a plain list of plugin entries -- re-validate that real
	 * invariant here so PluginConfigHandler::executeArray() gets the
	 * list<array{...}> shape it actually requires.
	 * @param array<string, mixed> $config
	 * @return list<array{class: string, enabled?: bool}>
	 */
	private function asPluginList(array $config): array
	{
		$result = [];
		foreach ($config as $entry) {
			if (!is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
				throw new \LogicException('Expected a list of plugin entries with a string "class" key.');
			}
			$result[] = [
				'class' => $entry['class'],
				'enabled' => isset($entry['enabled']) ? (bool) $entry['enabled'] : true,
			];
		}
		return $result;
	}

	private function assertCompilesAndAppendsPlugins(string $code): void
	{
		$file = tempnam($this->dir, 'compiled_');
		rename($file, $file .= '.php');
		file_put_contents($file, $code);
		include $file;
		unlink($file);

		$this->assertSame(['App\\Plugin\\One', 'App\\Plugin\\Two'], Config::getArray('plugins'));
	}

	public function testXmlPluginsFileCompiles(): void
	{
		file_put_contents($this->dir . '/plugins.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1">
    <ae:configuration>
        <plugin class="App\Plugin\One" enabled="true" />
        <plugin class="App\Plugin\Two" />
        <plugin class="App\Plugin\Three" enabled="false" />
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new PluginConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/plugins.xml', 'test');
		$code = $handler->executeArray($this->asPluginList($config), $this->dir . '/plugins.xml');

		$this->assertCompilesAndAppendsPlugins($code);
	}

	public function testPhpArrayPluginsFileCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/plugins.php', <<<'PHP'
<?php
return [
    ['class' => 'App\Plugin\One', 'enabled' => true],
    ['class' => 'App\Plugin\Two'],
    ['class' => 'App\Plugin\Three', 'enabled' => false],
];
PHP);

		$handler = new PluginConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/plugins.php', 'test');
		$code = $handler->executeArray($this->asPluginList($config), $this->dir . '/plugins.php');

		$this->assertCompilesAndAppendsPlugins($code);
	}

	public function testYamlPluginsFileCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/plugins.yaml', <<<'YAML'
- class: 'App\Plugin\One'
  enabled: true
- class: 'App\Plugin\Two'
- class: 'App\Plugin\Three'
  enabled: false
YAML);

		$handler = new PluginConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/plugins.yaml', 'test');
		$code = $handler->executeArray($this->asPluginList($config), $this->dir . '/plugins.yaml');

		$this->assertCompilesAndAppendsPlugins($code);
	}

	public function testAppendsToAlreadyDeclaredPluginsWithoutDuplicating(): void
	{
		Config::set('plugins', ['App\\Plugin\\Existing', 'App\\Plugin\\One'], true);

		$handler = new PluginConfigHandler();
		$handler->initialize(null, []);
		$code = $handler->executeArray([
			['class' => 'App\\Plugin\\One', 'enabled' => true],
			['class' => 'App\\Plugin\\Two', 'enabled' => true],
		], 'in-memory-test');

		$file = tempnam($this->dir, 'compiled_');
		rename($file, $file .= '.php');
		file_put_contents($file, $code);
		include $file;
		unlink($file);

		$this->assertSame(
			['App\\Plugin\\Existing', 'App\\Plugin\\One', 'App\\Plugin\\Two'],
			Config::getArray('plugins')
		);
	}
}
