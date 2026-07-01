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
	public function testGenerateCacheName($configname, $context)
	{
		$cachename = ConfigCache::getCacheName($configname, $context);

		// Calculate expected value here where Quiote is bootstrapped and core.environment is available
		$environment = Config::get('core.environment');

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
					'%1$s_%2$s_%3$s',
					$configname,
					$environment,
					$context
				)
			)
		);

		$expected = Config::get('core.cache_dir').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.$expectedFilename;

		$this->assertEquals($expected, $cachename);
	}

	public static function dataGenerateCacheName()
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


	public function testWriteCacheFile()
	{
		$expected = 'This is a config cache test.';
		$config = Config::get('core.config_dir').DIRECTORY_SEPARATOR.'foo.xml';
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

	public function testload()
	{
		$this->assertFalse( defined('ConfigCacheImportTest_included') );
		ConfigCache::load(Config::get('core.config_dir') . '/tests/importtest.xml');
		$this->assertTrue( defined('ConfigCacheImportTest_included') );

		$GLOBALS["ConfigCacheImportTestOnce_included"] = false;
		ConfigCache::load(Config::get('core.config_dir') . '/tests/importtest_once.xml', true);
		$this->assertTrue( $GLOBALS["ConfigCacheImportTestOnce_included"] );

		$GLOBALS["ConfigCacheImportTestOnce_included"] = false;
		ConfigCache::load(Config::get('core.config_dir') . '/tests/importtest_once.xml', true);
		$this->assertFalse( $GLOBALS["ConfigCacheImportTestOnce_included"] );
	}


	public function testClear()
	{
		$cacheDir = Config::get('core.cache_dir').DIRECTORY_SEPARATOR.'config';
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
		// If directory doesn't exist, that's also a valid state after clearing
		$this->assertTrue(true); // Test passes if we get here without failure
	}

	/**
	 * this does not seem to work in isolation
	 */
	public function testAddNonexistantConfigHandlersFile()
	{
		$this->expectException(UnreadableException::class);
		ConfigCache::addConfigHandlersFile('does/not/exist');
	}

	public function testAddConfigHandlersFile()
	{
		$config = Config::get('core.module_dir').'/Default/Config/config_handlers.xml';
		// Other tests (or module loading) may already have registered this file in
		// the process-wide handler-file registry; forget it so addConfigHandlersFile()
		// is exercised from a known-clean precondition regardless of execution order.
		TestingConfigCache::forgetHandlerFile($config);
		TestingConfigCache::addConfigHandlersFile($config);
		$this->assertTrue(TestingConfigCache::handlersDirty(), 'Failed asserting that the handlersDirty flag is set after adding a config handlers file.');
		$handlerFiles = TestingConfigCache::getHandlerFiles();
		$this->assertFalse($handlerFiles[$config], sprintf('Failed asserting that the config file "%1$s" has not been loaded.', $config));
	}


	public function testSetupHandlers()
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

	public function testGetHandlerInfo()
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

	public function testTicket931()
	{
		$config = 'project/foo.xml';
		$context = 'with/slash';
		$cachename = ConfigCache::getCacheName($config, $context);

		$expected = Config::get('core.cache_dir').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR;
		$expected .= 'foo.xml';
		$expected .= '_'.preg_replace('/[^\w_-]/i', '_', (string) Config::get('core.environment'));
		$expected .= '_'.preg_replace('/[^\w_-]/i', '_', $context).'_';
		$expected .= sha1($config.'_'.Config::get('core.environment').'_'.$context).'.php'; 

		$this->assertEquals($expected, $cachename);
	}

	public function testTicket932()
	{
		$config1 = 'project/foo.xml';
		$config2 = 'project_foo.xml';

		$this->assertNotEquals(ConfigCache::getCacheName($config1), ConfigCache::getCacheName($config2));
	}

    // Removed obsolete autoload.xml and pre-bootstrap handler tests (PSR-4 migration)
}