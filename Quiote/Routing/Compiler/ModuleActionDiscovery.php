<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

/**
 * Filesystem discovery of every `{Module}/Actions/**\/*Action.php` file
 * under one or more module directories, independent of whether the action
 * carries a #[Route] attribute. `AttributeRouteScanner` only surfaces
 * actions that declare a route; introspection consumers (the
 * `cache/introspection/app.json` artifact, triad diagnostics) need the full
 * action inventory per module, so this is a sibling front-end over the same
 * `Actions/` convention rather than a route-scoped one.
 * @since      1.0.0
 */
final class ModuleActionDiscovery
{
	/**
	 * @param iterable<string> $moduleDirs
	 * @return list<ModuleActionEntry>
	 */
	public function discover(iterable $moduleDirs, string $namespacePrefix): array
	{
		$entries = [];
		foreach ($moduleDirs as $moduleDir) {
			foreach ($this->discoverActionFiles((string) $moduleDir) as [$module, $relativePath, $file]) {
				$action = $this->deriveActionName($relativePath);
				$fqcn = $namespacePrefix . '\\Modules\\' . $module . '\\Actions\\' . str_replace('/', '\\', $relativePath);
				$entries[] = new ModuleActionEntry($module, $action, $file, $fqcn, (string) $moduleDir);
			}
		}
		return $entries;
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
}
