<?php

use Quiote\Config\Config;
use Quiote\Config\SettingConfigHandler;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * Locks in the compiled output of SettingConfigHandler against the real
 * tests/sandbox/app/Config/settings.xml fixture (system_actions, plain
 * settings, and an environment override). SettingConfigHandler::execute() is now
 * a two-line adapter over toCanonicalArray() + executeArray() (phase 2);
 * this is the parity guarantee that refactor promised, generated from the
 * pre-refactor handler's actual output.
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
			Config::getString('core.config_dir') . '/settings.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/settings.xsl',
			$environment
		);
		$code = $h->execute($document);
		// preg_replace() only returns null on a regex engine error; fall back to
		// the pre-replacement value in that (effectively unreachable) case
		// rather than widening this method's return type.
		$code = preg_replace('/^\/\/ Date: .*$/m', '// Date: <normalized>', $code) ?? $code;
		return preg_replace('/^\/\/ Compiled from: .*$/m', '// Compiled from: <normalized>', $code) ?? $code;
	}

	private function assertMatchesGolden(string $goldenName, string $environment): void
	{
		$goldenFile = __DIR__ . '/golden/' . $goldenName . '.php.golden';
		$this->assertFileExists($goldenFile, 'Golden fixture missing: ' . $goldenFile);
		$expected = file_get_contents($goldenFile);
		$actual = $this->compile($environment);
		$this->assertSame($expected, $actual, 'Compiled output for "' . $goldenName . '" drifted from the golden fixture.');
	}

	public function testDefaultTestingEnvironmentFixture(): void
	{
		$this->assertMatchesGolden('settings_default', 'testing');
	}

	public function testProductionEnvironmentFixture(): void
	{
		$this->assertMatchesGolden('settings_prod', 'production');
	}

	/**
	 * A <system_action> missing its required <module> or <action> child
	 * element must fail fast with a clear ParseException instead of a
	 * bare "call to a member function getValue() on null" fatal error.
	 */
	public function testSystemActionMissingModuleChildThrowsParseException(): void
	{
		$ns = 'http://quiote.dev/quiote/config/parts/settings/1.1';
		$inner = <<<XML
<system_actions xmlns="$ns">
  <system_action name="broken">
    <action>index</action>
  </system_action>
</system_actions>
XML;

		$this->expectException(ParseException::class);
		$this->expectExceptionMessageMatches('/system_action "broken" missing its required/');

		$h = new SettingConfigHandler();
		$h->initialize(null, []);
		$h->execute($this->wrapEnvelope($inner, 'settings_broken.xml'));
	}

	/**
	 * A <system_action> missing its required <action> child element must
	 * also fail fast with a clear ParseException.
	 */
	public function testSystemActionMissingActionChildThrowsParseException(): void
	{
		$ns = 'http://quiote.dev/quiote/config/parts/settings/1.1';
		$inner = <<<XML
<system_actions xmlns="$ns">
  <system_action name="broken">
    <module>default</module>
  </system_action>
</system_actions>
XML;

		$this->expectException(ParseException::class);
		$this->expectExceptionMessageMatches('/system_action "broken" missing its required/');

		$h = new SettingConfigHandler();
		$h->initialize(null, []);
		$h->execute($this->wrapEnvelope($inner, 'settings_broken2.xml'));
	}

	/**
	 * A well-formed <system_action> with both children present must still
	 * compile successfully (happy path counterpart to the two tests above).
	 */
	public function testSystemActionWithBothChildrenCompiles(): void
	{
		$ns = 'http://quiote.dev/quiote/config/parts/settings/1.1';
		$inner = <<<XML
<system_actions xmlns="$ns">
  <system_action name="ok">
    <module>default</module>
    <action>index</action>
  </system_action>
</system_actions>
XML;

		$h = new SettingConfigHandler();
		$h->initialize(null, []);
		$code = $h->execute($this->wrapEnvelope($inner, 'settings_ok.xml'));
		$this->assertStringContainsString('actions.ok_module', $code);
		$this->assertStringContainsString('actions.ok_action', $code);
	}

	private function wrapEnvelope(string $inner, string $uri): XmlConfigDomDocument
	{
		$xml = <<<XML
<?xml version="1.0"?>
<configurations xmlns="http://quiote.dev/quiote/config/global/envelope/1.1">
  <configuration>
    $inner
  </configuration>
</configurations>
XML;
		$doc = new XmlConfigDomDocument();
		$doc->loadXml($xml);
		$r = new ReflectionProperty(XmlConfigDomDocument::class, 'documentURI');
		$r->setValue($doc, sys_get_temp_dir() . '/' . $uri);
		return $doc;
	}
}
?>
