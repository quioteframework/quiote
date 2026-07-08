<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\ConfigHandlersConfigHandler;

/**
 * config_handlers.xml's own bootstrap handler entry (ConfigCache::loadConfigHandlers())
 * declares two legacy-upgrade <transformation> stylesheets, same story as
 * Factory/Database/Module: positions come back empty in the shipped
 * default configuration, and real once transformations are skipped.
 */
class ConfigHandlersConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'chcp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/config_handlers.xsl';
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

	private function writeHandlersXml(): string
	{
		$path = $this->dir . '/config_handlers.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/config_handlers/1.1">
    <ae:configuration>
        <handlers>
            <handler pattern="%core.app_dir%/foo.xml" class="FooHandler" />
        </handlers>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeHandlersXml();

		$handler = new ConfigHandlersConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertNotEmpty($result['data']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeHandlersXml();

		$handler = new ConfigHandlersConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$category = array_key_first($result['data']);
		$this->assertSame($path, $result['positions']["{$category}.class"]['file']);
		$this->assertSame(6, $result['positions']["{$category}.class"]['line']);
	}
}
?>
