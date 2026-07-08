<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\ModuleConfigHandler;

/**
 * module.xml has legacy-upgrade <transformation> stylesheets configured by
 * default (config_handlers.xml), same story as Factory/Database: positions
 * come back empty in the shipped default configuration, and real once
 * transformations are skipped.
 */
class ModuleConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'mchp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/module.xsl';
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

	private function writeModuleXml(): string
	{
		$path = $this->dir . '/module.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/module/1.1">
    <ae:configuration>
        <module enabled="true">
            <settings>
                <setting name="foo">bar</setting>
            </settings>
        </module>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeModuleXml();

		$handler = new ModuleConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertTrue($result['data']['enabled']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeModuleXml();

		$handler = new ModuleConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertTrue($result['data']['enabled']);
		$this->assertSame($path, $result['positions']['enabled']['file']);
		$this->assertSame(5, $result['positions']['enabled']['line']);
		$this->assertSame(7, $result['positions']['settings.modules.${moduleName}.foo']['line']);
	}
}
?>
