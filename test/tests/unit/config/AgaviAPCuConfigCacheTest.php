<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;

/**
 * Tests for AgaviAPCuConfigCache — exercises the APCu code path that was
 * previously unreachable in CLI tests because apc.enable_cli was Off.
 *
 * These tests require the APCu extension with apc.enable_cli=1 (set in phpunit.xml).
 */
class AgaviAPCuConfigCacheTest extends AgaviPhpUnitTestCase
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
		AgaviAPCuConfigCache::clear();
	}

	protected function tearDown(): void
	{
		if ($this->apcuAvailable) {
			AgaviAPCuConfigCache::clear();
		}
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// isAvailable / basic sanity
	// ---------------------------------------------------------------

	public function testIsAvailableReturnsTrue(): void
	{
		$this->assertTrue(AgaviAPCuConfigCache::isAvailable());
	}

	// ---------------------------------------------------------------
	// writeCacheFile stores in APCu (not filesystem)
	// ---------------------------------------------------------------

	public function testWriteCacheFileStoresInApcuNotFilesystem(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = AgaviConfigCache::getCacheName($config);
		$data = "<?php\n// test data\n\$GLOBALS['apcu_write_test'] = true;\n?>";

		// Remove any stale filesystem cache
		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		AgaviAPCuConfigCache::writeCacheFile($config, $cacheName, $data);

		// Filesystem cache should NOT be written
		$this->assertFileDoesNotExist($cacheName, 'APCu writeCacheFile should not write to filesystem');

		// APCu should have the data — use the same key derivation as the class
		// (null context since writeCacheFile is called from callHandler without context)
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
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
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = AgaviConfigCache::getCacheName($config);
		$part1 = "<?php\n// part 1\n";
		$part2 = "// part 2\n?>";

		AgaviAPCuConfigCache::writeCacheFile($config, $cacheName, $part1);
		AgaviAPCuConfigCache::writeCacheFile($config, $cacheName, $part2, true);

		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
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
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		// Pre-seed APCu with known PHP content
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$uniqueConst = 'APCU_CHECK_CONFIG_TEST_' . mt_rand();
		$phpContent = "<?php\ndefine('{$uniqueConst}', true);\n?>";
		apcu_store($key, $phpContent);

		// checkConfig should eval the content and return an 'APCU:' marker
		$result = AgaviAPCuConfigCache::checkConfig($config);

		$this->assertStringStartsWith('APCU:', $result, 'checkConfig should return APCU: marker on hit');
		$this->assertTrue(defined($uniqueConst), 'checkConfig should have eval\'d the PHP content from APCu');
	}

	public function testCheckConfigFallsBackToParentOnMiss(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		// Ensure nothing in APCu for this config
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		apcu_delete($key);

		// checkConfig should fall through to parent, which compiles and writes
		// Through late static binding, writeCacheFile will store in APCu
		$result = AgaviAPCuConfigCache::checkConfig($config);

		// Result should be the APCU: marker (because writeCacheFile stored it,
		// but checkConfig's own APCu lookup was before the store happened)
		// Actually — parent::checkConfig compiles and calls writeCacheFile (stored in APCu),
		// then returns the filesystem cache path. So result is a file path.
		$this->assertStringStartsNotWith('APCU:', $result, 'First call should fall through to parent and return cache file path');

		// But now the data IS in APCu, so a second call should hit
		$result2 = AgaviAPCuConfigCache::checkConfig($config);
		$this->assertStringStartsWith('APCU:', $result2, 'Second call should hit APCu and return marker');
	}

	// ---------------------------------------------------------------
	// checkConfig with context — verifies the pendingContext fix
	// ---------------------------------------------------------------

	public function testCheckConfigWithContextStoresUnderCorrectKey(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';
		$context = 'testing';

		// First call: compiles and stores in APCu via writeCacheFile with pendingContext
		$result1 = AgaviAPCuConfigCache::checkConfig($config, $context);

		// The key for this config+context should now exist in APCu
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$keyWithContext = $method->invoke(null, $config, $context);
		$keyWithoutContext = $method->invoke(null, $config, null);

		$this->assertNotFalse(
			apcu_fetch($keyWithContext),
			'Config should be stored under the context-specific APCu key'
		);

		// Second call should be an APCu hit
		$result2 = AgaviAPCuConfigCache::checkConfig($config, $context);
		$this->assertStringStartsWith('APCU:', $result2, 'Second checkConfig with context should hit APCu');
	}

	public function testDifferentContextsUseDifferentKeys(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
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
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		// Pre-seed APCu
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_test_' . mt_rand();
		$phpContent = "<?php\n\$GLOBALS['{$globalKey}'] = 'loaded_from_apcu';\n?>";
		apcu_store($key, $phpContent);

		$this->assertArrayNotHasKey($globalKey, $GLOBALS);

		AgaviAPCuConfigCache::load($config);

		$this->assertArrayHasKey($globalKey, $GLOBALS, 'load() should have eval\'d the PHP from APCu');
		$this->assertSame('loaded_from_apcu', $GLOBALS[$globalKey]);
	}

	public function testLoadOnceDoesNotReExecute(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_once_counter_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		AgaviAPCuConfigCache::load($config, null, true);
		AgaviAPCuConfigCache::load($config, null, true);

		$this->assertSame(1, $GLOBALS[$globalKey], 'load($config, null, true) should only execute once');
	}

	public function testLoadWithOnceFalseReExecutes(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_load_multi_counter_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		AgaviAPCuConfigCache::load($config, null, false);
		AgaviAPCuConfigCache::load($config, null, false);

		$this->assertSame(2, $GLOBALS[$globalKey], 'load($config, null, false) should execute every time');
	}

	// ---------------------------------------------------------------
	// clear()
	// ---------------------------------------------------------------

	public function testClearRemovesApcuEntries(): void
	{
		// Seed some data
		apcu_store('agavi_config_testkey1', 'data1');
		apcu_store('agavi_config_testkey2', 'data2');

		$this->assertNotFalse(apcu_fetch('agavi_config_testkey1'));

		AgaviAPCuConfigCache::clear();

		$this->assertFalse(apcu_fetch('agavi_config_testkey1'), 'clear() should remove agavi_ prefixed keys');
		$this->assertFalse(apcu_fetch('agavi_config_testkey2'), 'clear() should remove agavi_ prefixed keys');
	}

	public function testClearResetsLoadedConfigsTracking(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$globalKey = 'apcu_clear_reload_' . mt_rand();
		$phpContent = "<?php\nif(!isset(\$GLOBALS['{$globalKey}'])) \$GLOBALS['{$globalKey}']=0; \$GLOBALS['{$globalKey}']++;\n?>";
		apcu_store($key, $phpContent);

		AgaviAPCuConfigCache::load($config, null, true);
		$this->assertSame(1, $GLOBALS[$globalKey]);

		// Clear and re-seed
		AgaviAPCuConfigCache::clear();
		apcu_store($key, $phpContent);

		// After clear, load($once=true) should execute again
		AgaviAPCuConfigCache::load($config, null, true);
		$this->assertSame(2, $GLOBALS[$globalKey], 'After clear(), load() should execute again even with $once=true');
	}

	// ---------------------------------------------------------------
	// isWarmedUp / getStatus
	// ---------------------------------------------------------------

	public function testIsWarmedUpReturnsFalseBeforeWarmup(): void
	{
		$this->assertFalse(AgaviAPCuConfigCache::isWarmedUp());
	}

	public function testGetStatusReportsAvailable(): void
	{
		$status = AgaviAPCuConfigCache::getStatus();
		$this->assertTrue($status['available']);
		$this->assertFalse($status['warmed_up']);
		$this->assertArrayHasKey('memory_usage', $status);
	}

	// ---------------------------------------------------------------
	// configure()
	// ---------------------------------------------------------------

	public function testConfigureChangesPrefix(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		AgaviAPCuConfigCache::configure(['config_prefix' => 'custom_pfx_']);

		// After configure, new keys should use the custom prefix
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);

		// Reset key cache since prefix changed
		$keyCacheProp = $reflection->getProperty('keyCache');
		$keyCacheProp->setValue(null, []);

		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);

		$this->assertStringStartsWith('custom_pfx_', $key, 'configure() should change the key prefix');

		// Restore default
		AgaviAPCuConfigCache::configure(['config_prefix' => 'agavi_config_']);
		$keyCacheProp->setValue(null, []);
	}

	// ---------------------------------------------------------------
	// Integration: full round-trip through checkConfig (compile → store → hit)
	// ---------------------------------------------------------------

	public function testFullRoundTripCompileStoreHit(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';

		// Ensure nothing cached
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$method = $reflection->getMethod('getConfigKey');
		$key = $method->invoke(null, $config, null);
		apcu_delete($key);

		// Delete filesystem cache too
		$cacheName = AgaviConfigCache::getCacheName($config);
		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// First call: compiles config, writeCacheFile stores in APCu
		$result1 = AgaviAPCuConfigCache::checkConfig($config);
		// Parent returns a file path (since it compiled and cached on filesystem)
		// But our writeCacheFile override stored in APCu instead

		// Verify data is now in APCu
		$stored = apcu_fetch($key);
		$this->assertNotFalse($stored, 'After first checkConfig, compiled data should be in APCu');
		$this->assertStringContainsString('<?php', $stored, 'Stored data should be valid PHP');

		// Second call: should hit APCu directly
		$result2 = AgaviAPCuConfigCache::checkConfig($config);
		$this->assertStringStartsWith('APCU:', $result2, 'Second checkConfig should return APCU: marker');
	}

	// ---------------------------------------------------------------
	// Fallback when APCu is unavailable
	// ---------------------------------------------------------------

	public function testWriteCacheFileFallsBackToFilesystemWhenApcuUnavailable(): void
	{
		$config = AgaviConfig::get('core.config_dir') . '/tests/importtest.xml';
		$cacheName = AgaviConfigCache::getCacheName($config);
		$data = "<?php\n// fallback test\n?>";

		if (file_exists($cacheName)) {
			unlink($cacheName);
		}

		// Temporarily disable APCu availability via reflection
		$reflection = new ReflectionClass(AgaviAPCuConfigCache::class);
		$prop = $reflection->getProperty('apcuAvailable');
		$prop->setValue(null, false);

		try {
			AgaviAPCuConfigCache::writeCacheFile($config, $cacheName, $data);

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
}
