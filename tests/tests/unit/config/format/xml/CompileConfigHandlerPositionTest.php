<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\CompileConfigHandler;

/**
 * CompileConfigHandler isn't registered in the shipped config_handlers.xml
 * at all (it's an app-registered handler type), so unlike the framework's
 * own config types there are no default <transformation> stylesheets to
 * worry about -- positions are real out of the box, same as plugins.xml.
 */
class CompileConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'ccp_');
		unlink($this->dir);
		mkdir($this->dir);
		Config::set('core.debug', false, true, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	public function testCompileEntryResolvesToItsSourceLine(): void
	{
		$compiledFile = $this->dir . '/snippet.php';
		file_put_contents($compiledFile, "<?php\necho 'X';\n");

		$path = $this->dir . '/compile.xml';
		file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/compile/1.1">
    <ae:configuration>
        <compiles>
            <compile>$compiledFile</compile>
        </compiles>
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new CompileConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$result = $driver->loadWithPositions($path, 'test');

		$resolved = realpath($compiledFile);
		$this->assertIsString($resolved);
		$this->assertArrayHasKey($resolved, $result['data']);
		$this->assertSame($path, $result['positions'][$resolved]['file']);
		$this->assertSame(6, $result['positions'][$resolved]['line']);
	}
}
?>
