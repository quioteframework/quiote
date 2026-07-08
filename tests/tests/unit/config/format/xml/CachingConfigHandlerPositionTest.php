<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\CachingConfigHandler;

/**
 * CachingConfigHandler isn't registered in the shipped config_handlers.xml
 * (it's an app/module-registered per-action handler type), so like
 * plugins.xml/middleware.xml it has no default <transformation> stylesheets
 * to worry about -- the "lifetime" position it tracks is real out of the box.
 */
class CachingConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'cachp_');
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

	public function testLifetimeResolvesToTheCachingElementsOwnLine(): void
	{
		$path = $this->dir . '/caching.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/caching/1.1">
    <ae:configuration>
        <cachings>
            <caching method="GET" lifetime="3600" />
        </cachings>
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new CachingConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame('3600', $result['data']['GET']['lifetime']);
		$this->assertSame($path, $result['positions']['GET.lifetime']['file']);
		$this->assertSame(6, $result['positions']['GET.lifetime']['line']);
	}
}
?>
