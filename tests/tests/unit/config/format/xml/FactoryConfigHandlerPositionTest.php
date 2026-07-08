<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\FactoryConfigHandler;

/**
 * factories.xml has legacy-upgrade <transformation> stylesheets configured
 * by default (config_handlers.xml) -- the XSLT engine re-synthesizes the
 * whole tree even for an already-current-format file, so line numbers are
 * genuinely gone by the time the merge step runs. This documents that as
 * expected behavior (empty positions map, not a bug), and proves the
 * mechanism itself works correctly once the transform isn't in the way.
 */
class FactoryConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'fchp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/factories.xsl';
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

	private function writeFactoriesXml(): string
	{
		$path = $this->dir . '/factories.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/factories/1.1">
    <ae:configuration>
        <response class="Quiote\Response\WebResponse" />
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeFactoriesXml();

		$handler = new FactoryConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('Quiote\\Response\\WebResponse', $result['data']['response']['class']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeFactoriesXml();

		$handler = new FactoryConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('Quiote\\Response\\WebResponse', $result['data']['response']['class']);
		$this->assertSame($path, $result['positions']['response.class']['file']);
		$this->assertSame(5, $result['positions']['response.class']['line']);
	}
}
?>
