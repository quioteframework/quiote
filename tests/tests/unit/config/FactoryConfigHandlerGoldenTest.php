<?php

use Quiote\Config\CompiledArtifact;
use Quiote\Config\Config;
use Quiote\Config\FactoryConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Locks in FactoryConfigHandler's compiled output against the real
 * tests/sandbox/app/Config/factories.xml fixture: execute() is a two-line
 * adapter over toCanonicalArray() + executeArray(), and this golden file
 * is what keeps the pair producing the compiled shape ComponentInstaller
 * expects.
 */
class FactoryConfigHandlerGoldenTest extends ConfigHandlerTestBase
{
	public function testFactoriesFixtureMatchesGolden(): void
	{
		Config::set('core.use_translation', true, true);

		$h = new FactoryConfigHandler();
		$h->initialize(null, []);
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/factories.xml',
			null,
			'testing'
		);
		$code = CompiledArtifact::source($h->execute($document), $document->documentURI, $h::class);
		// preg_replace() only returns null on a regex engine error; fall back to
		// the pre-replacement value in that (effectively unreachable) case.
		$code = preg_replace('/^\/\/ Date: .*$/m', '// Date: <normalized>', $code) ?? $code;
		$code = preg_replace('/^\/\/ Compiled from: .*$/m', '// Compiled from: <normalized>', $code) ?? $code;
		// The fixture's session factory resolves '%core.app_dir%/cache/sessions'
		// against wherever this checkout lives, which would otherwise make the
		// golden file fail on every other machine/CI checkout path.
		$appDir = Config::getString('core.app_dir');
		if ($appDir !== '') {
			$code = str_replace($appDir, '<app_dir>', $code);
		}

		$goldenFile = __DIR__ . '/golden/factories_default.php.golden';
		$this->assertFileExists($goldenFile);
		$this->assertSame(file_get_contents($goldenFile), $code);
	}
}
?>
