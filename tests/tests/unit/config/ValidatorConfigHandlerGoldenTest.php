<?php

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ValidatorConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Locks in the compiled PHP that ValidatorConfigHandler produces for a
 * representative corpus of validators.xml fixtures (operator nesting,
 * translation-domain inheritance across both envelope versions, error
 * message inheritance/override, and a real runtime validator with
 * arguments/errors/parameters).
 *
 * ValidatorConfigHandler::execute() is now a thin adapter over
 * ValidatorPlanBuilder (XML -> IR) and RuntimeArrayEmitter (IR -> runtime
 * snippets), phase 1. This test is
 * the parity guarantee that refactor promised: any future change to either
 * class that alters the compiled output for these fixtures must fail here
 * first, rather than surfacing as a silent behavior change in production
 * validator configs.
 *
 * The "// Date: ..." header line is non-deterministic (stamped per
 * compile) and is normalized before comparison; everything else, including
 * whitespace, must match byte-for-byte.
 */
class ValidatorConfigHandlerGoldenTest extends ConfigHandlerTestBase
{
	protected function getContext()
	{
		if (Config::get('core.default_context') === null) {
			Config::set('core.default_context', 'web', true, true);
		}
		return Context::getInstance(Config::get('core.default_context'));
	}

	private function compile(string $configFile, ?string $xslFile, string $environment): string
	{
		$VCH = new ValidatorConfigHandler();
		$document = $this->parseConfiguration($configFile, $xslFile, $environment);
		$code = $VCH->execute($document);
		$code = preg_replace('/^\/\/ Date: .*$/m', '// Date: <normalized>', $code);
		// Normalize the source path too: it's a function of how the caller
		// resolved the file (double slashes etc. are harmless), not of the
		// compiler logic this test is meant to pin down.
		return preg_replace('/^\/\/ Compiled from: .*$/m', '// Compiled from: <normalized>', $code);
	}

	private function assertMatchesGolden(string $goldenName, string $configFile, ?string $xslFile, string $environment): void
	{
		$goldenFile = __DIR__ . '/golden/' . $goldenName . '.php.golden';
		$this->assertFileExists($goldenFile, 'Golden fixture missing: ' . $goldenFile);
		$expected = file_get_contents($goldenFile);
		$actual = $this->compile($configFile, $xslFile, $environment);
		$this->assertSame($expected, $actual, 'Compiled output for "' . $goldenName . '" drifted from the golden fixture.');
	}

	public function testMainTranslationDomainFixture()
	{
		$this->assertMatchesGolden(
			'main_translation_domain',
			Config::get('core.config_dir') . '/tests/validators.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl',
			'test-translation-domain'
		);
	}

	public function testMainTranslationDomain10BehaviourFixture()
	{
		$this->assertMatchesGolden(
			'main_translation_domain_1_0',
			Config::get('core.config_dir') . '/tests/validators.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl',
			'test-translation-domain-1.0-behaviour'
		);
	}

	public function testMainErrorDefinitionsFixture()
	{
		$this->assertMatchesGolden(
			'main_error_definitions',
			Config::get('core.config_dir') . '/tests/validators.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl',
			'test-validator-definition-error-definition'
		);
	}

	public function testKnownParameterFixture()
	{
		$this->assertMatchesGolden(
			'known_parameter',
			Config::get('core.config_dir') . '/tests/validators_unknown_param.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl',
			'test-known-parameter'
		);
	}

	public function testMethodHttpFixture()
	{
		$this->assertMatchesGolden(
			'method_http',
			Config::get('core.module_dir') . '/Method/Validate/MethodHttp.xml',
			null,
			'test'
		);
	}
}
?>
