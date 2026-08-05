<?php

use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\IDeclarationConfigHandler;
use Quiote\Config\ModuleConfigHandler;
use Quiote\Config\SettingConfigHandler;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The declaration contract: what ConfigCache::load() accepts, and what the appliers do with a
 * declaration once they have it.
 */
class ConfigDeclarationTest extends PhpUnitTestCase
{
	protected function tearDown(): void
	{
		ConfigCache::resetAppliedConfigs();
		parent::tearDown();
	}

	/**
	 * A handler that compiles a value for someone to read has nothing to apply, so loading its config
	 * for effect is a mistake worth naming -- rather than including an artifact and hoping it does
	 * something.
	 */
	public function testLoadRejectsAHandlerThatDoesNotApplyDeclarations(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/does not implement .*IDeclarationConfigHandler/');

		ConfigCache::load(Config::getString('core.config_dir') . '/tests/rbac_definitions.xml');
	}

	public function testLoadRejectsAConfigWithNoRegisteredHandler(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/does not have a registered handler/');

		ConfigCache::load(Config::getString('core.config_dir') . '/tests/no_such_handler_for_this.xml');
	}

	public function testTheFourAppliedConfigsHandlersAllApplyDeclarations(): void
	{
		foreach ([
			SettingConfigHandler::class,
			\Quiote\Config\PluginConfigHandler::class,
			\Quiote\Config\MiddlewareConfigHandler::class,
		] as $class) {
			$this->assertInstanceOf(IDeclarationConfigHandler::class, new $class(), $class);
		}
	}

	public function testSettingsDeclarationIsFedToTheConfigRepository(): void
	{
		$key = 'core.config_declaration_test_' . mt_rand();

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$handler->apply([$key => 'applied'], 'in-memory-test');

		try {
			$this->assertSame('applied', Config::getString($key));
		} finally {
			Config::remove($key);
		}
	}

	public function testSettingsApplyRejectsANonArrayDeclaration(): void
	{
		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/must be an array of setting name => value/');
		$handler->apply('core.app_name=Demo', 'in-memory-test');
	}

	/**
	 * The declaration carries the `${moduleName}` template, not a module name; the caller's module name
	 * is what turns a key into "modules.<name>.<setting>".
	 */
	public function testModuleDeclarationIsPrefixedWithTheCallerSuppliedModuleName(): void
	{
		$module = 'declarationtest' . mt_rand();

		ModuleConfigHandler::applyDeclaration(
			[
				'enabled' => true,
				'settings' => [
					'modules.${moduleName}.some_setting' => 'value',
					// A custom <settings prefix="..."> wrapper produces a key with no template in it.
					'fixed.key' => 'other',
				],
			],
			strtoupper($module),
			'in-memory-test'
		);

		try {
			$this->assertTrue(Config::getBool('modules.' . $module . '.enabled'));
			$this->assertSame('value', Config::getString('modules.' . $module . '.some_setting'));
			$this->assertSame('other', Config::getString('fixed.key'));
		} finally {
			Config::remove('modules.' . $module . '.enabled');
			Config::remove('modules.' . $module . '.some_setting');
			Config::remove('fixed.key');
		}
	}

	public function testModuleDeclarationWithNoSettingsStillRecordsTheEnabledFlag(): void
	{
		$module = 'declarationtestempty' . mt_rand();

		ModuleConfigHandler::applyDeclaration(['enabled' => false, 'settings' => []], $module, 'in-memory-test');

		try {
			$this->assertFalse(Config::getBool('modules.' . $module . '.enabled'));
		} finally {
			Config::remove('modules.' . $module . '.enabled');
		}
	}

	public function testModuleApplyRejectsADeclarationWithoutAnEnabledKey(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/must be an array with "enabled" and "settings" keys/');
		ModuleConfigHandler::applyDeclaration(['settings' => []], 'somemodule', 'in-memory-test');
	}

	public function testModuleApplyRejectsNonArraySettings(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/"settings" key .* must be an array/');
		ModuleConfigHandler::applyDeclaration(['enabled' => true, 'settings' => 'nope'], 'somemodule', 'in-memory-test');
	}
}
