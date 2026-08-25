<?php

use Quiote\Config\Config;
use Quiote\Config\PluginConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\XmlConfigParser;
use Quiote\Exception\ConfigurationException;
use Quiote\Config\Format\XmlFormatDriver;

/**
 * Turning a plugin on for a deployment by setting an environment variable: `enabled="%env(NAME)%"`
 * cannot be decided while plugins.xml is compiled, so the entry survives compilation and the
 * environment decides when the artifact is loaded. Restarting the process is what picks up a change;
 * the compiled cache does not have to be rebuilt.
 */
class PluginEnvToggleTest extends ConfigHandlerTestBase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$dir = tempnam(sys_get_temp_dir(), 'pet_');
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
		putenv('QUIOTE_TEST_REPLAY_PLUGIN');
		Config::remove('plugins');
		parent::tearDown();
	}

	private function pluginsFile(string $enabled): string
	{
		$path = $this->dir . '/plugins.xml';
		file_put_contents($path, sprintf(
			<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/plugins/1.1">
    <ae:configuration>
        <plugin class="App\Plugin\Always" />
        <plugin class="App\Plugin\Replay" enabled="%s" />
    </ae:configuration>
</ae:configurations>
XML,
			$enabled
		));

		return $path;
	}

	/**
	 * The canonical array the XML driver loaded, with the shape this handler requires re-established:
	 * FormatDriverInterface::load() is format-agnostic and returns array<string, mixed>, so the real
	 * invariant is asserted here rather than assumed.
	 *
	 * @param array<string, mixed> $config
	 * @return list<array{class: string, enabled?: bool|string}>
	 */
	private function asPluginList(array $config): array
	{
		$plugins = [];
		foreach ($config as $entry) {
			self::assertIsArray($entry);
			self::assertArrayHasKey('class', $entry);
			self::assertIsString($entry['class']);
			$enabled = $entry['enabled'] ?? true;
			self::assertTrue(is_bool($enabled) || is_string($enabled));
			$plugins[] = ['class' => $entry['class'], 'enabled' => $enabled];
		}

		return $plugins;
	}

	/**
	 * The declaration as the framework reads it back: compiled, written to a cache file, included
	 * again -- which is the moment the environment is read.
	 */
	private function compileAndApply(string $path): void
	{
		$handler = new PluginConfigHandler();
		$driver = new XmlFormatDriver($handler);
		$declaration = $handler->executeArray($this->asPluginList($driver->load($path, 'test')), $path);

		$handler->apply($this->roundTrip($declaration, $path, PluginConfigHandler::class), $path);
	}

	/**
	 * The XSD has to accept a placeholder where it accepts a boolean, or the config file never gets
	 * as far as being compiled.
	 */
	public function testTheSchemaAcceptsAPlaceholderWhereItAcceptsABoolean(): void
	{
		$document = new XmlConfigDomDocument();
		$document->load($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN)%'));

		XmlConfigParser::validateXmlschema($document, [Config::getString('core.quiote_dir') . '/Config/xsd/plugins.xsd']);
		$this->addToAssertionCount(1);
	}

	public function testTheSchemaStillRejectsSomethingThatIsNeitherBooleanNorPlaceholder(): void
	{
		$document = new XmlConfigDomDocument();
		$document->load($this->pluginsFile('maybe'));

		$this->expectException(\Quiote\Exception\ParseException::class);
		XmlConfigParser::validateXmlschema($document, [Config::getString('core.quiote_dir') . '/Config/xsd/plugins.xsd']);
	}

	public function testAPlaceholderSurvivesCompilationInsteadOfBeingDecidedThere(): void
	{
		$handler = new PluginConfigHandler();
		$driver = new XmlFormatDriver($handler);

		$canonical = $this->asPluginList($driver->load($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN)%'), 'test'));
		$this->assertSame([
			['class' => 'App\\Plugin\\Always', 'enabled' => true],
			['class' => 'App\\Plugin\\Replay', 'enabled' => '%env(QUIOTE_TEST_REPLAY_PLUGIN)%'],
		], $canonical);

		$this->assertSame([
			'App\\Plugin\\Always',
			['class' => 'App\\Plugin\\Replay', 'enabled' => '%env(QUIOTE_TEST_REPLAY_PLUGIN)%'],
		], $handler->executeArray($canonical, 'plugins.xml'));
	}

	public function testTheVariableTurnsThePluginOn(): void
	{
		putenv('QUIOTE_TEST_REPLAY_PLUGIN=true');

		$this->compileAndApply($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN)%'));

		$this->assertSame(['App\\Plugin\\Always', 'App\\Plugin\\Replay'], Config::getArray('plugins'));
	}

	public function testTheVariableTurnsThePluginOff(): void
	{
		putenv('QUIOTE_TEST_REPLAY_PLUGIN=false');

		$this->compileAndApply($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN)%'));

		$this->assertSame(['App\\Plugin\\Always'], Config::getArray('plugins'));
	}

	/**
	 * The same compiled declaration, a different environment, a different answer: nothing about the
	 * cache changes between these two, which is the whole point of deferring the decision.
	 */
	public function testTheSameDeclarationAnswersDifferentlyPerEnvironment(): void
	{
		$handler = new PluginConfigHandler();
		$declaration = ['App\\Plugin\\Always', ['class' => 'App\\Plugin\\Replay', 'enabled' => '%env(QUIOTE_TEST_REPLAY_PLUGIN)%']];

		putenv('QUIOTE_TEST_REPLAY_PLUGIN=on');
		$handler->apply($this->roundTrip($declaration, 'plugins.xml'), 'plugins.xml');
		$this->assertSame(['App\\Plugin\\Always', 'App\\Plugin\\Replay'], Config::getArray('plugins'));

		Config::remove('plugins');

		putenv('QUIOTE_TEST_REPLAY_PLUGIN=off');
		$handler->apply($this->roundTrip($declaration, 'plugins.xml'), 'plugins.xml');
		$this->assertSame(['App\\Plugin\\Always'], Config::getArray('plugins'));
	}

	public function testAnUnsetVariableWithNoFallbackIsReportedRatherThanTreatedAsOff(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('QUIOTE_TEST_REPLAY_PLUGIN');

		$this->compileAndApply($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN)%'));
	}

	public function testAFallbackDecidesWhenTheDeploymentSaysNothing(): void
	{
		$this->compileAndApply($this->pluginsFile('%env(QUIOTE_TEST_REPLAY_PLUGIN, false)%'));

		$this->assertSame(['App\\Plugin\\Always'], Config::getArray('plugins'));
	}

	/**
	 * A variable holding something that is not a boolean literal is a deployment mistake, and reading
	 * it as truthy would quietly enable a plugin nobody asked for.
	 */
	public function testAVariableThatIsNotABooleanLiteralIsRejected(): void
	{
		$handler = new PluginConfigHandler();

		putenv('QUIOTE_TEST_REPLAY_PLUGIN=sometimes');

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('App\\Plugin\\Replay');

		$handler->apply(
			$this->roundTrip([['class' => 'App\\Plugin\\Replay', 'enabled' => '%env(QUIOTE_TEST_REPLAY_PLUGIN)%']], 'plugins.xml'),
			'plugins.xml'
		);
	}

	/**
	 * The rejection says what the variable held, never the value itself: pointing this at a
	 * credential by mistake is exactly the way to get a non-boolean here, and the exception is logged
	 * and rendered.
	 */
	public function testTheRejectionDoesNotRepeatWhatTheEnvironmentAnswered(): void
	{
		$handler = new PluginConfigHandler();

		putenv('QUIOTE_TEST_REPLAY_PLUGIN=hunter2-actual-secret');

		try {
			$handler->apply(
				$this->roundTrip([['class' => 'App\\Plugin\\Replay', 'enabled' => '%env(QUIOTE_TEST_REPLAY_PLUGIN)%']], 'plugins.xml'),
				'plugins.xml'
			);
			$this->fail('Expected a ConfigurationException for a non-boolean environment value.');
		} catch (ConfigurationException $e) {
			$this->assertStringNotContainsString('hunter2-actual-secret', $e->getMessage());
			$this->assertStringContainsString('answered a string', $e->getMessage());
		}
	}

	public function testAMalformedEntryIsReportedWithoutRepeatingItsValues(): void
	{
		$handler = new PluginConfigHandler();

		try {
			$handler->apply([['class' => 42, 'enabled' => 'hunter2-actual-secret']], 'plugins.xml');
			$this->fail('Expected a ConfigurationException for a malformed entry.');
		} catch (ConfigurationException $e) {
			$this->assertStringNotContainsString('hunter2-actual-secret', $e->getMessage());
			$this->assertStringContainsString('keys [class, enabled]', $e->getMessage());
		}
	}

	public function testAnEntryThatIsNeitherAClassNameNorAPairIsRejected(): void
	{
		$handler = new PluginConfigHandler();

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('{class, enabled} pair');

		$handler->apply([['enabled' => true]], 'plugins.xml');
	}

	public function testAPairWithoutAnEnabledKeyIsRejected(): void
	{
		$handler = new PluginConfigHandler();

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('{class, enabled} pair');

		$handler->apply([['class' => 'App\\Plugin\\Replay']], 'plugins.xml');
	}

	/**
	 * A hand-authored PHP/YAML source writes the boolean literals the XSD accepts as strings; they
	 * mean there what they mean in XML, rather than being truthy because they are non-empty.
	 */
	public function testABooleanLiteralWrittenAsAStringIsStillABoolean(): void
	{
		$handler = new PluginConfigHandler();

		$this->assertSame(
			['App\\Plugin\\One'],
			$handler->executeArray([
				['class' => 'App\\Plugin\\One', 'enabled' => 'yes'],
				['class' => 'App\\Plugin\\Two', 'enabled' => 'off'],
			], 'plugins.yaml')
		);
	}
}
