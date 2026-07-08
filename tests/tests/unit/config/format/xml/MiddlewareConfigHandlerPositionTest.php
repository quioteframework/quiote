<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\MiddlewareConfigHandler;

/**
 * middleware.xml declares no <transformation> entries by default, so (like
 * plugins.xml) it reliably gets real positions out of the box.
 */
class MiddlewareConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'mwp_');
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

	public function testEachUseEntryResolvesToItsOwnSourceLine(): void
	{
		$path = $this->dir . '/middleware.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/middleware/1.1">
    <ae:configuration>
        <use class="App\Middleware\One" phase="action" />
        <use class="App\Middleware\Two" />
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new MiddlewareConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('App\\Middleware\\One', $result['data'][0]['class']);
		$this->assertSame($path, $result['positions']['[0].class']['file']);
		$this->assertSame(5, $result['positions']['[0].class']['line']);
		$this->assertSame(6, $result['positions']['[1].class']['line']);
	}
}
?>
