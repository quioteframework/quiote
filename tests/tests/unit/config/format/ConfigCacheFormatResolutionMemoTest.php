<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;

/**
 * Covers the per-worker memoization of ConfigCache::resolveConfigFormat():
 * the physical file a logical config name resolves to is cached (keyed by
 * filename + core.config_format) so the repeated is_file() format probe is
 * removed from the steady-state per-request path, while clear() still drops
 * the memo so on-disk changes (debug mode, between test runs) are picked up.
 */
class ConfigCacheFormatResolutionMemoTest extends PhpUnitTestCase
{
	private string $dir;

	#[\Override]
	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'ccfrm_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->resetMemo();
	}

	#[\Override]
	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('core.config_format');
		$this->resetMemo();
		parent::tearDown();
	}

	private function resetMemo(): void
	{
		$p = new ReflectionProperty(ConfigCache::class, 'formatResolveMemo');
		$p->setValue(null, []);
	}

	private function resolve(string $filename): string
	{
		$method = new ReflectionMethod(ConfigCache::class, 'resolveConfigFormat');
		return (string) $method->invoke(null, $filename);
	}

	private function touch(string $name): string
	{
		$path = $this->dir . '/' . $name;
		file_put_contents($path, '');
		return $path;
	}

	public function testRepeatedResolutionReturnsStableResult(): void
	{
		$this->touch('databases.xml');
		$xml = $this->dir . '/databases.xml';

		$this->assertSame($xml, $this->resolve($xml));
		$this->assertSame($xml, $this->resolve($xml), 'A second resolution must return the same path.');
	}

	public function testResolutionIsMemoizedUntilCleared(): void
	{
		$xml = $this->touch('databases.xml');

		// First resolution populates the memo with the only candidate: the .xml.
		$this->assertSame($xml, $this->resolve($xml));

		// A higher-priority sibling appears on disk. Without clear(), the memo
		// deliberately still returns the previously resolved path -- config files
		// do not materialize mid-worker-lifetime in production.
		$php = $this->touch('databases.php');
		$this->assertSame($xml, $this->resolve($xml));

		// clear() drops the memo, so the next resolution reflects on-disk state
		// (PHP wins over XML in autodetect order).
		ConfigCache::clear();
		$this->assertSame($php, $this->resolve($xml));
	}

	public function testConfigFormatIsPartOfTheMemoKey(): void
	{
		$xml = $this->touch('databases.xml');
		$this->touch('databases.php');
		$php = $this->dir . '/databases.php';

		// Autodetect (no override): PHP wins. Populates memo under the "no format" key.
		$this->assertSame($php, $this->resolve($xml));

		// Forcing the XML format must resolve afresh (distinct memo key), not
		// return the stale autodetected PHP path.
		Config::set('core.config_format', 'xml');
		$this->assertSame($xml, $this->resolve($xml));
	}
}
