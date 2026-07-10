<?php

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\Controller\OutputType;
use Quiote\Introspection\AppIntrospectionCompiler;
use Quiote\Renderer\Phptal\PhptalRenderer;
use Quiote\Renderer\PhpRenderer;
use Quiote\Routing\Compiler\ModuleActionDiscovery;
use Quiote\Routing\Compiler\ModuleActionEntry;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Testing\PhpUnitTestCase;

final class AppIntrospectionCompilerTest extends PhpUnitTestCase
{
	private const FIXTURE_MODULES = __DIR__ . '/../../../fixtures/TriadDiagnostics/Modules';

	public function testCompileProducesTheDocumentedTopLevelShape(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$this->assertSame(1, $artifact['_schema_version']);
		$this->assertNotSame('', $artifact['source_hash']);
		foreach (['modules', 'routes', 'triads', 'diagnostics', 'dependencies', 'shadowed', 'outputTypes'] as $key) {
			$this->assertArrayHasKey($key, $artifact);
		}
	}

	public function testCompileReportsARendererDefaultExtensionPerConfiguredOutputType(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$this->assertArrayHasKey('html', $artifact['outputTypes']);
		$this->assertSame('.php', $artifact['outputTypes']['html']);
	}

	/**
	 * Regression for the bug where `AppIntrospectionCompiler` always assumed
	 * `.php` templates: a view with `executeHtml()` rendered through a
	 * non-PHP renderer (`.tal`) must report that real template file for the
	 * "html" output type, and must not report one for `executeJson()`, which
	 * opts out via `@quiote-viewmethod-has-no-template`.
	 */
	public function testLocateViewAndTemplateResolvesAPerOutputTypeTemplateFile(): void
	{
		Context::getInstance('web')->getController()->initializeModule('Widget');
		Config::set('modules.widget.quiote.template.directory', self::FIXTURE_MODULES . '/Widget/Templates');

		try {
			require_once self::FIXTURE_MODULES . '/Widget/Actions/MultiOutputTypeAction.php';
			require_once self::FIXTURE_MODULES . '/Widget/Views/MultiOutputTypeSuccessView.php';

			$entries = (new ModuleActionDiscovery())->discover([self::FIXTURE_MODULES], 'Sandbox');
			$entry = null;
			foreach ($entries as $candidate) {
				if ($candidate->module === 'Widget' && $candidate->action === 'MultiOutputType') {
					$entry = $candidate;
				}
			}
			$this->assertInstanceOf(ModuleActionEntry::class, $entry);

			$controller = $this->buildControllerWithHtmlAsTal();

			$compiler = new AppIntrospectionCompiler();
			$method = new ReflectionMethod(AppIntrospectionCompiler::class, 'locateViewAndTemplate');
			/** @var array{0: ?string, 1: array<string, string>} $result */
			$result = $method->invoke(
				$compiler,
				$entry,
				new ReflectionClass('Sandbox\\Modules\\Widget\\Actions\\MultiOutputTypeAction'),
				'Sandbox',
				$controller,
			);
			[$viewFile, $templateFiles] = $result;

			$this->assertIsString($viewFile);
			$this->assertStringEndsWith('MultiOutputTypeSuccessView.php', $viewFile);
			$this->assertArrayHasKey('html', $templateFiles);
			$this->assertStringEndsWith('MultiOutputTypeSuccess.tal', $templateFiles['html']);
			$this->assertArrayNotHasKey('json', $templateFiles);
		} finally {
			Config::remove('modules.widget.quiote.template.directory');
		}
	}

	public function testBuildOutputTypesMapsEachOutputTypeToItsRendererDefaultExtension(): void
	{
		$controller = $this->buildControllerWithHtmlAsTal();

		$compiler = new AppIntrospectionCompiler();
		$method = new ReflectionMethod(AppIntrospectionCompiler::class, 'buildOutputTypes');
		/** @var array<string, string> $outputTypes */
		$outputTypes = $method->invoke($compiler, $controller);

		$this->assertSame('.tal', $outputTypes['html']);
		$this->assertSame('.php', $outputTypes['json']);
	}

	public function testOutputTypeKeyForFallsBackToTheLiteralDefaultWhenUnresolvable(): void
	{
		$compiler = new AppIntrospectionCompiler();
		$controller = new Controller();

		$method = new ReflectionMethod(AppIntrospectionCompiler::class, 'outputTypeKeyFor');

		$this->assertSame('json', $method->invoke($compiler, 'json', $controller));
		$this->assertSame('default', $method->invoke($compiler, null, $controller));
	}

	/**
	 * @return Controller A standalone Controller (no Context wiring) whose
	 *         "html" output type resolves through PhptalRenderer (`.tal`)
	 *         and "json" through PhpRenderer (`.php`), so extension
	 *         resolution can be exercised without a full config/compile
	 *         pipeline.
	 */
	private function buildControllerWithHtmlAsTal(): Controller
	{
		$controller = new Controller();

		$html = new OutputType();
		$html->initialize(
			Context::getInstance('web'),
			[],
			'html',
			['phptal' => ['class' => PhptalRenderer::class, 'instance' => null, 'parameters' => []]],
			'phptal',
			[],
			null,
		);

		$json = new OutputType();
		$json->initialize(
			Context::getInstance('web'),
			[],
			'json',
			['php' => ['class' => PhpRenderer::class, 'instance' => null, 'parameters' => []]],
			'php',
			[],
			null,
		);

		$property = new ReflectionProperty(Controller::class, 'outputTypes');
		$property->setValue($controller, ['html' => $html, 'json' => $json]);

		return $controller;
	}

	public function testCompileIncludesTheSandboxDefaultModuleAndItsIndexAction(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$moduleNames = array_column($artifact['modules'], 'name');
		$this->assertContains('Default', $moduleNames);

		$defaultModule = null;
		foreach ($artifact['modules'] as $module) {
			if ($module['name'] === 'Default') {
				$defaultModule = $module;
			}
		}
		$this->assertNotNull($defaultModule);
		$this->assertContains('Index', $defaultModule['actions']);
	}

	public function testCompileResolvesAKnownGoodTriadWithNoDiagnostic(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$triad = null;
		foreach ($artifact['triads'] as $candidate) {
			if ($candidate['module'] === 'Default' && $candidate['action'] === 'Index') {
				$triad = $candidate;
			}
		}
		$this->assertNotNull($triad);
		$this->assertStringEndsWith('IndexAction.php', $triad['actionFile']);
		$this->assertNotNull($triad['viewFile']);
		$this->assertStringEndsWith('IndexSuccessView.php', $triad['viewFile']);

		$verbNames = array_column($triad['verbs'], 'name');
		$this->assertContains('executeRead', $verbNames);

		foreach ($artifact['diagnostics'] as $diagnostic) {
			$this->assertNotSame('Default.Index', $diagnostic['symbol']);
		}
	}

	public function testCompileListsRoutesFromTheConfiguredRoutingService(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$names = array_column($artifact['routes'], 'name');
		$this->assertContains('index', $names);
	}

	public function testDiagnosticsSurviveTheArrayRoundTripWithDiagnosticToArrayShape(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		foreach ($artifact['diagnostics'] as $diagnostic) {
			foreach (['severity', 'code', 'message', 'file', 'line', 'column', 'endLine', 'endColumn', 'symbol'] as $key) {
				$this->assertArrayHasKey($key, $diagnostic);
			}
			$this->assertContains($diagnostic['severity'], [Diagnostic::SEVERITY_WARNING, Diagnostic::SEVERITY_ERROR]);
		}
	}
}
