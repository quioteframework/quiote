<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\FormatAwareConfigCache;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\SettingConfigHandler;
use Quiote\Exception\UnreadableException;

/**
 * Extension-agnostic discovery: proves FormatAwareConfigCache
 * resolves whichever of .php/.yaml/.xml exists for a given base path, and
 * that the resulting compiled cache file is a real, includable artifact
 * indistinguishable from what ConfigCache::checkConfig() would produce for
 * an equivalent XML file.
 */
class FormatAwareConfigCacheTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'facc_');
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

	private function newRegistry(SettingConfigHandler $handler): FormatDriverRegistry
	{
		return FormatDriverRegistry::forHandler($handler, [Config::get('core.quiote_dir') . '/Config/xsl/settings.xsl']);
	}

	public function testResolvesAndCompilesAPhpSettingsFileByBaseNameAlone()
	{
		file_put_contents($this->dir . '/settings.php', "<?php\nreturn ['core.app_name' => 'Demo'];\n");

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$cacheFile = FormatAwareConfigCache::checkConfig($this->dir . '/settings', $handler, $this->newRegistry($handler), 'test');

		$this->assertFileExists($cacheFile);
		$compiled = require $cacheFile;
		$this->assertSame('Demo', Config::get('core.app_name'));
	}

	public function testPrefersPhpOverYamlAndXmlWhenMultipleExist()
	{
		file_put_contents($this->dir . '/settings.php', "<?php\nreturn ['core.app_name' => 'FromPhp'];\n");
		file_put_contents($this->dir . '/settings.yaml', "core.app_name: FromYaml\n");
		file_put_contents($this->dir . '/settings.xml', '<x/>');

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$cacheFile = FormatAwareConfigCache::checkConfig($this->dir . '/settings', $handler, $this->newRegistry($handler), 'test');

		require $cacheFile;
		$this->assertSame('FromPhp', Config::get('core.app_name'));
	}

	public function testFallsBackToYamlWhenPhpAbsent()
	{
		file_put_contents($this->dir . '/settings.yaml', "core.app_name: FromYaml\n");

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$cacheFile = FormatAwareConfigCache::checkConfig($this->dir . '/settings', $handler, $this->newRegistry($handler), 'test');

		require $cacheFile;
		$this->assertSame('FromYaml', Config::get('core.app_name'));
	}

	public function testThrowsWhenNoCandidateFileExists()
	{
		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);

		$this->expectException(UnreadableException::class);
		FormatAwareConfigCache::checkConfig($this->dir . '/nonexistent', $handler, $this->newRegistry($handler), 'test');
	}

	public function testDoesNotRecompileWhenSourceIsUnchanged()
	{
		file_put_contents($this->dir . '/settings.php', "<?php\nreturn ['core.app_name' => 'Demo'];\n");

		$handler = new SettingConfigHandler();
		$handler->initialize(null, []);
		$registry = $this->newRegistry($handler);

		$cacheFile1 = FormatAwareConfigCache::checkConfig($this->dir . '/settings', $handler, $registry, 'test');
		$mtime1 = filemtime($cacheFile1);
		clearstatcache(true, $cacheFile1);

		// Second call: source file untouched, so the cache file must not be rewritten.
		usleep(1100000); // ensure a rewrite (if it happened) would produce a detectably different mtime
		$cacheFile2 = FormatAwareConfigCache::checkConfig($this->dir . '/settings', $handler, $registry, 'test');

		$this->assertSame($cacheFile1, $cacheFile2);
		clearstatcache(true, $cacheFile2);
		$this->assertSame($mtime1, filemtime($cacheFile2));
	}
}
?>
