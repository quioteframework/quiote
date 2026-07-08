<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\DatabaseConfigHandler;

/**
 * Same story as FactoryConfigHandlerPositionTest: databases.xml has
 * legacy-upgrade <transformation> stylesheets configured by default, so
 * positions come back empty in the shipped default configuration, and real
 * once transformations are skipped.
 */
class DatabaseConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'dchp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/databases.xsl';
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

	private function writeDatabasesXml(): string
	{
		$path = $this->dir . '/databases.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/databases/1.1">
    <ae:configuration>
        <databases default="main">
            <database name="main" class="eloquent" />
        </databases>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeDatabasesXml();

		$handler = new DatabaseConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('main', $result['data']['default']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeDatabasesXml();

		$handler = new DatabaseConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('main', $result['data']['default']);
		$this->assertSame($path, $result['positions']['default']['file']);
		$this->assertSame(5, $result['positions']['default']['line']);
		$this->assertSame($path, $result['positions']['databases.main.class']['file']);
		$this->assertSame(6, $result['positions']['databases.main.class']['line']);
	}
}
?>
