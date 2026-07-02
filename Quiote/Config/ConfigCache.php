<?php
namespace Quiote\Config;

use Quiote\Context;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Exception\CacheException;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\QuioteException;
use Quiote\Exception\UnreadableException;
use Quiote\Util\Toolkit;

/**
 * ConfigCache allows you to customize the format of a configuration
 * file to make it easy-to-use, yet still provide a PHP formatted result
 * for direct inclusion into your modules.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConfigCache
{
	const CACHE_SUBDIR = 'config';

	/**
	 * @var        array An array of config handler instructions.
	 */
	protected static $handlers = null;

	/**
	 * @var        array A string=>bool array containing config handler files and
	 *                   their loaded status.
	 */
	protected static $handlerFiles = [];

	/**
	 * @var        bool Whether there is an entry in self::$handlerFiles that
	 *                  needs processing.
	 */
	protected static $handlersDirty = true;

	/**
	 * @var        array<string,string> Pre-compiled regex patterns for wildcard config handlers.
	 */
	protected static array $compiledHandlerPatterns = [];

	/**
	 * @var        bool Whether the config handler files have been required.
	 */
	protected static $filesIncluded = false;

	/**
	 * Memoized results of getCacheName() — keyed by "$config|$context".
	 * The cache filename depends only on the source file path, environment and
	 * context — all of which are constant for the lifetime of a worker process.
	 * @var array<string,string>
	 */
	private static array $cacheNameMemo = [];

	/**
	 * Load a configuration handler.
	 * @param      string The path of the originally requested configuration file.
	 * @param      string An absolute filesystem path to a configuration file.
	 * @param      string An absolute filesystem path to the cache file that
	 *                    will be written.
	 * @param      string The context which we're currently running.
	 * @param      array  Optional config handler info array.
	 * @throws     <b>ConfigurationException</b> If a requested configuration
	 *                                                file does not have an
	 *                                                associated config handler.
	 * @since      1.0.0
	 */
	protected static function callHandler($name, $config, $cache, $context, ?array $handlerInfo = null)
	{
		self::setupHandlers();
		
		if(null === $handlerInfo) {
			// we need to load the handlers first
			$handlerInfo = self::getHandlerInfo($name);
		}

		if($handlerInfo === null) {
			// we do not have a registered handler for this file
			$error = 'Configuration file "%s" does not have a registered handler';
			$error = sprintf($error, $name);
			throw new ConfigurationException($error);
		}
		
		$data = self::executeHandler($config, $context, $handlerInfo);
		static::writeCacheFile($config, $cache, $data, false);
	}

	/**
	 * Set up all config handler definitions.
	 * Checks whether the handlers have been loaded or the dirtyHandlers flat is
	 * set, and loads any handler that has not been loaded.
	 * @since        1.0.0
	 */
	protected static function setupHandlers()
	{
		self::loadConfigHandlers();
		
		if(self::$handlersDirty) {
			// set handlersdirty to false, prevent an infinite loop
			self::$handlersDirty = false;
			// load additional config handlers
			foreach(self::$handlerFiles as $filename => &$loaded) {
				if(!$loaded) {
					self::loadConfigHandlersFile($filename);
					$loaded = true;
				}
			}
		}
	}
	
	/**
	 * Fetch the handler information for the given filename.
	 * @param        string The name of the config file (partial path).
	 * @return       array  The handler info.
	 * @since        1.0.0
	 */
	protected static function getHandlerInfo($name)
	{
		// grab the base name of the originally requested config path
		$basename = basename((string) $name);

		$handlerInfo = null;

		if(isset(self::$handlers[$name])) {
			// we have a handler associated with the full configuration path
			$handlerInfo = self::$handlers[$name];
		} elseif(isset(self::$handlers[$basename])) {
			// we have a handler associated with the configuration base name
			$handlerInfo = self::$handlers[$basename];
		} else {
			// let's see if we have any wildcard handlers registered that match
			// this basename
			foreach(self::$handlers as $key => $value)	{
				// Use pre-compiled pattern if available, otherwise compile and cache
				if (!isset(self::$compiledHandlerPatterns[$key])) {
					self::$compiledHandlerPatterns[$key] = sprintf('#%s#', str_replace('\*', '.*?', preg_quote((string) $key, '#')));
				}

				if(preg_match(self::$compiledHandlerPatterns[$key], (string) $name)) {
					$handlerInfo = $value;
					break;
				}
			}
		}
		
		return $handlerInfo;
	}
	
	/**
	 * Execute the config handler for the given file.
	 * @param        string The path to the config file (full path).
	 * @param        string The context which we're currently running.
	 * @param        array  The config handler info.
	 * @return       string The compiled data.
	 * @since        1.0.0
	 */
	protected static function executeHandler($config, $context, array $handlerInfo)
	{
		// call the handler and retrieve the cache data
		$handler = new $handlerInfo['class'];
		if($handler instanceof IXmlConfigHandler) {
			$extension = strtolower(pathinfo((string) $config, PATHINFO_EXTENSION));

			if ($extension !== 'xml' && $extension !== '') {
				// core.config_format / autodetect (see resolveConfigFormat()) resolved
				// this logical config to a non-XML physical file. Only handlers migrated
				// to the array contract (docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2) can
				// be fed from one -- everything else (currently only
				// ValidatorConfigHandler, which has its own separate compiler; see
				// docs/VALIDATOR_COMPILER_PLAN.md) stays XML-only.
				if (!$handler instanceof IArrayConfigHandler) {
					throw new ConfigurationException(sprintf(
						'"%s" does not support non-XML config sources (no IArrayConfigHandler implementation); "%s" cannot be used for it.',
						$handlerInfo['class'],
						$config
					));
				}

				$contextObj = $context !== null ? Context::getInstance($context) : null;
				$handler->initialize($contextObj, $handlerInfo['parameters']);

				try {
					$registry = FormatDriverRegistry::forHandler(
						$handler,
						$handlerInfo['transformations'][XmlConfigParser::STAGE_SINGLE] ?? []
					);
					$canonical = $registry->load($config, Config::get('core.environment'), $context);
					$data = $handler->executeArray($canonical, $config);
				} catch (\Exception $e) {
					throw new $e(sprintf("Compilation of configuration file '%s' failed for the following reason(s):\n\n%s", $config, $e->getMessage()), 0, $e);
				}

				return $data;
			}

			// a new-style config handler
			// it does not parse the config itself; instead, it is given a complete and merged DOM document
			$doc = XmlConfigParser::run($config, Config::get('core.environment'), $context, $handlerInfo['transformations'], $handlerInfo['validations']);

			if($context !== null) {
				$context = Context::getInstance($context);
			}

			$handler->initialize($context, $handlerInfo['parameters']);

			try {
				$data = $handler->execute($doc);
			} catch (\Exception $e) {
				throw new $e(sprintf("Compilation of configuration file '%s' failed for the following reason(s):\n\n%s", $config, $e->getMessage()), 0, $e);
			}
		} else {
			$validationFile = null;
			if(isset($handlerInfo['validations'][XmlConfigParser::STAGE_SINGLE][XmlConfigParser::STEP_TRANSFORMATIONS_AFTER][XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA][0])) {
				$validationFile = $handlerInfo['validations'][XmlConfigParser::STAGE_SINGLE][XmlConfigParser::STEP_TRANSFORMATIONS_AFTER][XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA][0];
			}
			$handler->initialize($validationFile, null, $handlerInfo['parameters']);
			$data = $handler->execute($config, $context);
		}
		
		return $data;
	}
	
	/**
	 * Check to see if a configuration file has been modified and if so
	 * recompile the cache file associated with it.
	 * If the configuration file path is relative, the path itself is relative
	 * to the Quiote "core.app_dir" application setting.
	 * @param      string A filesystem path to a configuration file.
	 * @param      string An optional context name for which the config should be
	 *                    read.
	 * @return     string An absolute filesystem path to the cache filename
	 *                    associated with this specified configuration file.
	 * @throws     <b>UnreadableException</b> If a requested configuration
	 *                                             file does not exist.
	 * @since      1.0.0
	 */
	public static function checkConfig($config, $context = null)
	{
		$config = Toolkit::normalizePath($config);
		// the full filename path to the config, which might not be what we were given.
		$filename = Toolkit::isPathAbsolute($config) ? $config : Toolkit::normalizePath(Config::get('core.app_dir')) . '/' . $config;

		// Extension-agnostic format resolution (docs/CONFIG_SYSTEM_REWRITE_PLAN.md
		// phase 3): $config (the logical name used for handler-pattern lookup
		// below) is untouched -- only the physical file we actually read/cache
		// against can change here.
		$resolvedFilename = self::resolveConfigFormat($filename);

		if(!is_readable($resolvedFilename)) {
			throw new UnreadableException('Configuration file "' . $resolvedFilename . '" does not exist or is unreadable.');
		}

		// The cache name is derived from the resolved (physical) filename, not
		// the logical $config name, so switching which format supplies a given
		// logical config -- an autodetect priority match changing, or
		// core.config_format changing -- can never silently reuse a cache
		// entry compiled from a different source file.
		$cache = self::getCacheName($resolvedFilename, $context);

		if(self::isModified($resolvedFilename, $cache)) {
			// configuration file has changed so we need to reparse it
			self::callHandler($config, $resolvedFilename, $cache, $context);
		}

		return $cache;
	}

	/**
	 * File extensions this class knows how to resolve/compile, in
	 * autodetect priority order (PHP > YAML > XML, per
	 * docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 3).
	 */
	private const CONFIG_FORMAT_EXTENSIONS = [
		'php' => ['php'],
		'yaml' => ['yaml', 'yml'],
		'xml' => ['xml'],
	];

	/**
	 * Resolves the logical config path $filename (as given, typically with
	 * a .xml extension baked into a directive like
	 * "%core.config_dir%/settings.xml") to the actual physical file that
	 * should be read.
	 *
	 * If `core.config_format` is set (one of 'php', 'yaml', 'xml'), that
	 * format is used deterministically -- e.g. with core.config_format =
	 * 'php', "settings.xml" resolves to "settings.php" (or ".yaml"/".yml"
	 * for 'yaml') regardless of whether a "settings.xml" also exists.
	 * Missing the forced format's file is a hard error rather than a
	 * silent fallback, matching how this codebase already treats
	 * configuration ambiguity (e.g. an undefined default database).
	 *
	 * If unset, the first of .php / .yaml / .yml / .xml that actually
	 * exists wins (autodetect). A $filename with no recognized config
	 * extension (or where NONE of the candidate extensions exist on disk)
	 * is returned unchanged, so the caller's normal is_readable() check
	 * produces its usual "does not exist" error.
	 */
	protected static function resolveConfigFormat(string $filename): string
	{
		$base = self::stripKnownConfigExtension($filename);
		if ($base === null) {
			return $filename;
		}

		$format = Config::get('core.config_format');
		if ($format !== null) {
			$extensions = self::CONFIG_FORMAT_EXTENSIONS[$format] ?? null;
			if ($extensions === null) {
				throw new ConfigurationException(sprintf(
					'Unknown core.config_format "%s"; expected one of: %s',
					$format,
					implode(', ', array_keys(self::CONFIG_FORMAT_EXTENSIONS))
				));
			}
			foreach ($extensions as $extension) {
				$candidate = $base . '.' . $extension;
				if (is_file($candidate)) {
					return $candidate;
				}
			}
			throw new UnreadableException(sprintf(
				'core.config_format is set to "%s" but no "%s.{%s}" file exists.',
				$format,
				$base,
				implode(',', $extensions)
			));
		}

		foreach (self::CONFIG_FORMAT_EXTENSIONS as $extensions) {
			foreach ($extensions as $extension) {
				$candidate = $base . '.' . $extension;
				if (is_file($candidate)) {
					return $candidate;
				}
			}
		}

		return $filename;
	}

	/**
	 * @return string|null $filename with its extension removed, or null if
	 *                      it doesn't end in a recognized config extension.
	 */
	private static function stripKnownConfigExtension(string $filename): ?string
	{
		$lower = strtolower($filename);
		foreach (self::CONFIG_FORMAT_EXTENSIONS as $extensions) {
			foreach ($extensions as $extension) {
				if (str_ends_with($lower, '.' . $extension)) {
					return substr($filename, 0, -(strlen($extension) + 1));
				}
			}
		}
		return null;
	}

	/**
	 * Check if the cached version of a file is up to date.
	 * @param      string The source file.
	 * @param      string The name of the cached version.
	 * @return     bool Whether or not the cached file must be updated.
	 * @since      1.0.0
	 */
	/**
	 * @var array<string,bool> Per-process cache of modification check results.
	 * In persistent workers (FrankenPHP), avoids repeated stat() syscalls for
	 * configs that have already been verified as fresh.
	 */
	protected static array $modifiedCache = [];

	public static function isModified($filename, $cachename)
	{
		$cacheKey = $filename . '|' . $cachename;
		if (isset(self::$modifiedCache[$cacheKey])) {
			// Verify cache file still exists — it may have been deleted
			// externally (e.g. Toolkit::clearCache() in debug mode,
			// or between test runs). Still cheaper than the full check
			// (1 stat vs 3).
			if (file_exists($cachename)) {
				return false;
			}
			unset(self::$modifiedCache[$cacheKey]);
		}
		$result = (!is_readable($cachename) || filemtime($filename) > filemtime($cachename));
		if (!$result) {
			// Only memoize 'not modified' — a 'modified' result triggers
			// recompilation, after which the next call should see the new file.
			self::$modifiedCache[$cacheKey] = true;
		}
		return $result;
	}

	/**
	 * Clear all configuration cache files.
	 * @since      1.0.0
	 */
	public static function clear()
	{
		Toolkit::clearCache(self::CACHE_SUBDIR);
		self::$modifiedCache = [];
	}

	/**
	 * Convert a normal filename into a cache filename.
	 * @param      string A normal filename.
	 * @param      string A context name.
	 * @return     string An absolute filesystem path to a cache filename.
	 * @since      1.0.0
	 */
	public static function getCacheName($config, $context = null)
	{
		$memoKey = $config . '|' . $context;
		if (isset(self::$cacheNameMemo[$memoKey])) {
			return self::$cacheNameMemo[$memoKey];
		}

		$environment = Config::get('core.environment');

		if(strlen((string) $config) > 3 && ctype_alpha((string) $config[0]) && $config[1] == ':' && ($config[2] == '\\' || $config[2] == '/')) {
			// file is a windows absolute path, strip off the drive letter
			$config = substr((string) $config, 3);
		}

		// replace unfriendly filename characters with an underscore and postfix the name with a php extension
		// see http://trac.quiote.org/wiki/RFCs/Ticket932 for an explanation how cache names are constructed
		$cacheName = sprintf(
			'%1$s_%2$s.php',
			preg_replace(
				'/[^\w_.-]/i', 
				'_', 
				sprintf(
					'%1$s_%2$s_%3$s', 
					basename((string) $config), 
					$environment, 
					$context
				)
			),
			sha1(
				sprintf(
					'%1$s_%2$s_%3$s',
					$config,
					$environment,
					$context
				)
			)
		);
		
		$baseCacheDir = Config::get('core.cache_dir');
		if(empty($baseCacheDir)) {
			// Fallback to system temp dir when core.cache_dir is not available.
			$baseCacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiote_cache';
		}

		return self::$cacheNameMemo[$memoKey] = $baseCacheDir . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . $cacheName;
	}

	/**
	 * Import a configuration file.
	 * If the configuration file path is relative, the path itself is relative
	 * to the Quiote "core.app_dir" application setting.
	 * @param      string A filesystem path to a configuration file.
	 * @param      string A context name.
	 * @param      bool   Only allow this configuration file to be included once
	 *                    per request?
	 * @since      1.0.0
	 */
	public static function load($config, $context = null, $once = true)
	{
		$cache = self::checkConfig($config, $context);

		if($once) {
			include_once($cache);
		} else {
			include($cache);
		}
	}

	/**
	 * Load all configuration application and module level handlers.
	 * @throws     <b>ConfigurationException</b> If a configuration related
	 *                                                error occurs.
	 * @since      1.0.0
	 */
	protected static function loadConfigHandlers()
	{
		if(self::$handlers !== null) {
			return;
		} else {
			self::$handlers = [];
		}
		
		// some checks first
		if(!defined('LIBXML_DOTTED_VERSION') || (!Config::get('core.ignore_broken_libxml', false) && !version_compare(LIBXML_DOTTED_VERSION, '2.6.16', 'gt'))) {
			throw new QuioteException("A libxml version greater than 2.6.16 is highly recommended. With version 2.6.16 and possibly later releases, validation of XML configuration files will not work and Form Population Filter will eventually fail randomly on some documents due to *severe bugs* in older libxml releases (2.6.16 was released in November 2004, so it is really getting time to update).\n\nIf you still would like to try your luck, disable this message by doing\nQuioteConfig::set('core.ignore_broken_libxml', true);\nand\nQuioteConfig::set('core.skip_config_validation', true);\nbefore calling\nQuiote::bootstrap();\nin index.php (app/config.php is not the right place for this).\n\nBut be advised that you *will* run into segfaults and other sad situations eventually, so what you should really do is upgrade your libxml install.");
		}
		
		$quioteDir = Config::get('core.quiote_dir');
		
		if(!Config::get('core.skip_config_transformations', false)) {
			if(!extension_loaded('xsl')) {
				throw new ConfigurationException("You do not have the XSL extension for PHP (ext/xsl) installed or enabled. The extension is used by Quiote to perform XSL transformations in the configuration system to guarantee forwards compatibility of applications.\n\nIf you do not want to or can not install ext/xsl, you may disable all transformations by setting\nQuioteConfig::set('core.skip_config_transformations', true);\nbefore calling\nQuiote::bootstrap();\nin index.php (app/config.php is not the right place for this because this is a setting that's specific to your environment or machine).\n\nKeep in mind that disabling transformations mean you *have* to use the latest configuration file formats and namespace versions. Also, certain additional configuration file validations implemented via Schematron will not be performed.");
			}
		}
		
		// manually create our config_handlers.xml handler
		self::$handlers['config_handlers.xml'] = [
			'class' => \Quiote\Config\ConfigHandlersConfigHandler::class,
			'parameters' => [
			],
			'transformations' => [
				XmlConfigParser::STAGE_SINGLE => [
					// 0.11 -> 1.0
					$quioteDir . '/Config/xsl/config_handlers.xsl',
					// 1.0 -> 1.0 with ReturnArrayConfigHandler <transformation> for Quiote 1.1
					$quioteDir . '/Config/xsl/config_handlers.xsl',
				],
				XmlConfigParser::STAGE_COMPILATION => [
				],
			],
			'validations' => [
				XmlConfigParser::STAGE_SINGLE => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
					],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
						XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							$quioteDir . '/Config/xsd/config_handlers.xsd',
						],
						XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							$quioteDir . '/Config/sch/config_handlers.sch',
						],
					],
				],
				XmlConfigParser::STAGE_COMPILATION => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => []
				],
			],
		];

		$cfg = Config::get('core.config_dir') . '/config_handlers.xml';
		if(!is_readable($cfg)) {
			$cfg = Config::get('core.system_config_dir') . '/config_handlers.xml';
		}
		// application configuration handlers
		self::loadConfigHandlersFile($cfg);
	}
	
	/**
	 * Load the config handlers from the given config file.
	 * Existing handlers will not be overwritten.
	 * @param      string The path to a config_handlers.xml file.
	 * @since      1.0.0
	 */
	protected static function loadConfigHandlersFile($cfg)
	{
		// Use static::checkConfig() (a forwarding call) rather than the explicit
		// ConfigCache::checkConfig(). The explicit class name is NON-forwarding
		// and resets late static binding to ConfigCache, so when this runs as a
		// side effect of a cold compile under APCuConfigCache, the subsequent
		// writeCacheFile() would resolve to the base (filesystem) implementation and
		// leak a config_handlers cache file to disk even though APCu is enabled.
		// Preserving LSB keeps the whole chain on the APCu store.
		$result = static::checkConfig($cfg);
		if (is_string($result) && str_starts_with($result, 'APCU:')) {
			// APCu hit/cold-store: the marker carries the compiled PHP directly.
			// eval()ing it (after a close-tag prefix) returns the compiled file's
			// return value, i.e. the handlers array, exactly as include() of the
			// equivalent cache file would.
			$loaded = eval('?>' . substr($result, 5));
		} else {
			$loaded = include($result);
		}
		if(is_array($loaded) && isset($loaded['__middleware_config'])) {
			\Quiote\Middleware\MiddlewareCatalog::initialize($loaded['__middleware_config']);
			unset($loaded['__middleware_config']);
		}
		self::$handlers = (array)self::$handlers + (array)$loaded;
	}

	/**
	 * Schedules a config handlers file to be loaded.
	 * @param      string The path to a config_handlers.xml file.
	 * @since      1.0.0
	 */
	public static function addConfigHandlersFile($filename)
	{
		if(!isset(self::$handlerFiles[$filename])) {
			if(!is_readable($filename)) {
				throw new UnreadableException('Configuration file "' . $filename . '" does not exist or is unreadable.');
			}
			
			self::$handlerFiles[$filename] = false;
			self::$handlersDirty = true;
		}
	}

	/**
	 * Write a cache file.
	 * @param      string An absolute filesystem path to a configuration file.
	 * @param      string An absolute filesystem path to the cache file that
	 *                    will be written.
	 * @param      string Data to be written to the cache file.
	 * @param      bool   Should we append the data?
	 * @throws     <b>CacheException</b> If the cache file cannot be written.
	 * @since      1.0.0
	 */
	public static function writeCacheFile($config, $cache, $data, $append = false)
	{
		$baseCacheDir = Config::get('core.cache_dir');
		if(empty($baseCacheDir)) {
			$baseCacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiote_cache';
		}

		$cacheDir = $baseCacheDir . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR;

		// Directory mode: derive from the existing cache dir (or a umask-respecting
		// default). The directory may legitimately be group/other accessible.
		$detectedPerms = @fileperms($baseCacheDir);
		if($detectedPerms === false) {
			$dirPerms = 0777 & ~umask();
		} else {
			$dirPerms = $detectedPerms ^ 0x4000; // strip S_IFDIR, keep the dir's permission bits
		}

		// File mode: cache files are PHP that gets include()'d (and eval()'d on the
		// APCu path), so they must NEVER be group/other-writable — otherwise any
		// local user able to write the cache dir could inject code the web process
		// executes — and need not be executable. Derive an owner-focused mode
		// INDEPENDENT of the (possibly 0777) directory mode. Previously the file
		// inherited the directory's bits via `fileperms ^ 0x4000`, which produced
		// world-writable executable PHP on a 0777 cache dir.
		$filePerms = (0644 & ~umask()) | 0600; // owner can always read/write; never group/other write

		Toolkit::mkdir($cacheDir, $dirPerms);

		if($append && is_readable($cache)) {
			$data = file_get_contents($cache) . $data;
		}

		$tmpName = tempnam($cacheDir, basename((string) $cache));
		if(@file_put_contents($tmpName, $data) !== false) {
			// Set the final, safe mode on the temp file BEFORE publishing it, so the
			// destination never briefly exists with surprising permissions.
			@chmod($tmpName, $filePerms);
			// that worked, but that doesn't mean we're safe yet
			// first, we cannot know if the destination directory really was writeable, as tempnam() falls back to the system temp dir
			// second, with php < 5.2.6 on win32 renaming to an already existing file doesn't work, but copy does
			// so we simply assume that when rename() fails that we are on win32 and try to use copy() followed by unlink()
			// if that also fails, we know something's odd
			if(@rename($tmpName, $cache) || (@copy($tmpName, $cache) && unlink($tmpName))) {
				// alright, it did work after all. chmod() and bail out.
				chmod($cache, $filePerms);
				return;
			}
		}
		
		// still here?
		// that means we could not write the cache file
		$error = 'Failed to write cache file "%s" generated from ' . 'configuration file "%s".';
		$error .= "\n\n";
		$error .= 'Please make sure you have set correct write permissions for directory "%s".';
		$error = sprintf($error, $cache, $config, Config::get('core.cache_dir'));
		throw new CacheException($error);
	}

	/**
     * Parses a config file with the ConfigParser for the extension of the given
     * file.
     * @param      string An absolute filesystem path to a configuration file.
     * @param      bool   Whether the config parser class should be autoloaded if
     *                    the class doesn't exist.
     * @param      string A path to a validation file for this config file.
     * @param      string A class name which specifies an parser to be used.
     * @return     ConfigValueHolder An abstract representation of the
     *                                    config file.
     * @throws     <b>ConfigurationException</b> If the parser for the
     *             extension couldn't be found.
     * @since      1.0.0
     */
    #[\Deprecated(message: <<<'TXT'
    New-style config handlers don't call this method anymore. To be
                 removed in Quiote 1.1
    TXT)]
    public static function parseConfig($config, $autoloadParser = true, $validationFile = null, $parserClass = null)
	{
		$parser = new ConfigParser();

		return $parser->parse($config, $validationFile);
	}
}

?>