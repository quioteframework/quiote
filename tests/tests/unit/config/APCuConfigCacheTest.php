<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Exception\CacheException;
use Quiote\Exception\ConfigurationException;

/**
 * Tests for APCuConfigCache — exercises the APCu code path.
 * These tests require the APCu extension with apc.enable_cli=1. APCu is disabled
 * in the default test run for determinism (the shared APCu store is process-wide
 * state that otherwise bleeds between tests), so this class is tagged with the
 * "apcu" group, which the default phpunit config excludes. Run it explicitly with:
 *     composer test:apcu
 * (which sets apc.enable_cli=1 and selects --group apcu). When run without APCu
 * enabled, every test self-skips.
 */
#[\PHPUnit\Framework\Attributes\Group('apcu')]
class APCuConfigCacheTest extends PhpUnitTestCase
{
	private bool $apcuAvailable;

	protected function setUp(): void
	{
		parent::setUp();
		$this->apcuAvailable = extension_loaded('apcu')
			&& function_exists('apcu_enabled')
			&& apcu_enabled();

		if (!$this->apcuAvailable) {
			$this->markTestSkipped('APCu is not available or not enabled for CLI (apc.enable_cli=1 required).');
		}

		// Start each test with a clean slate
		APCuConfigCache::clear();
	}

	protected function tearDown(): void
	{
		if ($this->apcuAvailable) {
			APCuConfigCache::clear();
		}
		\Quiote\Support\Clock\Clock::useClock(null);
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// isAvailable / basic sanity
	// ---------------------------------------------------------------

	public function testIsAvailableReturnsTrue(): void
	{
		$this->assertTrue(APCuConfigCache::isAvailable());
	}

	// ---------------------------------------------------------------
	// writeCacheFile stores in APCu (not filesystem)
	// ---------------------------------------------------------------

	private function configKey(string $config, ?string $context = null): string
	{
		$method = new ReflectionMethod(APCuConfigCache::class, 'getConfigKey');
		$key = $method->invoke(null, $config, $context);
		self::assertIsString($key);

		return $key;
	}

	public function testWriteCacheFileStoresTheValueInApcuNotAFileOfPhp(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);
		$declaration = ['core.app_name' => 'apcu test', 'nested' => [1, 2]];

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		APCuConfigCache::writeCacheFile($config, $cacheName, $declaration);

		$this->assertFileDoesNotExist($cacheName, 'APCu writeCacheFile should not write to the filesystem');
		// The entry is the value itself, so reading it costs a fetch rather than compiling PHP.
		$this->assertSame($declaration, apcu_fetch($this->configKey($config)));
	}

	/**
	 * A stored entry *is* the loaded value here -- there is no include to read the environment at --
	 * so the environment has to be read on the way in, which is the same moment the file cache's
	 * artifact reads it.
	 */
	public function testWriteCacheFileResolvesAnEnvironmentPlaceholderBeforeStoringIt(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		putenv('QUIOTE_TEST_APCU_FLAG=true');
		try {
			APCuConfigCache::writeCacheFile($config, $cacheName, ['replay.enabled' => '%env(QUIOTE_TEST_APCU_FLAG)%']);

			$this->assertSame(['replay.enabled' => true], apcu_fetch($this->configKey($config)));
			$this->assertSame(['replay.enabled' => true], APCuConfigCache::loadValue($config));
		} finally {
			putenv('QUIOTE_TEST_APCU_FLAG');
		}
	}

	/**
	 * Shared memory serializes what it stores, so a value it cannot reproduce faithfully has to go to
	 * the file cache instead of being served as a broken clone.
	 */
	public function testWriteCacheFileFallsBackToTheFileCacheForAValueSharedMemoryCannotHold(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		try {
			$this->expectException(CacheException::class);
			APCuConfigCache::writeCacheFile($config, $cacheName, ['handler' => new \stdClass()]);
		} finally {
			$this->assertFalse(apcu_fetch($this->configKey($config)), 'nothing unstorable should reach APCu');
			if (file_exists($cacheName)) {
				unlink($cacheName);
			}
		}
	}

	/**
	 * The base cache's checkConfig() promises the path of a compiled file. There is no such file here,
	 * so the promise is refused rather than answered with a path nothing ever wrote.
	 */
	public function testCheckConfigRefusesToInventAPathForAValueInSharedMemory(): void
	{
		$this->expectException(CacheException::class);
		$this->expectExceptionMessageMatches('/held in shared memory, not in a file/');
		APCuConfigCache::checkConfig(Config::getString('core.config_dir') . '/tests/importtest.xml');
	}

	// ---------------------------------------------------------------
	// loadValue() — the read path
	// ---------------------------------------------------------------

	public function testLoadValueServesAnAlreadyStoredValueFromSharedMemory(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		apcu_store($this->configKey($config), ['seeded' => true]);

		$this->assertSame(['seeded' => true], APCuConfigCache::loadValue($config));
	}

	/**
	 * A compiled config may legitimately return null or false, and the fetch must not read that as a
	 * miss -- otherwise such a config recompiles on every single load.
	 */
	public function testLoadValueTreatsAStoredFalseAsAHitNotAMiss(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		apcu_store($this->configKey($config), false);

		$this->assertFalse(APCuConfigCache::loadValue($config));
		// Still the seeded entry: nothing recompiled over it.
		$this->assertFalse(apcu_fetch($this->configKey($config)));
	}

	public function testLoadValueCompilesOnAMissAndKeepsTheValueForNextTime(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		apcu_delete($this->configKey($config));

		$value = APCuConfigCache::loadValue($config);

		$this->assertSame(['constant' => 'ConfigCacheImportTest_included'], $value);
		$this->assertSame($value, apcu_fetch($this->configKey($config)), 'the compiled value should now be in APCu');
	}

	public function testLoadValueWithAContextStoresUnderTheContextSpecificKey(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$context = 'testing';

		$value = APCuConfigCache::loadValue($config, $context);

		$this->assertSame($value, apcu_fetch($this->configKey($config, $context)));
		$this->assertFalse(apcu_fetch($this->configKey($config, null)), 'the context-less key must stay untouched');
	}

	public function testDifferentContextsUseDifferentKeys(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');

		$key1 = $method->invoke(null, $config, 'web');
		$key2 = $method->invoke(null, $config, 'console');
		$key3 = $method->invoke(null, $config, null);

		$this->assertNotSame($key1, $key2, 'Different contexts should produce different keys');
		$this->assertNotSame($key1, $key3, 'Context vs null should produce different keys');
	}

	// ---------------------------------------------------------------
	// load() — the primary entry point
	// ---------------------------------------------------------------

	/**
	 * Seed APCu with a compiled artifact for the given config, as a warmup or an earlier cold compile
	 * would have left it, and return the global its handler's apply() will set.
	 */
	private function seedDeclaration(string $config, string $globalKey): void
	{
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		self::assertIsString($key);

		apcu_store($key, ['global' => $globalKey]);
	}

	public function testLoadAppliesTheDeclarationHeldInApcu(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest_once.xml';
		$globalKey = 'apcu_load_test_' . mt_rand();
		$this->seedDeclaration($config, $globalKey);

		$this->assertArrayNotHasKey($globalKey, $GLOBALS);

		APCuConfigCache::load($config);

		$this->assertTrue($GLOBALS[$globalKey] ?? false, 'load() should have applied the declaration from APCu');
	}

	public function testLoadOnceDoesNotReApply(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest_once.xml';
		$globalKey = 'apcu_load_once_' . mt_rand();
		$this->seedDeclaration($config, $globalKey);

		APCuConfigCache::load($config, null, true);
		$this->assertTrue($GLOBALS[$globalKey]);

		$GLOBALS[$globalKey] = false;
		APCuConfigCache::load($config, null, true);

		$this->assertFalse($GLOBALS[$globalKey], 'load($config, null, true) should only apply once');
	}

	public function testLoadWithOnceFalseReApplies(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest_once.xml';
		$globalKey = 'apcu_load_multi_' . mt_rand();
		$this->seedDeclaration($config, $globalKey);

		APCuConfigCache::load($config, null, false);
		$this->assertTrue($GLOBALS[$globalKey]);

		$GLOBALS[$globalKey] = false;
		APCuConfigCache::load($config, null, false);

		$this->assertTrue($GLOBALS[$globalKey], 'load($config, null, false) should apply every time');
	}

	// ---------------------------------------------------------------
	// clear()
	// ---------------------------------------------------------------

	public function testClearRemovesApcuEntries(): void
	{
		// Seed some data
		apcu_store('quiote_config_testkey1', 'data1');
		apcu_store('quiote_config_testkey2', 'data2');

		$this->assertNotFalse(apcu_fetch('quiote_config_testkey1'));

		APCuConfigCache::clear();

		$this->assertFalse(apcu_fetch('quiote_config_testkey1'), 'clear() should remove quiote_ prefixed keys');
		$this->assertFalse(apcu_fetch('quiote_config_testkey2'), 'clear() should remove quiote_ prefixed keys');
	}

	public function testClearResetsAppliedConfigsTracking(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest_once.xml';
		$globalKey = 'apcu_clear_reload_' . mt_rand();
		$this->seedDeclaration($config, $globalKey);

		APCuConfigCache::load($config, null, true);
		$this->assertTrue($GLOBALS[$globalKey]);

		// Clear and re-seed: clear() drops both the source and the cached value.
		APCuConfigCache::clear();
		$GLOBALS[$globalKey] = false;
		$this->seedDeclaration($config, $globalKey);

		APCuConfigCache::load($config, null, true);
		$this->assertTrue($GLOBALS[$globalKey], 'After clear(), load() should apply again even with $once=true');
	}

	// ---------------------------------------------------------------
	// isWarmedUp / getStatus
	// ---------------------------------------------------------------

	public function testIsWarmedUpReturnsFalseBeforeWarmup(): void
	{
		$this->assertFalse(APCuConfigCache::isWarmedUp());
	}

	public function testGetStatusReportsAvailable(): void
	{
		$status = APCuConfigCache::getStatus();
		$this->assertTrue($status['available']);
		$this->assertFalse($status['warmed_up']);
		$this->assertArrayHasKey('memory_usage', $status);
	}

	/**
	 * age_seconds is wall-clock (the warmup metadata is compared against a
	 * later request's real time, potentially in a different process), so an
	 * injected clock must be honoured for both the stamp and the read-back.
	 */
	public function testGetStatusAgeSecondsIsComputedFromTheInjectedClock(): void
	{
		$clock = new \Quiote\Support\Clock\FrozenClock(1_000_000.0);
		\Quiote\Support\Clock\Clock::useClock($clock);

		APCuConfigCache::warmup(['tests/importtest.xml'], 'testing');
		$clock->advance(42.0);

		$status = APCuConfigCache::getStatus();
		$this->assertSame(42, $status['age_seconds']);
	}

	// ---------------------------------------------------------------
	// configure()
	// ---------------------------------------------------------------

	public function testConfigureChangesPrefix(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';

		APCuConfigCache::configure(['config_prefix' => 'custom_pfx_']);

		// After configure, new keys should use the custom prefix
		$reflection = new ReflectionClass(APCuConfigCache::class);

		// Reset key cache since prefix changed
		$keyCacheProp = $reflection->getProperty('keyCache');
		$keyCacheProp->setValue(null, []);

		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		self::assertIsString($key);

		$this->assertStringStartsWith('custom_pfx_', $key, 'configure() should change the key prefix');

		// Restore default
		APCuConfigCache::configure(['config_prefix' => 'quiote_config_']);
		$keyCacheProp->setValue(null, []);
	}

	public function testConfigureThrowsWhenConfigPrefixIsNotAString(): void
	{
		$this->expectException(ConfigurationException::class);
		APCuConfigCache::configure(['config_prefix' => 123]);
	}

	public function testConfigureThrowsWhenRoutingPrefixIsNotAString(): void
	{
		$this->expectException(ConfigurationException::class);
		APCuConfigCache::configure(['routing_prefix' => ['not', 'a', 'string']]);
	}

	public function testConfigureThrowsWhenTtlIsNotIntOrNumericString(): void
	{
		$this->expectException(ConfigurationException::class);
		APCuConfigCache::configure(['ttl' => ['not', 'a', 'ttl']]);
	}

	public function testConfigureAcceptsNumericStringTtl(): void
	{
		APCuConfigCache::configure(['ttl' => '3600']);
		$this->addToAssertionCount(1);
		// Restore default
		APCuConfigCache::configure(['ttl' => 0]);
	}

	// ---------------------------------------------------------------
	// Corrupted APCu entries must fail loudly rather than silently
	// misbehave (e.g. concatenating a non-string, or eval'ing garbage).
	// ---------------------------------------------------------------

	public function testGetStatusThrowsWhenWarmupMetadataIsMalformed(): void
	{
		apcu_store('quiote_warmup_meta', ['configs' => ['a.xml']]); // missing 'timestamp'

		$this->expectException(CacheException::class);
		APCuConfigCache::getStatus();
	}

	// ---------------------------------------------------------------
	// Integration: full round-trip through checkConfig (compile → store → hit)
	// ---------------------------------------------------------------

	public function testFullRoundTripCompileStoreHit(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$key = $this->configKey($config);
		apcu_delete($key);

		$cacheName = ConfigCache::getCacheName($config);
		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// First call compiles and stores the value; nothing is written to disk.
		$first = APCuConfigCache::loadValue($config);
		$this->assertSame($first, apcu_fetch($key));
		$this->assertFileDoesNotExist($cacheName, 'a compiled config must not reach the filesystem when APCu holds it');

		// Second call is a straight fetch, and returns the same value.
		$this->assertSame($first, APCuConfigCache::loadValue($config));
	}

	// ---------------------------------------------------------------
	// Fallback when APCu is unavailable
	// ---------------------------------------------------------------

	public function testWriteCacheFileFallsBackToFilesystemWhenApcuUnavailable(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);
		$declaration = ['core.app_name' => 'fallback test'];

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// Temporarily disable APCu availability via reflection
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$prop = $reflection->getProperty('apcuAvailable');
		$prop->setValue(null, false);

		try {
			APCuConfigCache::writeCacheFile($config, $cacheName, $declaration, 'TestHandler');

			$this->assertFileExists($cacheName, 'When APCu is unavailable, should fall back to filesystem');
			$this->assertSame($declaration, include $cacheName);
		} finally {
			// Restore
			$prop->setValue(null, null); // null triggers re-detection
			if (file_exists($cacheName)) {
				unlink($cacheName);
			}
		}
	}

	// ---------------------------------------------------------------
	// Regression: cold compile must not leak config_handlers to disk
	// (loadConfigHandlersFile() previously reset late static binding to
	//  ConfigCache, forcing a filesystem write even with APCu enabled)
	// ---------------------------------------------------------------

	public function testColdCompileUnderApcuWritesNoConfigHandlersToFilesystem(): void
	{
		$cacheConfigDir = Config::getString('core.cache_dir') . DIRECTORY_SEPARATOR . 'config';

		// Start fully cold: clear APCu + filesystem cache, and forget the loaded
		// handlers so the next compile runs loadConfigHandlers()/loadConfigHandlersFile()
		// as a side effect (this is the path the public-API tests never exercise).
		APCuConfigCache::clear();
		\TestingConfigCache::resetHandlers();

		// Trigger a cold compile through the APCu entrypoint so late static binding
		// is APCuConfigCache for the whole chain.
		$settings = Config::getString('core.config_dir') . '/settings.xml';
		$value = APCuConfigCache::loadValue($settings);
		$this->assertIsArray($value, 'settings.xml should compile to a declaration');
		$this->assertSame($value, apcu_fetch($this->configKey($settings)), 'settings.xml should be served from APCu, not the filesystem');

		// The bug: config_handlers.xml used to be written to the filesystem here.
		// With LSB preserved it stays in APCu, so no config_handlers cache file
		// should exist on disk.
		$handlerFiles = is_dir($cacheConfigDir) ? glob($cacheConfigDir . DIRECTORY_SEPARATOR . 'config_handlers*') : [];
		$this->assertSame([], $handlerFiles, 'config_handlers must not be written to the filesystem when APCu is enabled');
	}

	// ---------------------------------------------------------------
	// Regression: warmup() must not crash or produce write-only routing
	// data when a routing.xml happens to exist in core.config_dir. There is
	// no live ConfigCache handler for routing.xml (routing is the Routing
	// class's job), so warmup() must simply skip it rather than attempt to
	// compile it.
	// ---------------------------------------------------------------

	public function testWarmupCompletesWithoutCrashingWhenRoutingXmlExists(): void
	{
		$configDir = Config::getString('core.config_dir');
		$routingXmlPath = $configDir . '/routing.xml';
		$createdRoutingXml = !file_exists($routingXmlPath);

		if ($createdRoutingXml) {
			file_put_contents($routingXmlPath, "<?xml version=\"1.0\"?>\n<routing />\n");
		}

		try {
			$stats = APCuConfigCache::warmup(['tests/importtest.xml'], 'testing');

			$this->assertSame([], $stats['errors'], 'warmup() should not report errors just because routing.xml exists');
			$this->assertFalse($stats['routing_warmed'], 'routing_warmed must stay false: there is no live routing.xml warmup path');
			$this->assertFalse(
				apcu_fetch('quiote_routing_data'),
				'warmup() must not write routing data to APCu: nothing ever reads it back'
			);
		} finally {
			if ($createdRoutingXml && is_file($routingXmlPath)) {
				unlink($routingXmlPath);
			}
		}
	}

	// ---------------------------------------------------------------
	// Regression: a nested compile must not clobber the pending context,
	// which would store the outer config under the wrong (null) key and
	// then fall back to a non-existent filesystem path
	// ("require(...): No such file or directory").
	// ---------------------------------------------------------------

	public function testNestedCompileDoesNotClobberContextKey(): void
	{
		$config = Config::getString('core.config_dir') . '/tests/importtest.xml';
		$context = 'web';

		// Start cold and forget handlers so compiling $config also runs
		// loadConfigHandlers() -> a NESTED checkConfig() for config_handlers.xml
		// (with a null context). That nested call must restore, not null out, the
		// outer pending context.
		APCuConfigCache::clear();
		\TestingConfigCache::resetHandlers();

		// Cold compile WITH a context. The compiled config must end up stored under
		// that context in APCu, so checkConfig returns the marker (not a filesystem
		// path that was never written).
		$value = APCuConfigCache::loadValue($config, $context);
		$this->assertSame(
			$value,
			apcu_fetch($this->configKey($config, $context)),
			'cold compile with a nested handler load must still store under the correct context'
		);

		// A second lookup under the same context must be a straight APCu hit.
		$this->assertSame($value, APCuConfigCache::loadValue($config, $context));
	}
}
