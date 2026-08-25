<?php

use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\SettingConfigHandler;
use Quiote\Exception\ConfigurationException;

/**
 * A setting whose value a deployment decides: `%env(NAME)%` in settings.xml compiles to the
 * placeholder and becomes a value -- of the right type -- when the compiled artifact is loaded. This
 * is the path a `replay.enabled` style flag takes from a container's environment into
 * {@see Config}.
 */
class SettingEnvPlaceholderTest extends ConfigHandlerTestBase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$dir = tempnam(sys_get_temp_dir(), 'sep_');
		if ($dir === false) {
			throw new \RuntimeException('Failed to create a temporary directory for the test.');
		}
		unlink($dir);
		mkdir($dir);
		$this->dir = $dir;
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		putenv('QUIOTE_TEST_REPLAY_ENABLED');
		putenv('QUIOTE_TEST_REPLAY_RECORD');
		Config::remove('replay.enabled');
		Config::remove('replay.record');
		Config::remove('replay.sample_rate');
		parent::tearDown();
	}

	/**
	 * Compiles a settings file the way the config cache does -- canonical array, declaration, cache
	 * file, include -- and applies the result, so what lands in Config here is what a request would
	 * see.
	 */
	private function loadSettings(string $xml): void
	{
		$path = $this->dir . '/settings.xml';
		file_put_contents($path, sprintf(
			<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/settings/1.1">
    <ae:configuration>
        <settings prefix="replay.">
%s
        </settings>
    </ae:configuration>
</ae:configurations>
XML,
			$xml
		));

		$handler = new SettingConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$declaration = $handler->executeArray($driver->load($path, 'test'), $path);

		$handler->apply($this->roundTrip($declaration, $path, SettingConfigHandler::class), $path);
	}

	public function testASettingTakesItsValueAndItsTypeFromTheEnvironment(): void
	{
		putenv('QUIOTE_TEST_REPLAY_ENABLED=true');
		putenv('QUIOTE_TEST_REPLAY_RECORD=header');

		$this->loadSettings(
			'            <setting name="enabled">%env(QUIOTE_TEST_REPLAY_ENABLED)%</setting>' . "\n"
			. '            <setting name="record">%env(QUIOTE_TEST_REPLAY_RECORD)%</setting>'
		);

		// getBool() and getString() both reject a value of the wrong type, so these assertions are
		// also what proves the placeholder did not arrive as the string "true".
		$this->assertTrue(Config::getBool('replay.enabled'));
		$this->assertSame('header', Config::getString('replay.record'));
	}

	public function testTheCompiledDeclarationCarriesThePlaceholderRatherThanTheValue(): void
	{
		putenv('QUIOTE_TEST_REPLAY_ENABLED=true');

		$path = $this->dir . '/settings.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/settings/1.1">
    <ae:configuration>
        <settings prefix="replay.">
            <setting name="enabled">%env(QUIOTE_TEST_REPLAY_ENABLED)%</setting>
        </settings>
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new SettingConfigHandler();
		$declaration = $handler->executeArray((new XmlFormatDriver($handler))->load($path, 'test'), $path);

		// What a warmed cache shipped in an image holds: the placeholder, not the build machine's
		// answer to it.
		$this->assertSame(['replay.enabled' => '%env(QUIOTE_TEST_REPLAY_ENABLED)%'], $declaration);
	}

	public function testAFallbackIsWhatTheSettingMeansWhenTheDeploymentSaysNothing(): void
	{
		$this->loadSettings(
			'            <setting name="enabled">%env(QUIOTE_TEST_REPLAY_ENABLED, false)%</setting>' . "\n"
			. '            <setting name="sample_rate">%env(QUIOTE_TEST_REPLAY_RATE, 0.1)%</setting>'
		);

		$this->assertFalse(Config::getBool('replay.enabled'));
		$this->assertSame(0.1, Config::getFloat('replay.sample_rate'));
	}

	public function testAPlaceholderInsideALongerValueIsSubstituted(): void
	{
		putenv('QUIOTE_TEST_REPLAY_RECORD=eu-west-1');

		$this->loadSettings('            <setting name="record">https://%env(QUIOTE_TEST_REPLAY_RECORD)%.example.com/v1</setting>');

		$this->assertSame('https://eu-west-1.example.com/v1', Config::getString('replay.record'));
	}

	/**
	 * The two kinds of reference coexist in one value: `%directive%` is expanded while the file is
	 * compiled, `%env(NAME)%` survives that pass untouched and is resolved when the artifact loads.
	 */
	public function testADirectiveAndAPlaceholderInTheSameValueAreEachResolvedAtTheirOwnTime(): void
	{
		putenv('QUIOTE_TEST_REPLAY_RECORD=tenant-a');

		$this->loadSettings('            <setting name="record">%core.app_dir%/%env(QUIOTE_TEST_REPLAY_RECORD)%/cassettes</setting>');

		$this->assertSame(
			Config::getString('core.app_dir') . '/tenant-a/cassettes',
			Config::getString('replay.record')
		);
	}

	public function testAnUnsetVariableWithNoFallbackFailsTheLoadRatherThanSilentlyUnsettingTheSetting(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('QUIOTE_TEST_REPLAY_ENABLED');

		$this->loadSettings('            <setting name="enabled">%env(QUIOTE_TEST_REPLAY_ENABLED)%</setting>');
	}

	/**
	 * The same compiled artifact, two different environments, two different settings -- with nothing
	 * recompiled in between. This is what a deployment changing a variable and restarting gets.
	 */
	public function testTheSameArtifactAnswersToWhicheverEnvironmentLoadsIt(): void
	{
		$declaration = ['replay.enabled' => '%env(QUIOTE_TEST_REPLAY_ENABLED)%'];
		$handler = new SettingConfigHandler();

		putenv('QUIOTE_TEST_REPLAY_ENABLED=false');
		$handler->apply($this->roundTrip($declaration, 'settings.xml'), 'settings.xml');
		$this->assertFalse(Config::getBool('replay.enabled'));

		Config::remove('replay.enabled');

		putenv('QUIOTE_TEST_REPLAY_ENABLED=true');
		$handler->apply($this->roundTrip($declaration, 'settings.xml'), 'settings.xml');
		$this->assertTrue(Config::getBool('replay.enabled'));
	}
}
