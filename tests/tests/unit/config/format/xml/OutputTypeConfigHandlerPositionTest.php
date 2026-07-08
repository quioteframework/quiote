<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\OutputTypeConfigHandler;

/**
 * output_types.xml has legacy-upgrade <transformation> stylesheets
 * configured by default, same story as Factory/Database/Module: positions
 * come back empty in the shipped default configuration, and real once
 * transformations are skipped.
 */
class OutputTypeConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'otcp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/output_types.xsl';
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('core.skip_config_transformations');
		parent::tearDown();
	}

	private function writeOutputTypesXml(): string
	{
		$path = $this->dir . '/output_types.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/output_types/1.1">
    <ae:configuration>
        <output_types default="html">
            <output_type name="html">
                <renderers default="php">
                    <renderer name="php" class="Quiote\Renderer\PhpRenderer" />
                </renderers>
            </output_type>
        </output_types>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeOutputTypesXml();

		$handler = new OutputTypeConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertArrayHasKey('html', $result['data']['output_types']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeOutputTypesXml();

		$handler = new OutputTypeConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($path, $result['positions']['output_types.html.parameters']['file']);
		$this->assertSame(6, $result['positions']['output_types.html.parameters']['line']);
	}
}
?>
