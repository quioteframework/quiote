<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\UnreadableException;

/**
 * Proves the `core.config_format` setting + autodetect wiring into
 * ConfigCache's real dispatch (docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 3,
 * now wired into production, not just the opt-in FormatAwareConfigCache):
 * given siblings databases.xml / databases.php / databases.yaml, the
 * physical file actually read is whichever `core.config_format` names, or
 * (unset) the highest-priority one that exists: PHP > YAML > XML.
 */
class ConfigCacheFormatResolutionTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'ccfr_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('core.config_format');
		parent::tearDown();
	}

	private function resolve(string $filename): string
	{
		$method = new ReflectionMethod(ConfigCache::class, 'resolveConfigFormat');
		return $method->invoke(null, $filename);
	}

	private function touch(string $name): string
	{
		$path = $this->dir . '/' . $name;
		file_put_contents($path, '');
		return $path;
	}

	public function testAutodetectPrefersPhpOverYamlAndXmlWhenNoOverrideIsSet()
	{
		$this->touch('databases.xml');
		$this->touch('databases.yaml');
		$this->touch('databases.php');

		$this->assertSame($this->dir . '/databases.php', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testAutodetectFallsBackToYamlWhenNoPhpFileExists()
	{
		$this->touch('databases.xml');
		$this->touch('databases.yaml');

		$this->assertSame($this->dir . '/databases.yaml', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testAutodetectFallsBackToXmlWhenItIsTheOnlyCandidate()
	{
		$this->touch('databases.xml');

		$this->assertSame($this->dir . '/databases.xml', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testReturnsInputUnchangedWhenNoCandidateExistsAtAll()
	{
		// Neither databases.xml nor any sibling exists; the original
		// filename is returned so the caller's normal is_readable() check
		// produces its usual UnreadableException, not a confusing one from
		// deep inside format resolution.
		$missing = $this->dir . '/databases.xml';
		$this->assertSame($missing, $this->resolve($missing));
	}

	public function testExplicitConfigFormatXmlWinsEvenWhenPhpExists()
	{
		$this->touch('databases.xml');
		$this->touch('databases.php');
		Config::set('core.config_format', 'xml', true);

		$this->assertSame($this->dir . '/databases.xml', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testExplicitConfigFormatPhpWinsEvenWhenXmlExists()
	{
		$this->touch('databases.xml');
		$this->touch('databases.php');
		Config::set('core.config_format', 'php', true);

		$this->assertSame($this->dir . '/databases.php', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testExplicitConfigFormatYamlAcceptsEitherYamlOrYmlExtension()
	{
		$this->touch('databases.xml');
		$this->touch('databases.yml');
		Config::set('core.config_format', 'yaml', true);

		$this->assertSame($this->dir . '/databases.yml', $this->resolve($this->dir . '/databases.xml'));
	}

	public function testExplicitConfigFormatThrowsWhenItsFileDoesNotExist()
	{
		$this->touch('databases.xml');
		Config::set('core.config_format', 'php', true);

		$this->expectException(UnreadableException::class);
		$this->resolve($this->dir . '/databases.xml');
	}

	public function testUnknownConfigFormatValueThrows()
	{
		$this->touch('databases.xml');
		Config::set('core.config_format', 'toml', true);

		$this->expectException(ConfigurationException::class);
		$this->resolve($this->dir . '/databases.xml');
	}

	public function testNonConfigExtensionIsReturnedUnchanged()
	{
		// A filename with no recognized config extension at all -- nothing
		// to resolve, format detection must be a complete no-op.
		$path = $this->dir . '/some_other_file.txt';
		$this->assertSame($path, $this->resolve($path));
	}

	public function testEndToEndAutodetectPicksPhpSiblingOverRealSettingsXml()
	{
		// Prove this against the ACTUAL settings.xml registered pattern
		// (%core.config_dir%/settings.xml), not just resolveConfigFormat()
		// in isolation -- a sibling settings.php dropped next to the real
		// settings.xml must be what compiles, with zero other configuration.
		$configDir = Config::get('core.config_dir');
		$phpSibling = $configDir . '/settings.php';
		$this->assertFileDoesNotExist($phpSibling, 'This test cannot run if a real settings.php already exists.');

		$originalAppName = Config::get('core.app_name');
		file_put_contents($phpSibling, "<?php\nreturn ['core.app_name' => 'FromSiblingPhp'];\n");
		try {
			$cacheFile = ConfigCache::checkConfig($configDir . '/settings.xml', null);
			require $cacheFile;
			$this->assertSame('FromSiblingPhp', Config::get('core.app_name'));
		} finally {
			unlink($phpSibling);
			Config::set('core.app_name', $originalAppName, true);
		}
	}
}
?>
