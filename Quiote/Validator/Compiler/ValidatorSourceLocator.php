<?php
namespace Quiote\Validator\Compiler;

use Quiote\Config\Config;
use Quiote\Util\Toolkit;

/**
 * Finds validators.xml files on disk, the same way ConfigCache resolves
 * config_handlers.xml's `%core.module_dir%/*&#47;Validate/*.xml` pattern
 * for ValidatorConfigHandler -- except here the result is handed to a
 * compiler, not compiled into the request-time cache.
 *
 * Only "leaf" validator files (the per-action Validate/*.xml, or any
 * explicit path given) need to be discovered: each resolves its own
 * `parent` chain up to the module's and the framework's validator
 * definitions when parsed, exactly as XmlConfigParser::run() already does
 * for the runtime path.
 * @since      1.0.0
 */
class ValidatorSourceLocator
{
	/**
	 * @param iterable<string> $roots Glob patterns, with %directive% tokens
	 *                                (e.g. %core.module_dir%) expanded via
	 *                                Toolkit::expandDirectives().
	 * @return ValidatorSource[] Discovered sources, sorted by path for
	 *                           deterministic ordering.
	 */
	public function discover(iterable $roots): array
	{
		$paths = [];
		foreach ($roots as $pattern) {
			$expanded = (string) Toolkit::expandDirectives($pattern);
			foreach (glob($expanded) ?: [] as $path) {
				if (is_file($path)) {
					$paths[$path] = true;
				}
			}
		}

		$paths = array_keys($paths);
		sort($paths);

		return array_map(static fn(string $path) => new ValidatorSource($path), $paths);
	}

	/**
	 * The pattern config_handlers.xml maps to ValidatorConfigHandler today.
	 * @return string[]
	 */
	public static function defaultRoots(): array
	{
		return [Config::get('core.module_dir') . '/*/Validate/*.xml'];
	}
}
