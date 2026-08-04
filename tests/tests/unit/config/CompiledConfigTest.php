<?php

use Quiote\Config\APCuConfigCache;
use Quiote\Config\CompiledConfig;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Covers reading a compiled configuration's value without compiling PHP at the call site.
 */
class CompiledConfigTest extends PhpUnitTestCase
{
	/**
	 * A config-handlers artifact, which is the one compiled config guaranteed to exist in the test
	 * sandbox and to return data rather than execute statements.
	 */
	private function handlersConfigPath(): string
	{
		return Config::getString('core.config_dir') . '/config_handlers.xml';
	}

	public function testLoadValueReturnsTheCompiledConfigsValue(): void
	{
		$value = ConfigCache::loadValue($this->handlersConfigPath());

		$this->assertIsArray($value);
		$this->assertNotEmpty($value, 'the sandbox config_handlers.xml declares at least one handler');
	}

	public function testCompiledConfigValueAgreesWithTheCacheItDelegatesTo(): void
	{
		$path = $this->handlersConfigPath();

		$this->assertSame(
			ConfigCache::loadValue($path),
			CompiledConfig::value($path),
			'CompiledConfig must only choose an implementation, never alter the value',
		);
	}

	public function testRepeatedReadsAgreeWithEachOther(): void
	{
		$path = $this->handlersConfigPath();

		$first = CompiledConfig::value($path);
		$second = CompiledConfig::value($path);

		$this->assertSame($first, $second, 'a second read must not observe a different configuration');
	}

	/**
	 * The APCu store keeps a compiled config as PHP source, so the value has to be cached separately
	 * for the source not to be recompiled on every load. Verifies the value entry actually appears,
	 * rather than only that the returned data is right — the latter holds either way.
	 */
	public function testApcuStoresTheValueBesideTheSourceSoItIsNotRecompiled(): void
	{
		if(!APCuConfigCache::isAvailable() || !class_exists('APCUIterator')) {
			$this->markTestSkipped('APCu with APCUIterator is required to observe the value entry.');
		}

		$path = $this->handlersConfigPath();

		$value = APCuConfigCache::loadValue($path);
		$this->assertIsArray($value);

		$valueKeys = [];
		foreach(new \APCUIterator('/^quiote_config_.*:value$/') as $key => $ignored) {
			$valueKeys[] = $key;
		}

		$this->assertNotEmpty($valueKeys, 'loading a compiled config under APCu must cache its value');
		$this->assertSame($value, APCuConfigCache::loadValue($path), 'the cached value must match the compiled one');
	}

	public function testMalformedArtifactIsRejectedRatherThanFedToTheHandlerPipeline(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/did not return an array/');

		$this->assertHandlerInfoMap('not an array');
	}

	public function testArtifactWhoseEntryIsNotAnArrayIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/is string, not an array/');

		$this->assertHandlerInfoMap(['some.xml' => 'nonsense']);
	}

	public function testArtifactWithNoHandlerClassIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/names no handler class/');

		$this->assertHandlerInfoMap(['some.xml' => ['parameters' => [], 'transformations' => [], 'validations' => []]]);
	}

	public function testArtifactMissingAnExpectedArrayIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/missing its "transformations" array/');

		$this->assertHandlerInfoMap([
			'some.xml' => ['class' => 'SomeHandler', 'parameters' => [], 'validations' => []],
		]);
	}

	public function testArtifactWithAMalformedTransformationIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/malformed transformation/');

		$this->assertHandlerInfoMap([
			'some.xml' => [
				'class' => 'SomeHandler',
				'parameters' => [],
				'transformations' => ['before' => [new stdClass()]],
				'validations' => [],
			],
		]);
	}

	/**
	 * A handler class that cannot be autoloaded yet must be accepted: a handlers file is read before
	 * the classes it names are necessarily available, so rejecting one here would refuse a valid
	 * configuration.
	 */
	public function testWellFormedArtifactIsAcceptedEvenWhenItsHandlerClassIsNotLoadable(): void
	{
		$this->assertHandlerInfoMap([
			'some.xml' => [
				'class' => 'Not\\Yet\\Autoloadable\\Handler',
				'parameters' => ['foo' => 'bar'],
				'transformations' => ['before' => ['one', 'two']],
				'validations' => ['before' => []],
			],
		]);

		$this->addToAssertionCount(1);
	}

	/**
	 * The guard is private because nothing outside the cache should be validating its artifacts;
	 * reached here directly so each rejection reason is pinned without having to forge a cache entry
	 * on disk or in shared memory.
	 */
	private function assertHandlerInfoMap(mixed $loaded): void
	{
		$method = new \ReflectionMethod(ConfigCache::class, 'assertHandlerInfoMap');
		$method->invoke(null, $loaded, '/sandbox/config_handlers.xml');
	}
}
