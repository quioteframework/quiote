<?php

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ValidatorConfigHandler;
use Quiote\Exception\ConfigurationException;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Regression coverage for the compile-time unknown-parameter rejection.
 *
 * This targets the exact bug shape that caused a real SQL injection
 * incident: a validator config carried a `values="a,b,c"` allowlist
 * attribute that the configured validator class never read. The attribute
 * was silently absorbed into the parameter bag and the field was marked
 * "validated" without the allowlist ever being enforced.
 */
class ValidatorConfigHandlerUnknownParameterTest extends ConfigHandlerTestBase
{
	private $fixture;

	protected function setUp(): void
	{
		parent::setUp();
		$this->fixture = Config::get('core.config_dir') . '/tests/validators_unknown_param.xml';
	}

	protected function tearDown(): void
	{
		Config::remove('validation.reject_unknown_parameters');
		parent::tearDown();
	}

	protected function getContext()
	{
		if (Config::get('core.default_context') === null) {
			Config::set('core.default_context', 'web', true, true);
		}
		return Context::getInstance(Config::get('core.default_context'));
	}

	private function compile(string $environment): string
	{
		$VCH = new ValidatorConfigHandler();
		$document = $this->parseConfiguration(
			$this->fixture,
			Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl',
			$environment
		);
		return $VCH->execute($document);
	}

	public function testDefaultModeThrowsOnUnknownParameter()
	{
		// No explicit mode set: default must be 'throw' -- fail closed.
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/Unknown parameter "values" on validator "bad" \(Quiote\\\\Validator\\\\StringValidator\)/');
		$this->compile('test-unknown-parameter');
	}

	public function testThrowModeMessageListsAcceptedParameters()
	{
		try {
			Config::set('validation.reject_unknown_parameters', 'throw', true);
			$this->compile('test-unknown-parameter');
			$this->fail('Expected ConfigurationException was not thrown.');
		} catch (ConfigurationException $e) {
			$this->assertStringContainsString('Accepted: name, class, method', $e->getMessage());
			$this->assertStringContainsString('min, max, trim, utf8', $e->getMessage());
		}
	}

	public function testTypoSuggestsClosestAcceptedParameter()
	{
		try {
			Config::set('validation.reject_unknown_parameters', 'throw', true);
			$this->compile('test-typo-parameter');
			$this->fail('Expected ConfigurationException was not thrown.');
		} catch (ConfigurationException $e) {
			$this->assertStringContainsString('Unknown parameter "minn"', $e->getMessage());
			$this->assertStringContainsString('Did you mean "min"?', $e->getMessage());
		}
	}

	public function testWarnModeCompilesAndLogsInsteadOfThrowing()
	{
		Config::set('validation.reject_unknown_parameters', 'warn', true);
		$code = $this->compile('test-unknown-parameter');
		$this->assertIsString($code);
		$this->assertStringContainsString('StringValidator', $code);
	}

	public function testOffModeSkipsCheckEntirely()
	{
		Config::set('validation.reject_unknown_parameters', 'off', true);
		$code = $this->compile('test-unknown-parameter');
		$this->assertIsString($code);
	}

	public function testKnownParametersCompileCleanlyUnderThrowMode()
	{
		Config::set('validation.reject_unknown_parameters', 'throw', true);
		$code = $this->compile('test-known-parameter');
		$this->assertIsString($code);
		$this->assertStringContainsString('StringValidator', $code);
	}

	public function testCustomValidatorSubclassWithoutOverrideIsStillChecked()
	{
		Config::set('validation.reject_unknown_parameters', 'throw', true);
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/Unknown parameter "totally_bogus" on validator "bad_custom" \(Sandbox\\\\Testing\\\\DummyValidator\)/');
		$this->compile('test-unknown-parameter-custom-class');
	}

	public function testUnresolvableClassDegradesToNoticeInsteadOfThrowing()
	{
		Config::set('validation.reject_unknown_parameters', 'throw', true);
		// The class doesn't exist, so it can't be introspected -- the check
		// must not fail the build over something it cannot verify.
		$code = $this->compile('test-unresolvable-class');
		$this->assertIsString($code);
		$this->assertStringContainsString('NoSuchValidatorClassAtAll', $code);
	}
}
?>
