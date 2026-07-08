<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\TestSuitesConfigHandler;

/**
 * suites.xml has a legacy-upgrade <transformation> stylesheet configured by
 * default (config_handlers.xml), same story as Factory/Database/Module:
 * positions come back empty in the shipped default configuration, and real
 * once transformations are skipped.
 */
class TestSuitesConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'tscp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/testing.suites.xsl';
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

	private function writeSuitesXml(): string
	{
		$path = $this->dir . '/suites.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/testing/suites/1.1">
    <ae:configuration>
        <suites>
            <suite name="unit" class="TestSuite" base="tests/" />
        </suites>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeSuitesXml();

		$handler = new TestSuitesConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertArrayHasKey('unit', $result['data']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeSuitesXml();

		$handler = new TestSuitesConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($path, $result['positions']['unit.class']['file']);
		$this->assertSame(6, $result['positions']['unit.class']['line']);
	}
}
?>
