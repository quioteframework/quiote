<?php

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Controller\Controller;
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
		require_once self::FIXTURE_MODULES . '/Widget/Views/NoTemplateOptOutSuccessView.php';
		require_once self::FIXTURE_MODULES . '/Widget/Views/MixedSuccessView.php';
		require_once self::FIXTURE_MODULES . '/Widget/Views/MultiMissingSuccessView.php';
		require_once self::FIXTURE_MODULES . '/Widget/Views/AutoDetectSuccessView.php';
		require_once self::FIXTURE_MODULES . '/Widget/Views/AmbiguousReturnSuccessView.php';
	}

	protected function tearDown(): void
	{
		Config::remove('modules.widget.quiote.template.directory');
		parent::tearDown();
	}

	/** @return list<Diagnostic> */
	private function scan(?Controller $controller = null): array
	{
		$entries = (new ModuleActionDiscovery())->discover([self::FIXTURE_MODULES], 'Sandbox');
		return (new TriadDiagnosticsScanner(controller: $controller))->scan($entries);
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

	public function testDoesNotFlagAViewThatOptsOutOfTheTemplateCheck(): void
	{
		$this->assertNull($this->findFor($this->scan(), 'Widget.NoTemplateOptOut'));
	}

	public function testDoesNotFlagAViewWhoseDeclaredReturnTypeProvesItReturnsContent(): void
	{
		// No @quiote-viewmethod-has-no-template annotation here at all --
		// the non-nullable `string` return type alone must be enough.
		$this->assertNull($this->findFor($this->scan(), 'Widget.AutoDetect'));
	}

	public function testFlagsAViewWhoseNullableReturnTypeCannotProveItSkipsTheTemplate(): void
	{
		// A nullable return type can't statically prove the method never
		// renders a template, so the conservative fallback must still flag
		// it absent an explicit opt-out.
		$diagnostic = $this->findFor($this->scan(), 'Widget.AmbiguousReturn');

		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_TEMPLATE, $diagnostic->code);
	}

	public function testDoesNotFlagAViewWhoseUnannotatedMethodHasATemplate(): void
	{
		// executeJson()/execute() opt out and have no template; executeHtml()
		// doesn't opt out but does have one -- the opt-out must be scoped to
		// the specific method, not the whole view.
		$this->assertNull($this->findFor($this->scan(), 'Widget.Mixed'));
	}

	public function testCombinesMultipleMissingExecuteMethodsIntoOneDiagnostic(): void
	{
		$diagnostic = $this->findFor($this->scan(), 'Widget.MultiMissing');

		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_TEMPLATE, $diagnostic->code);
		$this->assertStringContainsString('executeHtml()', $diagnostic->message);
		$this->assertStringContainsString('executeXml()', $diagnostic->message);
		// execute() opts out, so it must not appear in the missing list.
		$this->assertStringNotContainsString('execute() ->', $diagnostic->message);
	}

	public function testResolvesTemplateExtensionsViaAnExplicitController(): void
	{
		$controller = Context::getInstance('web')->getController();

		$this->assertNull($this->findFor($this->scan($controller), 'Widget.Good'));

		$diagnostic = $this->findFor($this->scan($controller), 'Widget.NoTemplate');
		$this->assertNotNull($diagnostic);
		$this->assertSame(Diagnostic::CODE_MISSING_TEMPLATE, $diagnostic->code);
	}

	public function testDoesNotFlagAnActionThatNeverOverridesGetDefaultViewName(): void
	{
		// Inherits the base Action's 'Input' constant -- not a real
		// declaration, so it must never be treated as one.
		$this->assertNull($this->findFor($this->scan(), 'Widget.NoAutoView'));
	}
}
