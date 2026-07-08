<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;

/**
 * Proves ConfigCache::describeConfigCandidates() reports the full sibling
 * candidate list, not just the winner resolveConfigFormat() would pick --
 * the diagnostic shadowed-config detection needs (VSCODE_EXTENSION_INTEGRATION.md
 * config validator work item 5).
 */
class ConfigCacheShadowedCandidatesTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'ccsc_');
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

	private function touch(string $name): string
	{
		$path = $this->dir . '/' . $name;
		file_put_contents($path, '');
		return $path;
	}

	public function testAutodetectReportsPhpAsWinnerAndOthersAsLowerPrecedence(): void
	{
		$this->touch('databases.xml');
		$this->touch('databases.yaml');
		$this->touch('databases.php');

		$result = ConfigCache::describeConfigCandidates($this->dir . '/databases.xml');

		$this->assertSame($this->dir . '/databases.php', $result['winner']);
		$this->assertSame([
			['path' => $this->dir . '/databases.yaml', 'reason' => 'lower_precedence'],
			['path' => $this->dir . '/databases.xml', 'reason' => 'lower_precedence'],
		], $result['shadowed']);
	}

	public function testExplicitConfigFormatReportsNonMatchingSiblingAsExcluded(): void
	{
		$this->touch('databases.php');
		$this->touch('databases.xml');
		Config::set('core.config_format', 'xml', true);

		$result = ConfigCache::describeConfigCandidates($this->dir . '/databases.xml');

		$this->assertSame($this->dir . '/databases.xml', $result['winner']);
		$this->assertSame([
			['path' => $this->dir . '/databases.php', 'reason' => 'excluded_by_config_format'],
		], $result['shadowed']);
	}

	public function testSingleCandidateHasNoShadowedEntries(): void
	{
		$this->touch('databases.xml');

		$result = ConfigCache::describeConfigCandidates($this->dir . '/databases.xml');

		$this->assertSame($this->dir . '/databases.xml', $result['winner']);
		$this->assertSame([], $result['shadowed']);
	}

	public function testNoCandidateAtAllReportsNullWinnerAndNoShadowed(): void
	{
		$result = ConfigCache::describeConfigCandidates($this->dir . '/databases.xml');

		$this->assertNull($result['winner']);
		$this->assertSame([], $result['shadowed']);
	}

	public function testNonConfigExtensionReflectsPlainExistenceWithNoShadowConcept(): void
	{
		$path = $this->touch('some_other_file.txt');

		$result = ConfigCache::describeConfigCandidates($path);

		$this->assertSame($path, $result['winner']);
		$this->assertSame([], $result['shadowed']);
	}

	public function testNonConfigExtensionThatDoesNotExistReportsNullWinner(): void
	{
		$result = ConfigCache::describeConfigCandidates($this->dir . '/missing.txt');

		$this->assertNull($result['winner']);
		$this->assertSame([], $result['shadowed']);
	}

	public function testShadowedConfigDiagnosticsProducesOneWarningPerShadowedCandidate(): void
	{
		$this->touch('databases.xml');
		$this->touch('databases.yaml');
		$this->touch('databases.php');

		$diagnostics = ConfigCache::describeShadowedConfigDiagnostics($this->dir . '/databases.xml');

		$this->assertCount(2, $diagnostics);
		foreach ($diagnostics as $diagnostic) {
			$this->assertSame(\Quiote\Support\Compiler\Diagnostic::CODE_SHADOWED_CONFIG, $diagnostic->code);
			$this->assertSame(\Quiote\Support\Compiler\Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
			$this->assertStringContainsString('databases.php', $diagnostic->message);
		}
		$this->assertSame($this->dir . '/databases.yaml', $diagnostics[0]->where);
		$this->assertSame($this->dir . '/databases.xml', $diagnostics[1]->where);
	}

	public function testShadowedConfigDiagnosticsIsEmptyWhenNothingIsShadowed(): void
	{
		$this->touch('databases.xml');

		$this->assertSame([], ConfigCache::describeShadowedConfigDiagnostics($this->dir . '/databases.xml'));
	}

	public function testShadowedConfigDiagnosticsExplainsConfigFormatExclusion(): void
	{
		$this->touch('databases.php');
		$this->touch('databases.xml');
		Config::set('core.config_format', 'xml', true);

		$diagnostics = ConfigCache::describeShadowedConfigDiagnostics($this->dir . '/databases.xml');

		$this->assertCount(1, $diagnostics);
		$this->assertStringContainsString('core.config_format', $diagnostics[0]->message);
		$this->assertSame($this->dir . '/databases.php', $diagnostics[0]->where);
	}
}
?>
