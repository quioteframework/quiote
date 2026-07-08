<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\TranslationConfigHandler;

/**
 * translation.xml has legacy-upgrade <transformation> stylesheets
 * configured by default, same story as Factory/Database/Module: positions
 * come back empty in the shipped default configuration, and real (for the
 * "locales" part this handler tracks) once transformations are skipped.
 */
class TranslationConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'tcp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/translation.xsl';
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

	private function writeTranslationXml(): string
	{
		$path = $this->dir . '/translation.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/translation/1.1">
    <ae:configuration>
        <available_locales default_locale="en_US">
            <available_locale identifier="en_US" />
        </available_locales>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeTranslationXml();

		$handler = new TranslationConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertArrayHasKey('en_US', $result['data']['locales']);
		$this->assertSame([], $result['positions']);
	}

	public function testLocalePositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeTranslationXml();

		$handler = new TranslationConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($path, $result['positions']['locales.en_US.name']['file']);
		$this->assertSame(6, $result['positions']['locales.en_US.name']['line']);
	}
}
?>
