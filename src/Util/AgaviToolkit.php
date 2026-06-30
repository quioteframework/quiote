<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Util;

use Agavi\Config\AgaviConfig;
use Agavi\Exception\AgaviException;

/**
 * AgaviToolkit provides basic utility methods.
 *
 * @package    agavi
 * @subpackage util
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
final class AgaviToolkit
{
	/**
	 * Determine if a filesystem path is absolute.
	 *
	 * @param      path A filesystem path.
	 *
	 * @return     bool true, if the path is absolute, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public static function isPathAbsolute($path)
	{
		if(str_starts_with((string) $path, "file://")) {
			$path = substr((string) $path, 7);
		}
		
		if($path[0] == '/' || str_starts_with((string) $path, '\\\\') ||
			(
				strlen((string) $path) >= 3 && ctype_alpha((string) $path[0]) &&
				$path[1] == ':' &&
				($path[2] == '\\' || $path[2] == '/')
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Normalizes a path to contain only '/' as path delimiter.
	 *
	 * @param      string The path to normalize.
	 *
	 * @return     string The unified bool The mkdir return value.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function normalizePath($path)
	{
		return str_replace('\\', '/', $path);
	}

	/**
	 * Creates a directory without sucking at permissions.
	 * PHP mkdir() doesn't do what you tell it to, it takes umask into account.
	 *
	 * @param      string   The path name.
	 * @param      int      The mode. Really. Defaults to 0775.
	 * @param      bool     Recursive or not.
	 * @param      resource A Context.
	 *
	 * @return     bool The mkdir return value.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function mkdir($path, $mode = 0775, $recursive = false, $context = null)
	{
		$retval = is_dir($path);
		if(!$retval) {
			if($context !== null) {
				$retval = @mkdir($path, $mode, $recursive, $context);
			} else {
				$retval = @mkdir($path, $mode, $recursive);
			}
			if($retval) {
				chmod($path, $mode);
			}
		}
		return $retval;
	}

	/**
	 * Returns the base for two strings (the part at the beginning of both which
	 * is equal)
	 *
	 * @param      string The base string.
	 * @param      string The string which should be compared to the base string.
	 * @param      int    The number of characters which are equal.
	 *
	 * @return     string The equal part at the beginning of both strings.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function stringBase($baseString, $compString, &$equalAmount = 0)
	{
		$baseString = (string) $baseString;
		$compString = (string) $compString;
		$equalAmount = 0;
		$maxEqualAmount = min(strlen($baseString), strlen($compString));
		while ($equalAmount < $maxEqualAmount && $baseString[$equalAmount] === $compString[$equalAmount]) {
			$equalAmount++;
		}
		return substr($baseString, 0, $equalAmount);
	}

	/**
	 * Deletes a specified path in the cache dir recursively. If a folder is given
	 * the contents of this folder and all sub-folders get erased, but not the
	 * folder itself.
	 *
	 * @param      string The path to remove
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function clearCache($path = '')
	{
		$baseCache = AgaviConfig::get('core.cache_dir');
		if(empty($baseCache)) {
			// Tests (and some code paths) may clear AgaviConfig during setup; use
			// a safe fallback to the system temp directory to avoid throwing and
			// potentially wiping the system root. This mirrors fallbacks elsewhere
			// in the codebase (AgaviConfigCache/CacheManager).
			$baseCache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'agavi_cache';
		}
		
		$ignores = ['.', '..', '.svn', 'CVS', '_darcs', '.arch-params', '.monotone', '.bzr', '.gitignore', '.gitkeep'];
		$path = str_replace('/', DIRECTORY_SEPARATOR, str_replace('\\', DIRECTORY_SEPARATOR, $path));
		$path = realpath($baseCache . DIRECTORY_SEPARATOR . $path);
		if($path === false) {
			return false;
		}
		if(is_file($path)) {
			@unlink($path);
		} else {
			try {
				foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::CHILD_FIRST) as $iterator) {
					// omg, thanks spl for always using forward slashes ... even on windows
					$pathname = str_replace('/', DIRECTORY_SEPARATOR, str_replace('\\', DIRECTORY_SEPARATOR, $iterator->getPathname()));
					$continue = false;
					if(in_array($iterator->getFilename(), $ignores)) {
						$continue = true;
					} else {
						foreach($ignores as $ignore) {
							if(str_contains($pathname, DIRECTORY_SEPARATOR . $ignore . DIRECTORY_SEPARATOR)) {
								$continue = true;
								break;
							} elseif(strrpos($pathname, DIRECTORY_SEPARATOR . $ignore) == (strlen($pathname) - strlen(DIRECTORY_SEPARATOR . $ignore))) {
								// if we hit the directory itself it wont include a trailing /
								$continue = true;
								break;
							}
						}
					}
					if($continue) {
						continue;
					}
					if($iterator->isDir()) {
						@rmdir($pathname);
					} elseif($iterator->isFile()) {
						@unlink($pathname);
					}
				}
			} catch(\Exception) {
				// ignore all exceptions in case the path didn't exist anymore
			}
		}
	}

	/**
	 * Returns the method from the given definition list matching the given
	 * parameters.
	 *
	 * @param      array  The definitions of the functions.
	 * @param      array  The parameters which were passed to the function.
	 *
	 * @return     string The name of the function which matched.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function overloadHelper(array $definitions, array $parameters)
	{
		$countedDefs = [];
		foreach($definitions as $def) {
			$countedDefs[count($def['parameters'])][] = $def;
		}

		$paramCount = count($parameters);
		if(!isset($countedDefs[$paramCount])) {
			throw new AgaviException('overloadhelper couldn\'t find a matching method with the parameter count ' . $paramCount);
		}
		if(count($countedDefs[$paramCount]) > 1) {
			$matchCount = 0;
			$matchIndex = null;
			foreach($countedDefs[$paramCount] as $key => $paramDef) {
				$success = true;
				for($i = 0; $i < $paramCount; ++$i) {
					if(!str_starts_with(gettype($parameters[$i]), (string) $paramDef['parameters'][$i])) {
						$success = false;
						break;
					}
				}
				if($success) {
					++$matchCount;
					$matchIndex = $key;
				}
			}
			if($matchCount == 0) {
				throw new AgaviException('overloadhelper couldn\'t find a matching method');
			} elseif($matchCount > 1) {
				throw new AgaviException('overloadhelper found ' . $matchCount . ' matching methods');
			}
			return $countedDefs[$paramCount][$key]['name'];
		} else {
			return $countedDefs[$paramCount][0]['name'];
		}
	}

	/**
	 * Expand variables in a string.
	 *
	 * Variables can be in the form $foo, ${foo} or {$foo}.
	 *
	 * @param      string The format string.
	 * @param      array  The variables to use.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function expandVariables($string, array $arguments = [])
	{
		// replacing the other two forms is faster than using three different search values in the str_replace
		// also, if we had three search patterns, ${foo} with an argument {foo} would be replaced...
		$string = preg_replace(
			'/((\{\$)|\$)([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)(?(2)\}|)/',
			'${$3}',
			(string) $string
		);
		$search = [];
		foreach($arguments as $key => $value) {
			$search[] = '${' . $key . '}';
		}
		return str_replace($search, $arguments, $string);
	}
	
	/**
	 * Literalize a string value.
	 *
	 * @param      string The value to literalize.
	 *
	 * @return     string A literalized value.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	private static array $boolLiterals = [
		'on' => true, 'yes' => true, 'true' => true,
		'off' => false, 'no' => false, 'false' => false,
	];

	public static function literalize($value)
	{
		if(!is_string($value)) {
			return $value;
		}

		// trim!
		$value = trim($value);
		if($value === '') {
			return null;
		}

		// lookup table instead of chained comparisons
		$lvalue = strtolower($value);
		if(isset(self::$boolLiterals[$lvalue])) {
			return self::$boolLiterals[$lvalue];
		}

		if(!is_numeric($value)) {
			return self::expandDirectives($value);
		}

		// numeric value, remains a string on purpose (for BC)
		return $value;
	}
	
	/**
	 * Replace configuration directive identifiers in a string.
	 *
	 * @param      string The value on which to run the replacement procedure.
	 *
	 * @return     string The new value.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function expandDirectives($value): array|string|null
	{
		if ($value === null) {
			return null;
		}
		// Fast path: skip regex entirely when there are no directives to expand
		if (!str_contains((string) $value, '%')) {
			return $value;
		}
		do {
			$oldvalue = $value;
			$value = preg_replace_callback(
				'/\%([\w\.]+?)\%/',
				fn($matches) => AgaviConfig::get($matches[1], '%' . $matches[1] . '%'),
				(string) $value
			);
		} while($oldvalue !== $value);

		return $value;
	}
	
	/**
	 * This function takes the numerator and divides it through the denominator while
	 * storing the remainder and returning the quotient.
	 *
	 * @param      float The numerator.
	 * @param      int   The denominator.
	 * @param      int   The remainder.
	 *
	 * @return     int   The floored quotient.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function floorDivide($numerator, $denominator, &$remainder)
	{
		if((int)$denominator != $denominator) {
			throw new AgaviException('AgaviToolkit::floorDivive works only for int denominators');
		}
		$quotient = floor($numerator / $denominator);
		$remainder = (int) ($numerator - ($quotient * $denominator));

		return $quotient;
	}
	
	/**
	 * Determines whether a port declaration is necessary in a URL authority.
	 *
	 * @param      string The scheme (protocol identifier).
	 * @param      int    The port.
	 *
	 * @return     bool True, if port must be included, otherwise false.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function isPortNecessary($scheme, $port)
	{
		static $protocolList = [
			'ftp' => 21,
			'ssh' => 22,
			'telnet' => 23,
			'gopher' => 70,
			'http' => 80,
			'nttp' => 119,
			'https' => 443,
			'mms' => 1755,
		];
		if(isset($protocolList[$scheme = strtolower((string) $scheme)]) && $protocolList[$scheme] === $port) {
			return false;
		}
		return true;
	}
	
	/**
	 * Tries to grab a value from the given array using the given list of keys.
	 *
	 * @param      array The array to search in.
	 * @param      array The list of keys.
	 * @param      mixed A default return value, defaults to null.
	 *
	 * @return     mixed The found value, or the default value if nothing found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function getValueByKeyList(array $array, array $keys, $default = null)
	{
		foreach($keys as $key) {
			if(isset($array[$key])) {
				return $array[$key];
			}
		}
		return $default;
	}

	/**
	 * Checks if a value is not an array
	 *
	 * @param      mixed The value to check
	 *
	 * @return     bool The result.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function isNotArray($value)
	{
		return !is_array($value);
	}
	
	/**
	 * Generate a proper unique ID.
	 *
	 * Uses PHP's uniqid(), but forces use of additional entropy. Without, it's
	 * just the microtime in hex, and much slower than with entropy on Linux.
	 *
	 * @param      string An optional prefix
	 * @return     string A properly unique ID
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.1
	 */
	public static function uniqid($prefix = '')
	{
		return uniqid((string) $prefix, true);
	}
	
	/**
	 * Returns the canonical name for a dot-separated view/action/model name.
	 * This method is idempotent.
	 *
	 * @param      string The view/action/model name.
	 *
	 * @return     string The canonical name.
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	public static function canonicalName($name)
	{
		return str_replace('.', '/', $name);
	}
	
	/**
	 * Evaluates a given AgaviConfig per-module directive using the given info.
	 *
	 * @param      string The name of the module
	 * @param      string The relevant name fragment of the directive
	 * @param      array  The variables to expand in the directive value.
	 *
	 * @return     string The final value
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	/**
	 * @var array<string,string> Cache for expanded module directives (without variables).
	 * The config value + expandDirectives result is constant for the process lifetime.
	 */
	private static array $moduleDirectiveCache = [];

	public static function evaluateModuleDirective($moduleName, $directiveNameFragment, $variables = [])
	{
		$cacheKey = strtolower((string) $moduleName) . '|' . $directiveNameFragment;
		if (!isset(self::$moduleDirectiveCache[$cacheKey])) {
			$rawDirective = AgaviConfig::get(
				sprintf(
					'modules.%s.%s',
					strtolower((string) $moduleName),
					$directiveNameFragment
				)
			);
			// Do NOT cache an unresolved directive. The module's config (which
			// populates modules.<module>.<directive>) may not have been loaded yet
			// when this is first called — e.g. a view-name lookup for a forwarded
			// system action before that module was initialized. Caching the null
			// would poison every later lookup for the lifetime of the process
			// (the cache is static), making callers fall back to the raw,
			// unqualified view name and resolve the wrong (empty) view. Resolve
			// on the fly until a non-null value is available, then memoize that.
			if ($rawDirective === null) {
				return AgaviToolkit::expandVariables(
					AgaviToolkit::expandDirectives($rawDirective),
					$variables
				);
			}
			self::$moduleDirectiveCache[$cacheKey] = AgaviToolkit::expandDirectives($rawDirective);
		}
		return AgaviToolkit::expandVariables(
			self::$moduleDirectiveCache[$cacheKey],
			$variables
		);
	}
	
	/**
	 * Counterpart of PHP's parse_url().
	 * 
	 * @param      array $parts The parts of the URL as defined by parse_url()
	 * @return     string
	 * 
	 * @author     Thomas Bachem <mail@thomasbachem.com>
	 */
	public static function buildUrl(array $parts)
	{
		$url = '';
		if(isset($parts['host']) && strlen($parts['host'])) {
			if(isset($parts['scheme'])) {
				$url .= $parts['scheme'] . ':';
			}
			$url .= '//';
			if(isset($parts['user'])) {
				$url .= $parts['user'];
				if(isset($parts['pass'])) {
					$url .= ':' . $parts['pass'];
				}
				$url .= '@';
			}
			$url .= $parts['host'];
			if(isset($parts['port'])) {
				$url .= ':' . $parts['port'];
			}
		}
		$url .= '/';
		if(isset($parts['path']) && strlen($parts['path'])) {
			$url .= $parts['path'][0] === '/' ? substr($parts['path'], 1) : $parts['path'];
		}
		if(isset($parts['query']) && strlen($parts['query'])) {
			$url .= '?' . $parts['query'];
		}
		if(isset($parts['fragment']) && strlen($parts['fragment'])) {
			$url .= '#' . $parts['fragment'];
		}
		return $url;
	}
}

?>