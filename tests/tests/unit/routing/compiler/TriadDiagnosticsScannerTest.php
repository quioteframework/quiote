<?php

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Routing\Compiler\ModuleActionDiscovery;
use Quiote\Routing\Compiler\TriadDiagnosticsScanner;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The fixture module lives outside core.module_dir (like the RoutingDup*
 * fixtures AttributeRouteScannerTest uses), so
 * `quiote.template.directory` -- which defaults to
 * `%core.module_dir%/${module}/Templates` -- has to be pointed at the
 * fixture's own Templates dir explicitly; everything else (view name
 * concatenation, action/legacy class resolution) uses the framework's real
 * defaults via Controller::initializeModule().
 */
final class TriadDiagnosticsScannerTest extends PhpUnitTestCase
{
	private const FIXTURE_MODULES = __DIR__ . '/../../../../fixtures/TriadDiagnostics/Modules';

	protected function setUp(): void
	{
		parent::setUp();
		Context::getInstance('web')->getController()->initializeModule('Widget');
		Config::set('modules.widget.quiote.template.directory', self::FIXTURE_MODULES . '/Widget/Templates');

		// The fixture module lives outside core.module_dir, so neither
		// Composer's PSR-4 map nor AbstractAppCommand's app-namespace
		// fallback autoloader (both keyed to the real sandbox app dir) will
		// find these View classes -- load them directly, the same way
		// ModuleActionDiscovery/AttributeRouteScanner require_once the
		// Action files they discover.
		require_once self::FIXTURE_MODULES . '/Widget/Views/GoodSuccessView.php';
		require_once self::FIXTURE_MODULES . '/Widget/Views/NoTemplateSuccessView.php';
	}

	protected function tearDown(): void
	{
		Config::remove('modules.widget.quiote.template.directory');
		parent::tearDown();
	}

	/** @return list<Diagnostic> */
	private function scan(): array
	{
		$entries = (new ModuleActionDiscovery())->discover([self::FIXTURE_MODULES], 'Sandbox');
		return (new TriadDiagnosticsScanner())->scan($entries);
	}

	/**
	 * @param list<Diagnostic> $diagnostics
	 */
	private function findFor(array $diagnostics, string $symbol): ?Diagnostic
	{
		foreach ($diagnostics as $diagnostic) {
			if ($diagnostic->symbol === $symbol) {
				return $diagnostic;
			}
		}
		return null;
	}

	public function testFlagsAMissingActionClass(): void
	{
		$diagnostic = $this->findFor($this->scan(), 'Widget.Broken');

		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_ACTION_CLASS, $diagnostic->code);
		$this->assertSame(Diagnostic::SEVERITY_ERROR, $diagnostic->severity);
	}

	public function testFlagsADeclaredViewWithNoMatchingClassOrFile(): void
	{
		$diagnostic = $this->findFor($this->scan(), 'Widget.NoView');

		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_VIEW, $diagnostic->code);
		$this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
	}

	public function testFlagsAResolvedViewWithNoTemplateFile(): void
	{
		$diagnostic = $this->findFor($this->scan(), 'Widget.NoTemplate');

		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_TEMPLATE, $diagnostic->code);
		$this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
	}

	public function testDoesNotFlagAnActionWithAMatchingViewAndTemplate(): void
	{
		$this->assertNull($this->findFor($this->scan(), 'Widget.Good'));
	}

	public function testDoesNotFlagAnActionThatNeverOverridesGetDefaultViewName(): void
	{
		// Inherits the base Action's 'Input' constant -- not a real
		// declaration, so it must never be treated as one.
		$this->assertNull($this->findFor($this->scan(), 'Widget.NoAutoView'));
	}
}
