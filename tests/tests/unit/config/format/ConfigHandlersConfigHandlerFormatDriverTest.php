<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigHandlersConfigHandler;
use Quiote\Config\Format\FormatDriverRegistry;

/**
 * config_handlers.xml itself compiles identically whether the source file
 * is XML, a plain PHP array, or YAML -- the same "greenfield code can use
 * or not use XML" guarantee already covered for settings.xml (see
 * SettingConfigHandlerFormatDriverTest). Middleware enable/disable used to
 * be configured inline here via a reserved `<middleware_config>` block;
 * that's covered separately now by MiddlewareConfigHandlerFormatDriverTest.
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
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function expectedHandlers(): array
	{
		return [
			Config::getString('core.config_dir') . '/settings.xml' => [
				'class' => 'Quiote\\Config\\SettingConfigHandler',
				'parameters' => [],
				'transformations' => ['single' => [], 'compilation' => []],
				'validations' => [
					'single' => [
						'transformations_before' => ['relax_ng' => [], 'schematron' => [], 'xml_schema' => []],
						'transformations_after' => ['relax_ng' => [], 'schematron' => [], 'xml_schema' => []],
					],
					'compilation' => [
						'transformations_before' => ['relax_ng' => [], 'schematron' => [], 'xml_schema' => []],
						'transformations_after' => ['relax_ng' => [], 'schematron' => [], 'xml_schema' => []],
					],
				],
			],
		];
	}

	public function testXmlHandlerRegistrationCompiles(): void
	{
		file_put_contents($this->dir . '/config_handlers.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/config_handlers/1.1">
    <ae:configuration>
        <handlers>
            <handler pattern="%core.config_dir%/settings.xml" class="Quiote\Config\SettingConfigHandler" />
        </handlers>
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

		$this->assertSame($this->expectedHandlers(), $config);
	}

	public function testPhpArrayHandlerRegistrationCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/config_handlers.php', '<?php return ' . var_export($this->expectedHandlers(), true) . ';');

		$handler = new ConfigHandlersConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/config_handlers.php', 'test');

		$this->assertSame($this->expectedHandlers(), $config);
	}

	public function testExecuteArrayGeneratesLoadablePhp(): void
	{
		$config = $this->expectedHandlers();

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
