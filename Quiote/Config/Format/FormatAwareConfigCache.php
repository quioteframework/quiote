<?php
namespace Quiote\Config\Format;

use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\XmlConfigHandler;
use Quiote\Exception\UnreadableException;
use Quiote\Util\Toolkit;

/**
 * Extension-agnostic sibling of ConfigCache::checkConfig() (phase 3):
 * given a base path with NO extension, resolves whichever of .php/.yaml/.yml/.xml actually exists
 * (via FormatDriverRegistry::locate(), priority PHP > YAML > XML),
 * compiles it through the given handler's array contract, and reuses
 * ConfigCache's own cache-naming/staleness/write primitives so the
 * compiled artifact is indistinguishable from one ConfigCache produced.
 *
 * Deliberately a separate, opt-in entrypoint rather than a change to
 * ConfigCache::checkConfig()/getHandlerInfo() itself: those are on every
 * config load in the framework, XML included, and wiring
 * extension-agnostic discovery into config_handlers.xml's own pattern
 * matching (so `%core.config_dir%/settings` -- no extension -- becomes
 * the directive every module actually uses) is real follow-on work this
 * class deliberately does not attempt yet. What exists here is the
 * genuinely working, tested resolution + compilation path a caller (or
 * that future config_handlers.xml integration) can already build on.
 * @since      1.0.0
 */
final class FormatAwareConfigCache
{
	/**
	 * @param string $basePathWithoutExtension e.g. "%core.config_dir%/settings"
	 *               (directives are expanded the same way ConfigCache::checkConfig()
	 *               expands them for its own $config argument).
	 * @return string An absolute filesystem path to the compiled cache file.
	 * @throws UnreadableException If none of the candidate extensions exist.
	 */
	public static function checkConfig(
		string $basePathWithoutExtension,
		IArrayConfigHandler&XmlConfigHandler $handler,
		FormatDriverRegistry $registry,
		?string $environment = null,
		?string $context = null,
	): string {
		$basePathWithoutExtension = Toolkit::expandDirectives($basePathWithoutExtension);
		$basePathWithoutExtension = Toolkit::normalizePath($basePathWithoutExtension);
		$base = Toolkit::isPathAbsolute($basePathWithoutExtension)
			? $basePathWithoutExtension
			: Toolkit::normalizePath(Config::getString('core.app_dir')) . '/' . $basePathWithoutExtension;

		$resolved = $registry->locate($base);
		if ($resolved === null) {
			throw new UnreadableException(
				'No config file found for "' . $base . '" (checked .php, .yaml, .yml, .xml).'
			);
		}

		$cache = ConfigCache::getCacheName($resolved, $context);

		if (ConfigCache::isModified($resolved, $cache)) {
			$code = $handler->executeArray(
				$registry->load($resolved, $environment ?? Config::getNullableString('core.environment'), $context),
				$resolved
			);
			ConfigCache::writeCacheFile($resolved, $cache, $code, false);
		}

		return $cache;
	}
}
