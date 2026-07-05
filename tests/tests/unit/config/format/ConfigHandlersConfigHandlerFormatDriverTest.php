<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigHandlersConfigHandler;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Middleware\MiddlewareCatalog;

/**
 * Proves the <middleware_config> enable/disable mechanism (consumed by
 * MiddlewareCatalog::initialize()) compiles identically whether the source
 * file is XML, a plain PHP array, or YAML -- the same "greenfield code can
 * use or not use XML" guarantee already covered for settings.xml (see
 * SettingConfigHandlerFormatDriverTest), applied to config_handlers.xml.
 */
class ConfigHandlersConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'chchfd_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		MiddlewareCatalog::reset();
		MiddlewareCatalog::initialize([]);
		parent::tearDown();
	}

	private function expectedMap(): array
	{
		return [
			'Quiote\\Middleware\\ExecutionTimeMiddleware' => false,
			'Quiote\\Middleware\\TimingMiddleware' => true,
		];
	}

	private function assertCompilesToExpectedMiddlewareMap(array $config): void
	{
		$this->assertArrayHasKey('__middleware_config', $config);
		$this->assertSame($this->expectedMap(), $config['__middleware_config']);

		// Close the loop: this is exactly what ConfigCache::loadConfigHandlersFile()
		// does with the compiled array at runtime.
		MiddlewareCatalog::initialize($config['__middleware_config']);
		$this->assertFalse(MiddlewareCatalog::isEnabled('Quiote\\Middleware\\ExecutionTimeMiddleware'));
		$this->assertTrue(MiddlewareCatalog::isEnabled('Quiote\\Middleware\\TimingMiddleware'));
		// Unknown/unlisted classes default to enabled.
		$this->assertTrue(MiddlewareCatalog::isEnabled('Some\\Unlisted\\Middleware'));
	}

	public function testXmlMiddlewareConfigCompiles(): void
	{
		file_put_contents($this->dir . '/config_handlers.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/config_handlers/1.1">
    <ae:configuration>
        <middleware_config>
            <middleware class="Quiote\Middleware\ExecutionTimeMiddleware" enabled="false" />
            <middleware class="Quiote\Middleware\TimingMiddleware" enabled="true" />
        </middleware_config>
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new ConfigHandlersConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler(
			$handler,
			[Config::getString('core.quiote_dir') . '/Config/xsl/config_handlers.xsl']
		);

		$config = $registry->load($this->dir . '/config_handlers.xml', 'test');

		$this->assertCompilesToExpectedMiddlewareMap($config);
	}

	public function testPhpArrayMiddlewareConfigCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/config_handlers.php', <<<'PHP'
<?php
return [
    '__middleware_config' => [
        'Quiote\\Middleware\\ExecutionTimeMiddleware' => false,
        'Quiote\\Middleware\\TimingMiddleware' => true,
    ],
];
PHP);

		$handler = new ConfigHandlersConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/config_handlers.php', 'test');

		$this->assertCompilesToExpectedMiddlewareMap($config);
	}

	public function testYamlMiddlewareConfigCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/config_handlers.yaml', <<<'YAML'
__middleware_config:
  'Quiote\Middleware\ExecutionTimeMiddleware': false
  'Quiote\Middleware\TimingMiddleware': true
YAML);

		$handler = new ConfigHandlersConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/config_handlers.yaml', 'test');

		$this->assertCompilesToExpectedMiddlewareMap($config);
	}

	public function testExecuteArrayGeneratesLoadablePhpForAllThreeSources(): void
	{
		$config = ['__middleware_config' => $this->expectedMap()];

		$handler = new ConfigHandlersConfigHandler();
		$handler->initialize(null, []);
		$code = $handler->executeArray($config, 'in-memory-test');

		$file = tempnam($this->dir, 'compiled_');
		rename($file, $file .= '.php');
		file_put_contents($file, $code);
		$loaded = include $file;
		unlink($file);

		$this->assertSame($config, $loaded);
	}
}
