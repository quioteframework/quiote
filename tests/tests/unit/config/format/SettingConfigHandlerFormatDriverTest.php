<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\SettingConfigHandler;

/**
 * Proves the actual point of migrating SettingConfigHandler to
 * IArrayConfigHandler: a settings file written as plain PHP or YAML
 * compiles through the exact same handler as settings.xml, with no
 * special-casing. This is the "greenfield code can use or not use XML" bet
 * actually landing for one config type, not just described in a plan.
 */
class SettingConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'schfd_');
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

	public function testPhpArraySettingsFileCompilesThroughSettingConfigHandler()
	{
		file_put_contents($this->dir . '/settings.php', <<<'PHP'
<?php
return [
    'core.app_name' => 'Demo',
    'core.debug' => true,
    'actions.default_module' => 'Default',
    'actions.default_action' => 'Index',
];
PHP);

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/settings.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/settings.php');

		$this->assertStringContainsString("'core.app_name' => 'Demo'", $code);
		$this->assertStringContainsString("'core.debug' => true", $code);
		$this->assertStringContainsString('Quiote\\Config\\Config::fromArray(', $code);
	}

	public function testYamlSettingsFileCompilesThroughTheSameHandler()
	{
		file_put_contents($this->dir . '/settings.yaml', <<<YAML
core.app_name: Demo
core.debug: true
YAML);

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/settings.yaml', 'test');
		$code = $handler->executeArray($config, $this->dir . '/settings.yaml');

		$this->assertSame(['core.app_name' => 'Demo', 'core.debug' => true], $config);
		$this->assertStringContainsString("'core.app_name' => 'Demo'", $code);
	}

	public function testPhpArraySettingsCanHaveAnXmlParentDuringAStranglerMigration()
	{
		// The realistic migration path: keep the framework/module defaults
		// in XML, override just a couple of settings in a new PHP file,
		// without touching or duplicating the XML at all.
		$xmlParent = Config::getString('core.config_dir') . '/settings.xml';

		file_put_contents($this->dir . '/settings.php', <<<PHP
<?php
return [
    'parent' => '{$xmlParent}',
    'core.debug' => false,
];
PHP);

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler, [Config::getString('core.quiote_dir') . '/Config/xsl/settings.xsl']);

		$config = $registry->load($this->dir . '/settings.php', 'testing');

		// Inherited from the XML parent, untouched:
		$this->assertSame('sandbox', $config['core.app_name']);
		$this->assertSame('Default', $config['actions.default_module']);
		// Overridden by the PHP child:
		$this->assertFalse($config['core.debug']);
	}
}
?>
