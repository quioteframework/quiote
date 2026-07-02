<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Quiote\Config\Config;
use Quiote\Routing\Attribute\Route;
use Quiote\Support\Compiler\Diagnostic;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Discovers #[Route] attributes on action classes under one or more module
 * directories and builds a RoutePlan from them. This is the first front-end
 * for the routing IR (see docs/ROUTING_AND_CLI_PLAN.md) -- a future
 * RoutingConfigHandler (XML) or programmatic builder would feed the same
 * RoutePlan shape without either back-end (RouteCollectionBuilder, the
 * compiled-matcher emitter) needing to change.
 *
 * module/action are derived from a class's location, mirroring
 * Controller::createActionInstance()'s reverse mapping:
 *   {namespace_prefix}\Modules\{Module}\Actions\{Namespaced\Action}Action
 *   <-> %core.module_dir%/{Module}/Actions/{Namespaced/Action}Action.php
 * A nested action file (Actions/Index/AddAction.php) yields the dotted
 * action name "Index.Add", matching Toolkit::canonicalName()'s '.' <-> '/'
 * convention used throughout the rest of routing/dispatch.
 * @since      1.0.0
 */
final class AttributeRouteScanner
{
	public const CODE_DUPLICATE_ROUTE_NAME = 'DUPLICATE_ROUTE_NAME';
	public const CODE_DUPLICATE_ROUTE_PATH = 'DUPLICATE_ROUTE_PATH';

	/** @var Diagnostic[] */
	private array $diagnostics = [];

	/**
	 * @param iterable<string>|null $moduleDirs Directories each containing
	 *        `{Module}/Actions/**\/*Action.php` subtrees; defaults to
	 *        [core.module_dir].
	 */
	public function scan(?iterable $moduleDirs = null): RoutePlan
	{
		$this->diagnostics = [];

		$moduleDirs = $moduleDirs !== null ? $moduleDirs : [Config::get('core.module_dir')];
		$namespacePrefix = (string) Config::get('core.namespace_prefix', 'App');

		/** @var RouteDefinition[] $routes */
		$routes = [];
		/** @var array<string,string> $seenNames name => sourceRef */
		$seenNames = [];
		/** @var array<string,string> $seenPaths "METHOD path" => sourceRef */
		$seenPaths = [];

		foreach ($moduleDirs as $moduleDir) {
			foreach ($this->discoverActionFiles((string) $moduleDir) as [$module, $relativePath, $file]) {
				$action = $this->deriveActionName($relativePath);
				$fqcn = $this->deriveClassName($namespacePrefix, $module, $relativePath);

				if (!class_exists($fqcn, false) && !class_exists($fqcn, true)) {
					require_once $file;
				}
				if (!class_exists($fqcn, false)) {
					continue;
				}

				$reflection = new ReflectionClass($fqcn);
				$attributes = $reflection->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
				if (!$attributes) {
					continue;
				}

				foreach ($attributes as $attribute) {
					$route = $this->buildRouteDefinition($attribute->newInstance(), $module, $action, $file);

					if (isset($seenNames[$route->name])) {
						$this->diagnostics[] = new Diagnostic(
							Diagnostic::SEVERITY_ERROR,
							self::CODE_DUPLICATE_ROUTE_NAME,
							sprintf('Route name "%s" is declared more than once (also in %s).', $route->name, $seenNames[$route->name]),
							$file
						);
					}
					$seenNames[$route->name] = $file;

					foreach ($this->pathMethodKeys($route) as $key) {
						if (isset($seenPaths[$key])) {
							$this->diagnostics[] = new Diagnostic(
								Diagnostic::SEVERITY_WARNING,
								self::CODE_DUPLICATE_ROUTE_PATH,
								sprintf('Route "%s" duplicates path+method "%s" already declared in %s.', $route->name, $key, $seenPaths[$key]),
								$file
							);
						}
						$seenPaths[$key] = $file;
					}

					$routes[] = $route;
				}
			}
		}

		return new RoutePlan($routes, implode(':', (array) $moduleDirs));
	}

	/**
	 * @return Diagnostic[] Diagnostics recorded during the last scan().
	 */
	public function getDiagnostics(): array
	{
		return $this->diagnostics;
	}

	/**
	 * @return string[]
	 */
	private function pathMethodKeys(RouteDefinition $route): array
	{
		$methods = $route->methods ?: ['*'];
		return array_map(static fn(string $method) => strtoupper($method) . ' ' . $route->path, $methods);
	}

	private function buildRouteDefinition(Route $attribute, string $module, string $action, string $sourceRef): RouteDefinition
	{
		$name = $attribute->name ?? $this->defaultRouteName($module, $action);

		return new RouteDefinition(
			$name,
			$attribute->path,
			$module,
			$action,
			$attribute->methods,
			$attribute->defaults,
			$attribute->requirements,
			$attribute->host,
			$attribute->condition,
			$attribute->priority,
			$attribute->outputType,
			[
				'gen_path' => $attribute->path,
				'cut' => false,
				'path' => $attribute->path,
			],
			$sourceRef
		);
	}

	private function defaultRouteName(string $module, string $action): string
	{
		return strtolower($module . '.' . str_replace('/', '.', $action));
	}

	/**
	 * @return array{0:string,1:string,2:string}[] [module, path relative to
	 *         Actions/ without the .php extension, absolute file path]
	 */
	private function discoverActionFiles(string $moduleDir): array
	{
		$found = [];
		foreach (glob($moduleDir . '/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
			$module = basename($modulePath);
			$actionsDir = $modulePath . '/Actions';
			if (!is_dir($actionsDir)) {
				continue;
			}
			foreach ($this->findActionFiles($actionsDir) as $file) {
				$relative = substr($file, strlen($actionsDir) + 1, -4); // strip "Actions/" prefix and ".php" suffix
				$found[] = [$module, str_replace('\\', '/', $relative), $file];
			}
		}
		sort($found);
		return $found;
	}

	/**
	 * @return string[] Absolute file paths, sorted.
	 */
	private function findActionFiles(string $dir): array
	{
		$files = glob($dir . '/*Action.php') ?: [];
		foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
			$files = array_merge($files, $this->findActionFiles($subdir));
		}
		sort($files);
		return $files;
	}

	private function deriveActionName(string $relativePath): string
	{
		$segments = explode('/', $relativePath);
		$last = array_pop($segments);
		if (str_ends_with($last, 'Action')) {
			$last = substr($last, 0, -strlen('Action'));
		}
		$segments[] = $last;
		return implode('.', $segments);
	}

	private function deriveClassName(string $namespacePrefix, string $module, string $relativePath): string
	{
		return $namespacePrefix . '\\Modules\\' . $module . '\\Actions\\' . str_replace('/', '\\', $relativePath);
	}
}
