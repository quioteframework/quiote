<?php

use Quiote\Config\Config;
use Quiote\Config\SettingConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Locks in the compiled output of SettingConfigHandler against the real
 * tests/sandbox/app/Config/settings.xml fixture (system_actions, plain
 * settings, an environment override, and exception templates -- both with
 * and without a context attribute). SettingConfigHandler::execute() is now
 * a two-line adapter over toCanonicalArray() + executeArray() (see
 * docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2); this is the parity
 * guarantee that refactor promised, generated from the pre-refactor
 * handler's actual output.
 *
 * The "// Date: ..." and "// Compiled from: ..." header lines are
 * normalized before comparison (see ValidatorConfigHandlerGoldenTest for
 * the same convention); everything else must match byte-for-byte.
 */
class SettingConfigHandlerGoldenTest extends ConfigHandlerTestBase
{
	private function compile(string $environment): string
	{
		$h = new SettingConfigHandler();
		$h->initialize(null, []);
		$document = $this->parseConfiguration(
			Config::get('core.config_dir') . '/settings.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/settings.xsl',
			$environment
		);
		$code = $h->execute($document);
		$code = preg_replace('/^\/\/ Date: .*$/m', '// Date: <normalized>', $code);
		return preg_replace('/^\/\/ Compiled from: .*$/m', '// Compiled from: <normalized>', $code);
	}

	private function assertMatchesGolden(string $goldenName, string $environment): void
	{
		$goldenFile = __DIR__ . '/golden/' . $goldenName . '.php.golden';
		$this->assertFileExists($goldenFile, 'Golden fixture missing: ' . $goldenFile);
		$expected = file_get_contents($goldenFile);
		$actual = $this->compile($environment);
		$this->assertSame($expected, $actual, 'Compiled output for "' . $goldenName . '" drifted from the golden fixture.');
	}

	public function testDefaultTestingEnvironmentFixture()
	{
		$this->assertMatchesGolden('settings_default', 'testing');
	}

	public function testProductionEnvironmentFixture()
	{
		$this->assertMatchesGolden('settings_prod', 'production');
	}
}
?>
