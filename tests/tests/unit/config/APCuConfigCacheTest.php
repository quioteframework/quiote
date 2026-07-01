<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;

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

	public function testWriteCacheFileStoresInApcuNotFilesystem(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);
		$data = "<?php\n// test data\n\$GLOBALS['apcu_write_test'] = true;\n?>";

		// Remove any stale filesystem cache
		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		APCuConfigCache::writeCacheFile($config, $cacheName, $data);

		// Filesystem cache should NOT be written
		$this->assertFileDoesNotExist($cacheName, 'APCu writeCacheFile should not write to filesystem');

		// APCu should have the data — use the same key derivation as the class
		// (null context since writeCacheFile is called from callHandler without context)
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		// pendingContext will be set when called through checkConfig; for direct
		// writeCacheFile calls it's null (verified by examining the static property)
		$key = $method->invoke(null, $config, null);

		$stored = apcu_fetch($key);
		$this->assertNotFalse($stored, 'Data should be stored in APCu');
		$this->assertSame($data, $stored);
	}

	public function testWriteCacheFileAppendWorks(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);
		$part1 = "<?php\n// part 1\n";
		$part2 = "// part 2\n?>";

		APCuConfigCache::writeCacheFile($config, $cacheName, $part1);
		APCuConfigCache::writeCacheFile($config, $cacheName, $part2, true);

		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$stored = apcu_fetch($key);
		$this->assertSame($part1 . $part2, $stored, 'Appended data should be concatenated in APCu');
	}

	// ---------------------------------------------------------------
	// checkConfig — APCu hit path (eval from memory)
	// ---------------------------------------------------------------

	public function testCheckConfigEvalsFromApcuOnHit(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		// Pre-seed APCu with known PHP content
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$uniqueConst = 'APCU_CHECK_CONFIG_TEST_' . mt_rand();
		$phpContent = "<?php\ndefine('{$uniqueConst}', true);\n?>";
		apcu_store($key, $phpContent);

		// checkConfig returns the 'APCU:' marker but does NOT eval — callers do that.
		$result = APCuConfigCache::checkConfig($config);

		$this->assertStringStartsWith('APCU:', $result, 'checkConfig should return APCU: marker on hit');
		// Simulate what load() / ValidationService etc. do: eval in caller scope.
		eval('?>' . substr($result, 5));
		$this->assertTrue(defined($uniqueConst), 'caller-eval\'d PHP content from APCu should define the constant');
	}

	public function testCheckConfigFallsBackToParentOnMiss(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		// Ensure nothing in APCu for this config
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		apcu_delete($key);

		// Cold path: parent compiles the config, writeCacheFile() stores it in APCu
		// (no filesystem write), then checkConfig re-fetches and returns the marker.
		$result = APCuConfigCache::checkConfig($config);

		$this->assertStringStartsWith('APCU:', $result, 'First call should compile, store in APCu, and return APCU: marker');

		// Second call is a straightforward APCu hit — also returns the marker.
		$result2 = APCuConfigCache::checkConfig($config);
		$this->assertStringStartsWith('APCU:', $result2, 'Second call should hit APCu and return marker');
	}

	// ---------------------------------------------------------------
	// checkConfig with context — verifies the pendingContext fix
	// ---------------------------------------------------------------

	public function testCheckConfigWithContextStoresUnderCorrectKey(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';
		$context = 'testing';

		// First call: compiles and stores in APCu via writeCacheFile with pendingContext
		$result1 = APCuConfigCache::checkConfig($config, $context);

		// The key for this config+context should now exist in APCu
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$keyWithContext = $method->invoke(null, $config, $context);
		$keyWithoutContext = $method->invoke(null, $config, null);

		$this->assertNotFalse(
			apcu_fetch($keyWithContext),
			'Config should be stored under the context-specific APCu key'
		);

		// Second call should be an APCu hit
		$result2 = APCuConfigCache::checkConfig($config, $context);
		$this->assertStringStartsWith('APCU:', $result2, 'Second checkConfig with context should hit APCu');
	}

	public function testDifferentContextsUseDifferentKeys(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

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

	public function testLoadExecutesConfigFromApcu(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		// Pre-seed APCu
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_test_' . mt_rand();
		$phpContent = "<?php\n\$GLOBALS['{$globalKey}'] = 'loaded_from_apcu';\n?>";
		apcu_store($key, $phpContent);

		$this->assertArrayNotHasKey($globalKey, $GLOBALS);

		APCuConfigCache::load($config);

		$this->assertArrayHasKey($globalKey, $GLOBALS, 'load() should have eval\'d the PHP from APCu');
		$this->assertSame('loaded_from_apcu', $GLOBALS[$globalKey]);
	}

	public function testLoadOnceDoesNotReExecute(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_once_counter_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		APCuConfigCache::load($config, null, true);
		APCuConfigCache::load($config, null, true);

		$this->assertSame(1, $GLOBALS[$globalKey], 'load($config, null, true) should only execute once');
	}

	public function testLoadWithOnceFalseReExecutes(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_multi_counter_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		APCuConfigCache::load($config, null, false);
		APCuConfigCache::load($config, null, false);

		$this->assertSame(2, $GLOBALS[$globalKey], 'load($config, null, false) should execute every time');
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

	public function testClearResetsLoadedConfigsTracking(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_clear_reload_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		APCuConfigCache::load($config, null, true);
		$this->assertSame(1, $GLOBALS[$globalKey]);

		// Clear and re-seed
		APCuConfigCache::clear();
		apcu_store($key, $phpContent);

		// After clear, load($once=true) should execute again
		APCuConfigCache::load($config, null, true);
		$this->assertSame(2, $GLOBALS[$globalKey], 'After clear(), load() should execute again even with $once=true');
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

	// ---------------------------------------------------------------
	// configure()
	// ---------------------------------------------------------------

	public function testConfigureChangesPrefix(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		APCuConfigCache::configure(['config_prefix' => 'custom_pfx_']);

		// After configure, new keys should use the custom prefix
		$reflection = new ReflectionClass(APCuConfigCache::class);

		// Reset key cache since prefix changed
		$keyCacheProp = $reflection->getProperty('keyCache');
		$keyCacheProp->setValue(null, []);

		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$this->assertStringStartsWith('custom_pfx_', $key, 'configure() should change the key prefix');

		// Restore default
		APCuConfigCache::configure(['config_prefix' => 'quiote_config_']);
		$keyCacheProp->setValue(null, []);
	}

	// ---------------------------------------------------------------
	// Integration: full round-trip through checkConfig (compile → store → hit)
	// ---------------------------------------------------------------

	public function testFullRoundTripCompileStoreHit(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';

		// Ensure nothing cached
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		apcu_delete($key);

		// Delete filesystem cache too
		$cacheName = ConfigCache::getCacheName($config);
		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// First call: compiles config, writeCacheFile stores in APCu
		$result1 = APCuConfigCache::checkConfig($config);
		// Parent returns a file path (since it compiled and cached on filesystem)
		// But our writeCacheFile override stored in APCu instead

		// Verify data is now in APCu
		$stored = apcu_fetch($key);
		$this->assertNotFalse($stored, 'After first checkConfig, compiled data should be in APCu');
		$this->assertStringContainsString('<?php', $stored, 'Stored data should be valid PHP');

		// Second call: should hit APCu directly
		$result2 = APCuConfigCache::checkConfig($config);
		$this->assertStringStartsWith('APCU:', $result2, 'Second checkConfig should return APCU: marker');
	}

	// ---------------------------------------------------------------
	// Fallback when APCu is unavailable
	// ---------------------------------------------------------------

	public function testWriteCacheFileFallsBackToFilesystemWhenApcuUnavailable(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = ConfigCache::getCacheName($config);
		$data = "<?php\n// fallback test\n?>";

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// Temporarily disable APCu availability via reflection
		$reflection = new ReflectionClass(APCuConfigCache::class);
		$prop = $reflection->getProperty('apcuAvailable');
		$prop->setValue(null, false);

		try {
			APCuConfigCache::writeCacheFile($config, $cacheName, $data);

			$this->assertFileExists($cacheName, 'When APCu is unavailable, should fall back to filesystem');
			$this->assertSame($data, file_get_contents($cacheName));
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
		$cacheConfigDir = Config::get('core.cache_dir') . DIRECTORY_SEPARATOR . 'config';

		// Start fully cold: clear APCu + filesystem cache, and forget the loaded
		// handlers so the next compile runs loadConfigHandlers()/loadConfigHandlersFile()
		// as a side effect (this is the path the public-API tests never exercise).
		APCuConfigCache::clear();
		\TestingConfigCache::resetHandlers();

		// Trigger a cold compile through the APCu entrypoint so late static binding
		// is APCuConfigCache for the whole chain.
		$result = APCuConfigCache::checkConfig(Config::get('core.config_dir') . '/settings.xml');
		$this->assertStringStartsWith('APCU:', $result, 'settings.xml should be served from APCu, not the filesystem');

		// The bug: config_handlers.xml used to be written to the filesystem here.
		// With LSB preserved it stays in APCu, so no config_handlers cache file
		// should exist on disk.
		$handlerFiles = is_dir($cacheConfigDir) ? glob($cacheConfigDir . DIRECTORY_SEPARATOR . 'config_handlers*') : [];
		$this->assertSame([], $handlerFiles, 'config_handlers must not be written to the filesystem when APCu is enabled');
	}

	// ---------------------------------------------------------------
	// Regression: a nested compile must not clobber the pending context,
	// which would store the outer config under the wrong (null) key and
	// then fall back to a non-existent filesystem path
	// ("require(...): No such file or directory").
	// ---------------------------------------------------------------

	public function testNestedCompileDoesNotClobberContextKey(): void
	{
		$config = Config::get('core.config_dir') . '/tests/importtest.xml';
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
		$result = APCuConfigCache::checkConfig($config, $context);
		$this->assertStringStartsWith(
			'APCU:',
			$result,
			'cold compile with a nested handler load must still store/fetch under the correct context'
		);

		// A second lookup under the same context must be a straight APCu hit.
		$again = APCuConfigCache::checkConfig($config, $context);
		$this->assertStringStartsWith('APCU:', $again);
	}
}
