<?php

use Quiote\Config\ConfigHandler;
use Quiote\Config\ConfigHandlersConfigHandler;
use Quiote\Config\Config;
use Quiote\Util\Toolkit;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class CHCHTestHandler extends ConfigHandler
{
	public	$validationFile,
					$parser,
					$parameters;

	#[\Override]
    public function initialize($vf = null, $parser = null, $params = [])
	{
		$this->validationFile = $vf;
		$this->parser = $parser;
		$this->parameters = $params;
	}

	public function execute($config, $context = null)
	{
		return [];
	}
}

class ConfigHandlersConfigHandlerTest extends ConfigHandlerTestBase
{

	public function testConfigHandlersConfigHandler(): void
	{
		$hf = Toolkit::normalizePath(Config::getString('core.config_dir') . '/routing.xml');
		$CHCH = new ConfigHandlersConfigHandler();

		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/config_handlers.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/config_handlers.xsl'
		);

		$handlers = $CHCH->execute($document);

		$this->assertIsArray($handlers);
		$this->assertCount(1, $handlers);
		$this->assertArrayHasKey($hf, $handlers);
		$handler = $handlers[$hf];
		$this->assertIsArray($handler);
		$this->assertSame('CHCHTestHandler', $handler['class']);
		$validations = $handler['validations'];
		$this->assertIsArray($validations);
		$single = $validations['single'] ?? null;
		$this->assertIsArray($single);
		$afterTransformations = $single['transformations_after'] ?? null;
		$this->assertIsArray($afterTransformations);
		$schemas = $afterTransformations['xml_schema'] ?? null;
		$this->assertIsArray($schemas);
		$this->assertSame(Config::getString('core.quiote_dir') . '/config/xsd/routing.xsd', $schemas[0]);
		$this->assertSame(['foo' => 'bar', 'dir' => Config::getString('core.quiote_dir')], $handler['parameters']);
	}

}
?>