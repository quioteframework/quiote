<?php
declare(strict_types=1);

namespace Quiote\Introspection;

use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\Plugin\PluginManager;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Routing\Compiler\ModuleActionDiscovery;
use Quiote\Routing\Compiler\ModuleActionEntry;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Routing\Compiler\TriadDiagnosticsScanner;
use Quiote\Routing\Compiler\TriadViewResolver;
use Quiote\Support\Compiler\Diagnostic;
use ReflectionClass;
use Throwable;

/**
 * Builds the versioned `cache/introspection/app.json` artifact an editor
 * extension reads directly, with no PHP spawn, on its warm path: routes,
 * modules, Action/View/Template triads, diagnostics, a dependency manifest,
 * and shadowed-config info. `Quiote\Console\Command\RoutesCompileCommand` is
 * the only writer; this class does the actual compilation so a future
 * probe/`overview` capability elsewhere can reuse it verbatim.
 *
 * Only single-file, one-per-app config types are checked for shadowing here,
 * matching the config validator's own scope.
 * @since      1.0.0
 */
final class AppIntrospectionCompiler
{
	private const SCHEMA_VERSION = 1;

	/** Config logical names that are single-file, one-per-app. */
	private const SHADOWED_CONFIG_CANDIDATES = [
		'settings', 'factories', 'databases', 'output_types',
		'rbac_definitions', 'translation', 'plugins', 'middleware',
	];

	private const VERB_TOKENS = ['read', 'write', 'update', 'remove'];

	public function __construct(private readonly TriadViewResolver $views = new TriadViewResolver())
	{
	}

	/**
	 * @return array{
	 *     _schema_version: int,
	 *     source_hash: string,
	 *     config_format: ?string,
	 *     modules: list<array{name: string, dir: string, actions: list<string>}>,
	 *     routes: list<array{name: string, path: string, methods: list<string>, module: string, action: string, outputType: ?string, source: string, file: ?string, line: ?int}>,
	 *     triads: list<array{module: string, action: string, actionFile: string, viewFile: ?string, templateFiles: array<string, string>, verbs: list<array{name: string, line: ?int}>}>,
	 *     diagnostics: list<array{severity: string, code: string, message: string, file: string, line: ?int, column: ?int, endLine: ?int, endColumn: ?int, symbol: ?string}>,
	 *     dependencies: list<array{file: string, hash: string}>,
	 *     shadowed: list<array{logical: string, loaded: ?string, ignored: list<string>}>,
	 *     outputTypes: array<string, string>,
	 * }
	 */
	public function compile(string $context): array
	{
		$moduleDir = rtrim(Config::getString('core.module_dir'), '/');
		$namespacePrefix = Config::getString('core.namespace_prefix', 'App');
		$moduleDirs = [$moduleDir, ...PluginManager::moduleDirectories()];

		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan($moduleDirs);
		$diagnostics = $scanner->getDiagnostics();

		$entries = (new ModuleActionDiscovery())->discover($moduleDirs, $namespacePrefix);
		$this->initializeModules($context, $entries);
		$scannerController = Context::getInstance($context)->getController();
		foreach ((new TriadDiagnosticsScanner($this->views, $scannerController))->scan($entries) as $diagnostic) {
			$diagnostics[] = $diagnostic;
		}

		$routing = Context::getInstance($context)->getRouting();
		$attributeRoutesByKey = $this->indexAttributeRoutes($plan->routes);

		$routes = [];
		foreach ($routing->getRouteCollection() as $name => $route) {
			$moduleDefault = $route->getDefault('_module');
			$actionDefault = $route->getDefault('_action');
			$module = is_string($moduleDefault) ? $moduleDefault : '';
			$action = is_string($actionDefault) ? $actionDefault : '';
			$attributeRoute = $attributeRoutesByKey[$module . '|' . $action] ?? null;
			$location = $attributeRoute !== null ? $this->locateAction($namespacePrefix, $attributeRoute) : [null, null];

			$outputType = $route->getDefault('_output_type');

			$routes[] = [
				'name' => $name,
				'path' => $route->getPath(),
				'methods' => array_values($route->getMethods()),
				'module' => $module,
				'action' => $action,
				'outputType' => is_string($outputType) ? $outputType : null,
				'source' => $attributeRoute !== null ? 'Attribute' : 'File',
				'file' => $location[0],
				'line' => $location[1],
			];
		}

		$modules = $this->buildModules($entries);
		$triads = $this->buildTriads($entries, $namespacePrefix, $scannerController);
		$dependencies = $this->collectDependencies();
		$shadowed = $this->collectShadowed();
		$outputTypes = $this->buildOutputTypes($scannerController);

		return [
			'_schema_version' => self::SCHEMA_VERSION,
			'source_hash' => hash('sha256', implode('|', array_column($dependencies, 'hash'))),
			'config_format' => Config::getNullableString('core.config_format'),
			'modules' => $modules,
			'routes' => $routes,
			'triads' => $triads,
			'diagnostics' => array_values(array_map(static fn(Diagnostic $diagnostic) => $diagnostic->toArray(), $diagnostics)),
			'dependencies' => $dependencies,
			'shadowed' => $shadowed,
			'outputTypes' => $outputTypes,
		];
	}

	/**
	 * The `quiote.view.name`/`quiote.template.directory`/... directives
	 * `TriadViewResolver` reads are only guaranteed to exist for a module
	 * once `Controller::initializeModule()` has run for it at least once in
	 * this process (it sets their pure-convention defaults as a side effect,
	 * before it does anything module-config-dependent) -- without this, view
	 * names resolve to the unqualified raw token instead of
	 * `{action}{viewName}`, which would misreport every real view as
	 * missing. A disabled module still gets its defaults set (the throw
	 * happens after), so that failure mode is deliberately swallowed here.
	 * @param list<ModuleActionEntry> $entries
	 */
	private function initializeModules(string $context, array $entries): void
	{
		$controller = Context::getInstance($context)->getController();
		$modules = [];
		foreach ($entries as $entry) {
			$modules[$entry->module] = true;
		}
		foreach (array_keys($modules) as $module) {
			try {
				$controller->initializeModule($module);
			} catch (\Throwable) {
				// Defaults are already set as a side effect; nothing more to do.
			}
		}
	}

	/**
	 * @param array<int, RouteDefinition> $routes
	 * @return array<string, RouteDefinition> "module|action" => the first
	 *         attribute-declared RouteDefinition for that action.
	 */
	private function indexAttributeRoutes(array $routes): array
	{
		$byKey = [];
		foreach ($routes as $route) {
			$byKey[$route->module . '|' . $route->action] ??= $route;
		}
		return $byKey;
	}

	/**
	 * @return array{0: ?string, 1: ?int}
	 */
	private function locateAction(string $namespacePrefix, RouteDefinition $route): array
	{
		$fqcn = $namespacePrefix . '\\Modules\\' . $route->module . '\\Actions\\' . str_replace('/', '\\', str_replace('.', '/', $route->action));
		if (!class_exists($fqcn)) {
			return [$route->sourceRef, null];
		}
		$line = (new ReflectionClass($fqcn))->getStartLine();
		return [$route->sourceRef, $line !== false ? $line : null];
	}

	/**
	 * @param list<ModuleActionEntry> $entries
	 * @return list<array{name: string, dir: string, actions: list<string>}>
	 */
	private function buildModules(array $entries): array
	{
		/** @var array<string, array{dir: string, actions: list<string>}> $byModule */
		$byModule = [];
		foreach ($entries as $entry) {
			$byModule[$entry->module] ??= ['dir' => $entry->moduleDir . '/' . $entry->module, 'actions' => []];
			$byModule[$entry->module]['actions'][] = $entry->action;
		}

		$modules = [];
		foreach ($byModule as $name => $data) {
			$modules[] = ['name' => $name, 'dir' => $data['dir'], 'actions' => $data['actions']];
		}
		return $modules;
	}

	/**
	 * @param list<ModuleActionEntry> $entries
	 * @return list<array{module: string, action: string, actionFile: string, viewFile: ?string, templateFiles: array<string, string>, verbs: list<array{name: string, line: ?int}>}>
	 */
	private function buildTriads(array $entries, string $namespacePrefix, Controller $controller): array
	{
		$triads = [];
		foreach ($entries as $entry) {
			if (!class_exists($entry->fqcn, false) && !class_exists($entry->fqcn, true)) {
				require_once $entry->file;
			}
			$legacyClass = $entry->legacyClassName();
			if (class_exists($entry->fqcn, false)) {
				$reflection = new ReflectionClass($entry->fqcn);
			} elseif (class_exists($legacyClass, false)) {
				$reflection = new ReflectionClass($legacyClass);
			} else {
				continue;
			}
			$verbs = [];
			foreach ([...self::VERB_TOKENS, ''] as $token) {
				$method = 'execute' . ucfirst($token);
				if (!$reflection->hasMethod($method)) {
					continue;
				}
				$startLine = $reflection->getMethod($method)->getStartLine();
				$verbs[] = ['name' => $method, 'line' => $startLine !== false ? $startLine : null];
			}

			[$viewFile, $templateFiles] = $this->locateViewAndTemplate($entry, $reflection, $namespacePrefix, $controller);

			$triads[] = [
				'module' => $entry->module,
				'action' => $entry->action,
				'actionFile' => $entry->file,
				'viewFile' => $viewFile,
				'templateFiles' => $templateFiles,
				'verbs' => $verbs,
			];
		}
		return $triads;
	}

	/**
	 * Resolves one template file per output type the view actually renders,
	 * since a single view class can mix several `execute*()` methods (each
	 * its own output type, per {@see TriadViewResolver::executeMethodsFor()})
	 * that each render through a differently-configured renderer/extension
	 * (per {@see TriadViewResolver::templateExtensionFor()}) -- collapsing
	 * that to one `?string` silently assumed every view was PHP-rendered
	 * HTML, so a PHPTAL/Twig/XSLT-rendered `executeHtml()` always reported
	 * `templateFile: null` even though its real template exists on disk.
	 * @param ReflectionClass<object> $reflection
	 * @return array{0: ?string, 1: array<string, string>}
	 */
	private function locateViewAndTemplate(ModuleActionEntry $entry, ReflectionClass $reflection, string $namespacePrefix, Controller $controller): array
	{
		$viewToken = $this->views->resolveViewToken($reflection);
		if ($viewToken === null) {
			return [null, []];
		}

		$canonical = $this->views->canonicalViewToken($entry, $viewToken);
		$viewFile = $this->views->resolveExistingViewFile($entry, $canonical, $namespacePrefix);
		if ($viewFile === null) {
			return [null, []];
		}

		$viewClass = $this->views->viewClassFor($entry, $canonical, $namespacePrefix);
		if (!class_exists($viewClass)) {
			// Legacy (non-class) view file: no execute*() methods to reflect,
			// so fall back to the single conventional template check.
			$templateFile = $this->views->templateFileFor($entry, $canonical);
			$templateFiles = is_file($templateFile) ? [$this->outputTypeKeyFor(null, $controller) => $templateFile] : [];
			return [$viewFile, $templateFiles];
		}

		$templateFiles = [];
		foreach ($this->views->executeMethodsFor(new ReflectionClass($viewClass)) as $method) {
			if ($this->views->declaresNoTemplate($method) || $this->views->alwaysReturnsContent($method)) {
				continue;
			}
			$extension = $this->views->templateExtensionFor($method, $controller);
			$templateFile = $this->views->templateFileFor($entry, $canonical, $extension);
			if (is_file($templateFile)) {
				$templateFiles[$this->outputTypeKeyFor($this->views->outputTypeNameFor($method), $controller)] = $templateFile;
			}
		}

		return [$viewFile, $templateFiles];
	}

	/**
	 * The output type name to key a resolved template file under -- the
	 * method's own explicit output type (`executeJson()` -> `'json'`), or,
	 * for the bare `execute()` method, whichever output type the app
	 * actually resolves as its default (mirrors `Controller::getOutputType()`
	 * `null`-name resolution), falling back to the literal `'default'` only
	 * if that can't be resolved either.
	 */
	private function outputTypeKeyFor(?string $outputTypeName, Controller $controller): string
	{
		if ($outputTypeName !== null) {
			return $outputTypeName;
		}
		try {
			return $controller->getOutputType(null)->getName();
		} catch (Throwable) {
			return 'default';
		}
	}

	/**
	 * @return array<string, string> output type name => its renderer's
	 *         default template file extension, so external tooling with only
	 *         a bare file path (no action/method context to reflect) can
	 *         still make a best-effort guess at which extension a template
	 *         should have.
	 */
	private function buildOutputTypes(Controller $controller): array
	{
		$outputTypes = [];
		foreach ($controller->getOutputTypeNames() as $name) {
			try {
				$extension = $controller->getOutputType($name)->getRenderer()?->getDefaultExtension();
			} catch (Throwable) {
				continue;
			}
			$outputTypes[$name] = $extension !== null && $extension !== '' ? $extension : '.php';
		}
		return $outputTypes;
	}

	/**
	 * @return list<array{file: string, hash: string}>
	 */
	private function collectDependencies(): array
	{
		$files = array_unique(get_included_files());
		sort($files);

		$dependencies = [];
		foreach ($files as $file) {
			$contents = @file_get_contents($file);
			if ($contents === false) {
				continue;
			}
			$dependencies[] = ['file' => $file, 'hash' => hash('sha256', $contents)];
		}
		return $dependencies;
	}

	/**
	 * @return list<array{logical: string, loaded: ?string, ignored: list<string>}>
	 */
	private function collectShadowed(): array
	{
		$configDir = Config::getString('core.config_dir');
		$shadowed = [];
		foreach (self::SHADOWED_CONFIG_CANDIDATES as $logical) {
			$candidates = ConfigCache::describeConfigCandidates($configDir . '/' . $logical . '.xml');
			if ($candidates['shadowed'] === []) {
				continue;
			}
			$shadowed[] = [
				'logical' => $logical,
				'loaded' => $candidates['winner'],
				'ignored' => array_column($candidates['shadowed'], 'path'),
			];
		}
		return $shadowed;
	}
}
