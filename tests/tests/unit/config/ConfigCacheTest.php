<?php

require_once __DIR__ . '/../../../../tests/lib/config/TestingConfigCache.class.php';

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\UnreadableException;
use Quiote\Util\Toolkit;

class ConfigCacheTest extends PhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGenerateCacheName')]
	public function testGenerateCacheName(string $configname, ?string $context): void
	{
		$cachename = ConfigCache::getCacheName($configname, $context);

		// Calculate expected value here where Quiote is bootstrapped and core.environment is available
		$environment = Config::getNullableString('core.environment');

		// This mirrors the logic in ConfigCache::getCacheName()
		$expectedFilename = sprintf(
			'%1$s_%2$s.php',
			preg_replace(
				'/[^\w_.-]/i', 
				'_', 
				sprintf(
					'%1$s_%2$s_%3$s', 
					basename((string) $configname), 
					$environment, 
					$context
				)
			),
			sha1(
				sprintf(
					'%1$s_%2$s_%3$s_%4$s',
					$configname,
					$environment,
					$context,
					ConfigCache::frameworkFingerprint()
				)
			)
		);

		$expected = Config::getString('core.cache_dir').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.$expectedFilename;

		$this->assertEquals($expected, $cachename);
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function dataGenerateCacheName(): array
	{
		// Only provide input data, not expected values (since core.environment isn't available yet)
		return [
			'slashes_null' => [
				'foo/bar/hash#bang.xml',
				null,
			],
			'<contextname>' => [
				'foo/bar/hash#bang.xml',
				'<contextname>',
			],
		];
	}


	public function testWriteCacheFile(): void
	{
		$expected = 'This is a config cache test.';
		$config = Config::getString('core.config_dir').DIRECTORY_SEPARATOR.'foo.xml';
		$cacheName = ConfigCache::getCacheName($config);
		if(file_exists($cacheName)) {
			unlink($cacheName);
		}
		ConfigCache::writeCacheFile($config, $cacheName, $expected);
		$this->assertFileExists($cacheName);
		$content = file_get_contents($cacheName);
		$this->assertEquals($expected, $content);

		$append = "\nAnd a second line appended.";
		ConfigCache::writeCacheFile($config, $cacheName, $append, true);
		$content = file_get_contents($cacheName);
		$this->assertEquals($expected.$append, $content);
	}

	/**
	 * The config cache holds PHP that this process include()s and eval()s, and
	 * *directory* permissions -- not file permissions -- decide who may replace
	 * an entry: on a world-writable directory without the sticky bit, any local
	 * user can unlink a 0600 cache file and drop their own PHP in its place. A
	 * `chmod -R 777` deployment (depressingly common) would otherwise propagate
	 * straight into the mode of the directory created here.
	 */
	public function testCreatedCacheDirectoryIsNeverWorldWritable(): void
	{
		$base = sys_get_temp_dir() . '/quiote-cachedir-perm-test-' . getmypid();
		$this->removeRecursively($base);
		mkdir($base, 0777, true);
		chmod($base, 0777);

		$previous = Config::getString('core.cache_dir');
		Config::set('core.cache_dir', $base);

		try {
			$config = Config::getString('core.config_dir') . DIRECTORY_SEPARATOR . 'perm.xml';
			ConfigCache::writeCacheFile($config, ConfigCache::getCacheName($config), '<?php // test');

			$configDir = $base . DIRECTORY_SEPARATOR . 'config';
			$this->assertDirectoryExists($configDir);
			$perms = fileperms($configDir) & 0777;
			$this->assertSame(0, $perms & 0002, sprintf('mode %04o must not be world-writable', $perms));
			$this->assertNotSame(0, $perms & 0700, sprintf('mode %04o must stay usable by the owner', $perms));
		} finally {
			Config::set('core.cache_dir', $previous);
			$this->removeRecursively($base);
		}
	}

	private function removeRecursively(string $path): void
	{
		if (is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			if (!$item instanceof \SplFileInfo) {
				continue;
			}
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($path);
	}

	public function testload(): void
	{
		$this->assertArrayNotHasKey('ConfigCacheImportTest_included', get_defined_constants());
		ConfigCache::load(Config::getString('core.config_dir') . '/tests/importtest.xml');
		$this->assertArrayHasKey('ConfigCacheImportTest_included', get_defined_constants());

		$GLOBALS["ConfigCacheImportTestOnce_included"] = false;
		ConfigCache::load(Config::getString('core.config_dir') . '/tests/importtest_once.xml');
		$this->assertTrue( $GLOBALS["ConfigCacheImportTestOnce_included"] );

		$GLOBALS["ConfigCacheImportTestOnce_included"] = false;
		ConfigCache::load(Config::getString('core.config_dir') . '/tests/importtest_once.xml');
		$this->assertFalse( $GLOBALS["ConfigCacheImportTestOnce_included"] );
	}


	public function testClear(): void
	{
		$cacheDir = Config::getString('core.cache_dir').DIRECTORY_SEPARATOR.'config';
		ConfigCache::clear();

		// After clearing, the directory may not exist or it may exist but be empty
		if (is_dir($cacheDir)) {
			$directory = new DirectoryIterator($cacheDir);
			foreach($directory as $item) {
				if($directory->current()->isDot()) {
					continue;
				}
				$this->fail(sprintf('Failed asserting that the cache dir "%1$s" is empty, it contains at least "%2$s"', $cacheDir, $item->getFileName()));
			}
		}
		// If directory doesn't exist, that's also a valid state after clearing;
		// reaching this point without the fail() above firing is the assertion.
		$this->addToAssertionCount(1);
	}

	/**
	 * this does not seem to work in isolation
	 */
	public function testAddNonexistantConfigHandlersFile(): void
	{
		$this->expectException(UnreadableException::class);
		ConfigCache::addConfigHandlersFile('does/not/exist');
	}

	public function testAddConfigHandlersFile(): void
	{
		$config = Config::getString('core.module_dir').'/Default/Config/config_handlers.xml';
		// Other tests (or module loading) may already have registered this file in
		// the process-wide handler-file registry; forget it so addConfigHandlersFile()
		// is exercised from a known-clean precondition regardless of execution order.
		TestingConfigCache::forgetHandlerFile($config);
		TestingConfigCache::addConfigHandlersFile($config);
		$this->assertTrue(TestingConfigCache::handlersDirty(), 'Failed asserting that the handlersDirty flag is set after adding a config handlers file.');
		$handlerFiles = TestingConfigCache::getHandlerFiles();
		$this->assertFalse($handlerFiles[$config], sprintf('Failed asserting that the config file "%1$s" has not been loaded.', $config));
	}


	public function testSetupHandlers(): void
	{	
		// this is not possible to test with the quiote unit tests as this needs
		// a really clean env with no framework bootstrapped. Need to think about that.
		//$this->markTestIncomplete();
		TestingConfigCache::resetHandlers();
		$this->assertEquals(null, TestingConfigCache::getHandlers());
		TestingConfigCache::setUpHandlers();
		$handlers = TestingConfigCache::getHandlers();
		$this->assertNotEquals(null, $handlers);
	}

	public function testGetHandlerInfo(): void
	{
		$handlerInfo = TestingConfigCache::getHandlerInfo('notregistered');
		$this->assertEquals(null, $handlerInfo);

		$expected = [
			'class' => 'ReturnArrayConfigHandler',
			'parameters' => [],
			'transformations' => [
				'single' => ['confighandler-testing.xsl',],
				'compilation' => [],
			],
			'validations' => [
				'single' => [
					'transformations_before' => [
						'relax_ng' => [],
						'schematron' => [],
						'xml_schema' => [],
					],
					'transformations_after' => [
						'relax_ng' => ['confighandler-testing.rng'],
						'schematron' => [],
						'xml_schema' => [],
					],
				],
				'compilation' => [
					'transformations_before' => [
						'relax_ng' => [],
						'schematron' => [],
						'xml_schema' => [],
					],
					'transformations_after' => [
						'relax_ng' => [],
						'schematron' => [],
						'xml_schema' => [],
					],
				],
			],
		];
		$handlerInfo = TestingConfigCache::getHandlerInfo('confighandler-testing');
		$this->assertEquals($expected, $handlerInfo);
	}

	public function testTicket931(): void
	{
		$config = 'project/foo.xml';
		$context = 'with/slash';
		$cachename = ConfigCache::getCacheName($config, $context);

		$expected = Config::getString('core.cache_dir').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR;
		$expected .= 'foo.xml';
		$expected .= '_'.preg_replace('/[^\w_-]/i', '_', (string) Config::getNullableString('core.environment'));
		$expected .= '_'.preg_replace('/[^\w_-]/i', '_', $context).'_';
		$expected .= sha1(
			$config.'_'.Config::getNullableString('core.environment').'_'.$context
			.'_'.ConfigCache::frameworkFingerprint()
		).'.php';

		$this->assertEquals($expected, $cachename);
	}

	public function testTicket932(): void
	{
		$config1 = 'project/foo.xml';
		$config2 = 'project_foo.xml';

		$this->assertNotEquals(ConfigCache::getCacheName($config1), ConfigCache::getCacheName($config2));
	}

    // Removed obsolete autoload.xml and pre-bootstrap handler tests (PSR-4 migration)

	// ---------------------------------------------------------------
	// isModified() / core.config_check_freshness -- item 7 of PERF_PLAN.md:
	// under classic PHP-FPM, isModified()'s per-worker memoization resets
	// every request, so every config resolution pays a filemtime() stat pair.
	// core.config_check_freshness=false ("trust the cache" prod mode) skips
	// that stat pair entirely once a cache file exists.
	// ---------------------------------------------------------------

	/** @var list<string> */
	private array $isModifiedFilesToDelete = [];

	#[\Override]
	protected function tearDown(): void
	{
		Config::remove('core.config_check_freshness');
		foreach ($this->isModifiedFilesToDelete as $file) {
			@unlink($file);
		}
		$this->isModifiedFilesToDelete = [];
		parent::tearDown();
	}

	/**
	 * @return array{0: string, 1: string} [$sourceFile, $cacheFile], with the
	 * source file's mtime set strictly newer than the cache file's (the
	 * "cache is stale" precondition every test below starts from).
	 */
	private function makeStaleSourceAndCachePair(): array
	{
		$dir = sys_get_temp_dir();
		$suffix = bin2hex(random_bytes(8));
		$source = $dir . '/config_cache_freshness_source_' . $suffix . '.xml';
		$cache = $dir . '/config_cache_freshness_cache_' . $suffix . '.php';
		file_put_contents($cache, '<?php // stale cache');
		file_put_contents($source, '<xml/>');
		touch($cache, time() - 100);
		touch($source, time());
		$this->isModifiedFilesToDelete[] = $source;
		$this->isModifiedFilesToDelete[] = $cache;
		return [$source, $cache];
	}

	public function testIsModifiedDetectsStaleCacheByDefault(): void
	{
		[$source, $cache] = $this->makeStaleSourceAndCachePair();

		$this->assertTrue(ConfigCache::isModified($source, $cache));
	}

	public function testIsModifiedSkipsStatCallsWhenFreshnessCheckDisabled(): void
	{
		[$source, $cache] = $this->makeStaleSourceAndCachePair();
		Config::set('core.config_check_freshness', false);

		// The source is genuinely newer than the cache (see precondition
		// helper), but with freshness checking disabled the mtime comparison
		// must never run -- an existing cache file is trusted outright.
		$this->assertFalse(ConfigCache::isModified($source, $cache));
	}

	public function testIsModifiedStillReportsMissingCacheWhenFreshnessCheckDisabled(): void
	{
		[$source, $cache] = $this->makeStaleSourceAndCachePair();
		unlink($cache);
		Config::set('core.config_check_freshness', false);

		// "Trust the cache" only applies once a cache file exists; a
		// genuinely missing cache must still compile.
		$this->assertTrue(ConfigCache::isModified($source, $cache));
	}

	// ---------------------------------------------------------------
	// Framework fingerprint
	// ---------------------------------------------------------------

	public function testTheFingerprintIsStableWithinAProcess(): void
	{
		$this->assertSame(ConfigCache::frameworkFingerprint(), ConfigCache::frameworkFingerprint());
	}

	public function testTheFingerprintIsAShortHexToken(): void
	{
		$this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', ConfigCache::frameworkFingerprint());
	}

	/**
	 * The whole point. Freshness is decided by comparing the source config's mtime against the cache
	 * file's, and upgrading the framework changes neither -- so without the fingerprint in the key, a
	 * cache compiled by an older framework is reused indefinitely, and the failure lands at boot
	 * reporting whatever the stale contents break first.
	 */
	public function testADifferentFrameworkVersionYieldsADifferentCacheName(): void
	{
		$config = 'project/foo.xml';
		$before = ConfigCache::getCacheName($config, 'web');
		$previousVersion = Config::getString('quiote.version', 'unknown');

		Config::set('quiote.version', $previousVersion . '-nextrelease', true);
		ConfigCache::resetFrameworkFingerprint();
		try {
			$after = ConfigCache::getCacheName($config, 'web');
		} finally {
			Config::set('quiote.version', $previousVersion, true);
			ConfigCache::resetFrameworkFingerprint();
		}

		$this->assertNotSame($before, $after);
		$this->assertSame($before, ConfigCache::getCacheName($config, 'web'), 'and back again');
	}

	/**
	 * The knob a build pipeline can turn to force a rebuild without touching a config file.
	 */
	public function testTheConfigCacheFingerprintSettingChangesTheCacheName(): void
	{
		$config = 'project/foo.xml';
		$before = ConfigCache::getCacheName($config, 'web');

		Config::set('core.config_cache_fingerprint', 'build-1234', true);
		ConfigCache::resetFrameworkFingerprint();
		try {
			$after = ConfigCache::getCacheName($config, 'web');
		} finally {
			Config::remove('core.config_cache_fingerprint');
			ConfigCache::resetFrameworkFingerprint();
		}

		$this->assertNotSame($before, $after);
	}

	/**
	 * Two configs must still be told apart, and the same config must still resolve to one name --
	 * the fingerprint is a component of the key, not a replacement for it.
	 */
	public function testTheFingerprintDoesNotCollapseDistinctConfigs(): void
	{
		$this->assertNotSame(
			ConfigCache::getCacheName('project/foo.xml', 'web'),
			ConfigCache::getCacheName('project/bar.xml', 'web'),
		);
		$this->assertNotSame(
			ConfigCache::getCacheName('project/foo.xml', 'web'),
			ConfigCache::getCacheName('project/foo.xml', 'console'),
		);
		$this->assertSame(
			ConfigCache::getCacheName('project/foo.xml', 'web'),
			ConfigCache::getCacheName('project/foo.xml', 'web'),
		);
	}
}
