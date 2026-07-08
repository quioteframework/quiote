<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\PluginConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

/**
 * plugins.xml has zero configured <transformation> entries by default, so
 * it's the one handler that reliably gets real positions back out of
 * XmlFormatDriver::loadWithPositions() -- proves the whole pipeline (merge
 * correlation -> handler reconciliation -> schema diagnostic) end to end.
 */
class PluginConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'pchp_');
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

	public function testEachPluginEntryResolvesToItsOwnSourceLine(): void
	{
		$path = $this->dir . '/plugins.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1">
    <ae:configuration>
        <plugin class="App\Plugin\One" enabled="true" />
        <plugin class="App\Plugin\Two" />
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new PluginConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame([
			['class' => 'App\\Plugin\\One', 'enabled' => true],
			['class' => 'App\\Plugin\\Two', 'enabled' => true],
		], $result['data']);

		$this->assertSame($path, $result['positions']['[0].class']['file']);
		$this->assertSame(5, $result['positions']['[0].class']['line']);
		$this->assertSame($path, $result['positions']['[0].enabled']['file']);

		// Second entry omits "enabled" in the source -- no position recorded for it.
		$this->assertArrayNotHasKey('[1].enabled', $result['positions']);
		$this->assertSame(6, $result['positions']['[1].class']['line']);
	}

	public function testSchemaDiagnosticKeyPathMatchesAPositionEntry(): void
	{
		$path = $this->dir . '/plugins.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1">
    <ae:configuration>
        <plugin class="App\Plugin\One" enabled="true" />
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new PluginConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$result = $driver->loadWithPositions($path, 'test');

		// Simulate a malformed hand-edit downstream (e.g. via a PHP override)
		// producing a non-bool "enabled" -- the diagnostic's keyPath must be
		// something the positions map can resolve back to a real line.
		$malformed = $result['data'];
		$malformed[0]['enabled'] = 'true';

		$diagnostics = SchemaValidator::validate($handler->schema(), $malformed);

		$this->assertCount(1, $diagnostics);
		$keyPath = $diagnostics[0]->keyPath;
		$this->assertArrayHasKey($keyPath, $result['positions']);
		$this->assertSame(5, $result['positions'][$keyPath]['line']);
	}
}
?>
