<?php
namespace Quiote\Validator\Compiler\Runtime;

use Quiote\Context;
use Quiote\Validator\IValidatorContainer;
use RuntimeException;

/**
 * Resolves and loads the compiled/hand-written PHP validator-builder file
 * for a module/action, if one exists, and applies it to a ValidatorBuilder
 * scoped to the given container. Wired into Action::registerValidators()
 * by default, so committing a generated (or hand-written) validator file
 * is all it takes to activate it -- no per-action boilerplate.
 *
 * The file is a plain `require` (opcache-backed): no parsing happens at
 * request time beyond what any other PHP include already costs. It must
 * `return` a callable accepting a single ValidatorBuilder argument -- the
 * exact shape FluentSourceEmitter produces, and the shape a developer can
 * hand-write for an action that never had an XML config at all.
 *
 * Registering through this path gives the same guarantee as the XML path:
 * ValidationManager derives its strict-mode whitelist from whichever
 * validators got addChild()'d before it executes (see
 * ValidatorBuilder/ValidatorSpec), so a parameter with no validator here
 * is pruned from the request exactly as it would be for an XML-declared
 * action -- there is no separate, weaker guarantee for the fluent path.
 *
 * Since this now runs by default for every action (most of which have no
 * compiled/hand-written validator file at all), path resolution is
 * memoized per (moduleDir, module, action) the same way
 * ConfigCache::isModified() memoizes its own filesystem checks -- a
 * documented optimization for persistent workers (FrankenPHP etc.) where
 * "no such file" is trusted across the worker's lifetime rather than
 * re-stat()'d on every request, and a resolved path is re-verified with a
 * single stat() so a file removed between requests is still noticed.
 * Both caches are process-wide by design; deploying a new/changed
 * validator file is expected to go through a worker restart, exactly like
 * every other compiled-artifact cache in this framework.
 * @since      1.0.0
 */
final class CompiledValidatorRegistry
{
	/**
	 * @var array<string, string|false> Cache key => resolved candidate path,
	 *                                  or false for "neither candidate exists".
	 */
	private static array $resolvedPathCache = [];

	/**
	 * @return bool True if a compiled/hand-written validator file was found
	 *              and applied, false if neither candidate exists (not
	 *              every action needs validators -- this is not an error).
	 */
	public function apply(
		string $moduleDir,
		string $module,
		string $action,
		IValidatorContainer $container,
		Context $context,
		?string $method = null,
	): bool {
		$path = $this->resolvePath($moduleDir, $module, $action);
		if ($path === null) {
			return false;
		}

		$registrar = require $path;
		if (!is_callable($registrar)) {
			throw new RuntimeException(sprintf(
				'Compiled validator file "%s" must return a callable accepting a ValidatorBuilder; got %s.',
				$path,
				get_debug_type($registrar)
			));
		}

		$registrar(ValidatorBuilder::on($container, $context, $method));
		return true;
	}

	private function resolvePath(string $moduleDir, string $module, string $action): ?string
	{
		$cacheKey = $moduleDir . '|' . $module . '|' . $action;

		if (isset(self::$resolvedPathCache[$cacheKey])) {
			$cached = self::$resolvedPathCache[$cacheKey];
			if ($cached === false) {
				// Trust the memoized "no file" result for the rest of this
				// worker's lifetime -- this is the common case (most actions
				// have no compiled validator file) and is exactly the cost
				// this cache exists to avoid paying on every request.
				return null;
			}
			if (is_file($cached)) {
				return $cached;
			}
			// The previously-resolved file disappeared; fall through to a
			// full re-resolution below.
			unset(self::$resolvedPathCache[$cacheKey]);
		}

		foreach ($this->candidatePaths($moduleDir, $module, $action) as $candidate) {
			if (is_file($candidate)) {
				self::$resolvedPathCache[$cacheKey] = $candidate;
				return $candidate;
			}
		}

		self::$resolvedPathCache[$cacheKey] = false;
		return null;
	}

	/**
	 * Mirrors the XML path's `%core.module_dir%/{module}/Validate/{action}.xml`
	 * convention (see Controller::defineDefaultDirectives()'s
	 * `quiote.validate.path` directive) so a validator file lives right
	 * next to the XML it replaces or supplements.
	 * @return string[] Candidate paths, generated output checked before a
	 *                   hand-written file of the same base name.
	 */
	private function candidatePaths(string $moduleDir, string $module, string $action): array
	{
		$actionPath = str_replace('.', '/', $action);
		$dir = rtrim($moduleDir, '/') . '/' . $module . '/Validate/' . $actionPath;

		return [
			$dir . '.generated.php',
			$dir . '.php',
		];
	}
}
