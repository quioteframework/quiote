<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\XmlConfigParser;

/**
 * Proves the ONE importNode() call site that matters (the $configurationOrder
 * merge loop) correctly correlates each merged <configuration> element back
 * to its own source file and line -- the mechanism PluginConfigHandler's
 * position support (and any future handler's) relies on.
 */
class XmlConfigParserPositionCorrelationTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'xcppc_');
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

	public function testMergedElementResolvesToItsRealSourceLine(): void
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

		$positions = new ElementPositionIndex();
		$document = XmlConfigParser::run($path, 'test', '', [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		], [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		], $positions);
		$document->setDefaultNamespace('http://quiote.dev/quiote/config/parts/plugins/1.1', 'plugins');

		$configurations = $document->getConfigurationElements();
		$this->assertCount(1, $configurations);

		$plugin = $configurations[0]->get('plugins')[0];
		$position = $positions->forElement($plugin);

		$this->assertNotNull($position);
		$this->assertSame($path, $position['file']);
		$this->assertSame(5, $position['line']);
	}

	public function testEachMergedElementResolvesToItsOwnContributingFile(): void
	{
		$parentPath = $this->dir . '/parent.xml';
		$childPath = $this->dir . '/plugins.xml';

		file_put_contents($parentPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1">
    <ae:configuration>
        <plugin class="App\Plugin\FromParent" enabled="true" />
    </ae:configuration>
</ae:configurations>
XML);

		file_put_contents($childPath, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1"
                    parent="$parentPath">
    <ae:configuration>
        <plugin class="App\Plugin\FromChild" enabled="true" />
    </ae:configuration>
</ae:configurations>
XML);

		$positions = new ElementPositionIndex();
		$document = XmlConfigParser::run($childPath, 'test', '', [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		], [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		], $positions);
		$document->setDefaultNamespace('http://quiote.dev/quiote/config/parts/plugins/1.1', 'plugins');

		$plugins = [];
		foreach ($document->getConfigurationElements() as $configuration) {
			foreach ($configuration->get('plugins') as $plugin) {
				$plugins[(string) $plugin->getAttribute('class')] = $positions->forElement($plugin);
			}
		}

		$this->assertNotNull($plugins['App\\Plugin\\FromParent']);
		$this->assertSame($parentPath, $plugins['App\\Plugin\\FromParent']['file']);

		$this->assertNotNull($plugins['App\\Plugin\\FromChild']);
		$this->assertSame($childPath, $plugins['App\\Plugin\\FromChild']['file']);
	}

	public function testPositionsAreUntouchedWhenNoIndexIsPassed(): void
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

		// Existing call convention (no $positions arg) must behave identically.
		$document = XmlConfigParser::run($path, 'test', '', [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		], [
			XmlConfigParser::STAGE_SINGLE => [],
			XmlConfigParser::STAGE_COMPILATION => [],
		]);

		$this->assertCount(1, $document->getConfigurationElements());
	}
}
?>
