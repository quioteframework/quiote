<?php

use Quiote\Config\Config;
use Quiote\Config\FactoryConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Locks in FactoryConfigHandler's compiled output against the real
 * tests/sandbox/app/Config/factories.xml fixture. execute() is now a
 * two-line adapter over toCanonicalArray() + executeArray() (see
 * docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2, second handler after
 * SettingConfigHandler); this is the parity guarantee that refactor
 * promised, generated from the pre-refactor handler's actual output.
 */
class FactoryConfigHandlerGoldenTest extends ConfigHandlerTestBase
{
	public function testFactoriesFixtureMatchesGolden()
	{
		Config::set('core.use_translation', true, true);

		$h = new FactoryConfigHandler();
		$h->initialize(null, []);
		$document = $this->parseConfiguration(
			Config::get('core.config_dir') . '/factories.xml',
			null,
			'testing'
		);
		$code = $h->execute($document);
		$code = preg_replace('/^\/\/ Date: .*$/m', '// Date: <normalized>', $code);
		$code = preg_replace('/^\/\/ Compiled from: .*$/m', '// Compiled from: <normalized>', $code);

		$goldenFile = __DIR__ . '/golden/factories_default.php.golden';
		$this->assertFileExists($goldenFile);
		$this->assertSame(file_get_contents($goldenFile), $code);
	}
}
?>
