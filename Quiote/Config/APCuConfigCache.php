<?php

namespace Quiote\Config;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Exception\CacheException;
use Quiote\Routing\Routing;

/**
 * APCu-based configuration cache with warmup for Kubernetes/FrankenPHP deployments
 * This class provides both warmup functionality and drop-in replacement methods
 * for ConfigCache. It combines the benefits of APCu caching with the
 * standard config cache interface.
 * Benefits:
 * - Zero file I/O after warmup
 * - Pre-compiled configurations stored in memory
 * - Routing trees cached and ready
 * - Drop-in replacement for ConfigCache
 * - Uses igbinary for better serialization performance when available
 * @since      1.0.0
 */
class APCuConfigCache extends ConfigCache
{
    /**
     * @var string APCu key prefix for config cache
     */
    private static $configPrefix = 'quiote_config_';
    
    /**
     * @var string APCu key prefix for routing cache
     */
    private static $routingPrefix = 'quiote_routing_';
    
    /**
     * @var string APCu key for compilation metadata
     */
    private static $metaKey = 'quiote_warmup_meta';

    /**
     * @var int Cache TTL (0 = never expire, good for immutable deployments)
     */
    private static $ttl = 0;
    
    /**
     * @var bool Whether APCu is available
     */
    private static $apcuAvailable = null;
    
    /**
     * @var bool Whether igbinary is available for better serialization
     */
    private static $igbinaryAvailable = null;
    
    /**
     * @var string|null Tracks the context for the currently active loadValue() cold compile so that
     * writeCacheFile() (which has no context parameter) can store the value under the correct key.
     */
    private static ?string $pendingContext = null;
    
    
    
    /**
     * Keep the compiled configuration's value in shared memory instead of writing a file.
     *
     * A value, not PHP source: that is the whole difference this class is for. Source would have to be
     * compiled again on every load -- eval()'d code is never opcache-cached -- so an unchanged config
     * would pay a full lex/parse/compile per request, while a stored array is simply fetched. It also
     * means a tampered entry can only produce wrong configuration, never execution.
     *
     * A value shared memory cannot reproduce faithfully ({@see isStorable()}) falls through to the file
     * cache rather than being stored as a broken clone.
     *
     * `%env(...)%` placeholders are resolved before the value is stored, which is the same moment the
     * file cache resolves them: there, the stored artifact holds the placeholder and the environment
     * is read when the file is included, and here the stored entry *is* the loaded value, so it must
     * already be resolved. Either way the environment is read once per process, and a variable
     * changed under a running worker takes effect when that worker is replaced.
     *
     * @param      mixed $value The declaration the handler compiled.
     * @param      ?string $generatedBy The handler class that compiled it; only the file cache records it.
     * @return     void
     */
    #[\Override]
    public static function writeCacheFile(string $config, string $cache, mixed $value, ?string $generatedBy = null): void
    {
        if (self::isAvailable() && self::isStorable($value)) {
            // The pending context set by loadValue()'s cold path, so the key matches the one the
            // fetch will use.
            \apcu_store(
                self::getConfigKey($config, self::$pendingContext),
                EnvPlaceholder::contains($value) ? EnvPlaceholder::resolve($value, $config) : $value,
                self::$ttl
            );
            return;
        }

        parent::writeCacheFile($config, $cache, $value, $generatedBy);
    }

    /**
     * Not a path on this cache: a compiled configuration is a value in shared memory here, so there is
     * no file to hand back and nothing sensible to return.
     *
     * The base implementation's contract -- "returns the path of the compiled cache file" -- cannot be
     * honoured, and returning a path that was never written would fail later as a missing include.
     * {@see loadValue()}, reached through {@see CompiledConfig::value()}, is the read path.
     *
     * @throws     CacheException Always, while APCu is in use.
     */
    #[\Override]
    public static function checkConfig($config, $context = null)
    {
        if (!self::isAvailable()) {
            return parent::checkConfig($config, $context);
        }

        throw new CacheException(sprintf(
            'A compiled configuration is held in shared memory, not in a file, so checkConfig() cannot '
            . 'return a path for "%s". Read the value with CompiledConfig::value() instead.',
            $config
        ));
    }

    /**
     * The value a compiled configuration returns, served from shared memory.
     *
     * The stored entry *is* the value, so a hit costs a fetch. A miss compiles through the inherited
     * {@see ConfigCache::checkConfig()}, whose call to {@see writeCacheFile()} stores it -- and if the
     * value turned out not to be storable, that wrote a file instead, which the fallback below reads.
     *
     * Everything that invalidates a compiled config is already folded into {@see getConfigKey()} --
     * the framework fingerprint, the resolved source format, the context -- and {@see clear()} matches
     * on the shared `quiote_` prefix.
     *
     * @param      string $config An absolute or relative filesystem path to a configuration file.
     * @param      string|null $context An optional context name.
     * @return     mixed The compiled configuration's return value.
     * @since      4.0.0
     */
    #[\Override]
    public static function loadValue(string $config, ?string $context = null): mixed
    {
        if (!self::isAvailable()) {
            return parent::loadValue($config, $context);
        }

        $key = self::getConfigKey($config, $context);

        // The out-parameter, not a false return: a compiled config is entitled to return null or
        // false, and treating that as a miss would recompile it on every single load.
        $found = false;
        $value = \apcu_fetch($key, $found);
        if ($found === true) {
            return $value;
        }

        // Cold path: compile, which stores the value under $key.
        //
        // SAVE/RESTORE (not reset-to-null) of the pending context: compiling a config can trigger
        // nested loads (module.xml initialization, loadConfigHandlersFile(), etc.). Each level must
        // restore the *previous* pending context on exit, or the inner call's finally would clobber the
        // outer context to null -- the outer config would then be stored under context null while its
        // re-fetch uses the real context, missing it every time.
        $previousPendingContext = self::$pendingContext;
        self::$pendingContext = $context;
        try {
            $cacheFile = parent::checkConfig($config, $context);
        } finally {
            self::$pendingContext = $previousPendingContext;
        }

        $value = \apcu_fetch($key, $found);
        if ($found === true) {
            return $value;
        }

        // Not in shared memory: writeCacheFile() decided this value belongs in a file.
        return include $cacheFile;
    }

    /**
     * Whether a compiled config's value round-trips through shared memory unchanged.
     *
     * APCu serializes what it stores, so an object comes back as a different instance and a closure
     * cannot be stored at all. The compiled configs this cache serves are `return <array>;` of scalars
     * by construction, so this should always answer true -- it is here so that a handler that starts
     * emitting something richer degrades to recompiling rather than silently serving a broken clone.
     *
     * @since      4.0.0
     */
    private static function isStorable(mixed $value): bool
    {
        if (is_object($value) || is_resource($value)) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isStorable($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Warm up all configurations and routing data into APCu
     * @param array<int, string> $configs Array of config files to warm up (relative to config_dir)
     * @param string $context The context to warm up for
     * @return array<string, mixed> Warmup statistics
     */
    public static function warmup(array $configs = [], ?string $context = null): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('APCu is not available or enabled');
        }
        
        $stats = [
            'configs_warmed' => 0,
            // Always false: there is no live config-cache handler for routing
            // data (routing is the Routing class's job, not a warmable config
            // file -- see the note on getDefaultConfigs()), so this stat is
            // kept only for backward compatibility with callers that read it.
            'routing_warmed' => false,
            'memory_used' => 0,
            // Monotonic: this only ever measures the duration of this call, never a
            // point in time, so it must not be thrown off by a wall-clock step mid-warmup.
            'start_time' => \Quiote\Support\Clock\Clock::instance()->monotonic(),
            'errors' => []
        ];

        try {
            // Auto-detect common config files if none provided
            if (empty($configs)) {
                $configs = self::getDefaultConfigs();
            }

            $configDir = Config::getString('core.config_dir');

            // Warm up configuration files in the correct dependency order
            foreach ($configs as $config) {
                try {
                    $configPath = self::isAbsolutePath($config) ? $config : $configDir . '/' . $config;
                    if (self::warmupConfig($configPath, $context)) {
                        $stats['configs_warmed']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = "Config {$config}: " . $e->getMessage();
                }
            }

            // Store metadata about this warmup
            $meta = [
                'timestamp' => \Quiote\Support\Clock\Clock::instance()->unixTimestamp(),
                'context' => $context,
                'configs' => $configs,
                'php_version' => PHP_VERSION,
                // Version constant may not be defined in minimal bootstrap contexts
                'quiote_version' => Config::getString('quiote.version', 'unknown')
            ];
            \apcu_store(self::$metaKey, $meta, self::$ttl);
            
        } catch (\Exception $e) {
            $stats['errors'][] = "General: " . $e->getMessage();
        }
        
        $stats['duration'] = \Quiote\Support\Clock\Clock::instance()->monotonic() - $stats['start_time'];
        $stats['memory_used'] = self::getApcuMemoryUsage();
        
        return $stats;
    }
    
    /**
     * Warm up a single configuration file: compile it and put its value in shared memory.
     *
     * Compilation runs with APCu treated as unavailable so it goes through the file cache, then the
     * compiled file is read for its value and the file removed -- warmup exists so the first real
     * request finds shared memory populated, not a file.
     */
    private static function warmupConfig(string $config, ?string $context): bool
    {
        $logger = self::getLoggerFor($context);
        $logger->debug('APCuConfigCache warmupConfig start', ['config' => basename($config), 'context' => $context]);

        $apcuWasAvailable = self::$apcuAvailable;
        self::$apcuAvailable = false;

        try {
            $cacheFile = parent::checkConfig($config, $context);

            if (!is_readable($cacheFile)) {
                $logger->warning('APCuConfigCache warmupConfig cache file not readable', ['config' => basename($config)]);
                return false;
            }

            $value = include $cacheFile;

            if (!self::isStorable($value)) {
                // Leave the compiled file in place: it is the only form this value survives in, and
                // loadValue() falls back to reading it.
                $logger->warning(
                    'APCuConfigCache warmupConfig value cannot live in shared memory; keeping the compiled file',
                    ['config' => basename($config)]
                );
                return false;
            }

            $result = \apcu_store(self::getConfigKey($config, $context), $value, self::$ttl);
            if ($result) {
                $logger->debug('APCuConfigCache warmupConfig stored in APCu', ['config' => basename($config)]);
            } else {
                $logger->warning('APCuConfigCache warmupConfig failed storing in APCu', ['config' => basename($config)]);
                return false;
            }

            if (file_exists($cacheFile)) {
                unlink($cacheFile);
                $logger->debug('APCuConfigCache warmupConfig cleaned temp file', ['config' => basename($config)]);
            }

            return true;
        } finally {
            self::$apcuAvailable = $apcuWasAvailable;
        }
    }

    /**
     * Get APCu memory usage for Quiote keys
     */
    private static function getApcuMemoryUsage(): int
    {
        if (!self::isAvailable()) {
            return 0;
        }
        
        $totalSize = 0;
        if (class_exists('APCUIterator')) {
            $iterator = new \APCUIterator('/^quiote_/');
            
            foreach ($iterator as $value) {
                if (is_array($value) && isset($value['mem_size']) && is_int($value['mem_size'])) {
                    $totalSize += $value['mem_size'];
                }
            }
        }
        
        return $totalSize;
    }
    
    /**
     * Configure APCu cache settings
     * @param array<string, mixed> $options
     */
    public static function configure(array $options): void
    {
        if (isset($options['config_prefix'])) {
            if (!is_string($options['config_prefix'])) {
                throw new \Quiote\Exception\ConfigurationException(sprintf(
                    'The "config_prefix" option must be a string, got %s.',
                    get_debug_type($options['config_prefix'])
                ));
            }
            self::$configPrefix = $options['config_prefix'];
        }

        if (isset($options['routing_prefix'])) {
            if (!is_string($options['routing_prefix'])) {
                throw new \Quiote\Exception\ConfigurationException(sprintf(
                    'The "routing_prefix" option must be a string, got %s.',
                    get_debug_type($options['routing_prefix'])
                ));
            }
            self::$routingPrefix = $options['routing_prefix'];
        }

        if (isset($options['ttl'])) {
            if (!is_int($options['ttl']) && !is_string($options['ttl'])) {
                throw new \Quiote\Exception\ConfigurationException(sprintf(
                    'The "ttl" option must be an int or a numeric string, got %s.',
                    get_debug_type($options['ttl'])
                ));
            }
            self::$ttl = (int)$options['ttl'];
        }
    }
    
    /**
     * Check if APCu is available and enabled
     */
    public static function isAvailable(): bool
    {
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = extension_loaded('apcu') && function_exists('apcu_enabled') && \apcu_enabled();
        }
        return self::$apcuAvailable;
    }
    
    /**
     * Check if igbinary is available for better serialization
     */
    public static function isIgbinaryAvailable(): bool
    {
        if (self::$igbinaryAvailable === null) {
            self::$igbinaryAvailable = extension_loaded('igbinary') && function_exists('igbinary_serialize');
        }
        return self::$igbinaryAvailable;
    }
    
    /**
     * Generate APCu key for config file.
     * Normalizes the config path to a full absolute path so that the key is
     * consistent regardless of whether the caller passes a relative or
     * absolute path (writeCacheFile receives $filename from callHandler which
     * is always the full absolute path).
     * Memoized to avoid repeated normalization and md5() hashing on the hot path.
     */
    /**
     * @var array<string, string>
     */
    private static array $keyCache = [];
    private static function getConfigKey(string $config, ?string $context): string
    {
        // Normalize to full absolute path — mirrors the logic in ConfigCache::checkConfig()
        $normalized = \Quiote\Util\Toolkit::normalizePath($config);
        if (!\Quiote\Util\Toolkit::isPathAbsolute($normalized)) {
            $normalized = \Quiote\Util\Toolkit::normalizePath(Config::getString('core.app_dir')) . '/' . $normalized;
        }
        // Resolve to the actual physical source file (core.config_format /
        // autodetect, see ConfigCache::resolveConfigFormat()) before hashing,
        // the same way ConfigCache::checkConfig() derives its own cache
        // filename from the resolved path rather than the logical one. Without
        // this, switching which format supplies a config (or a new sibling
        // file appearing) would keep serving whatever was first warmed into
        // APCu under this key until TTL expiry, since the APCu-hit fast path
        // never re-checks the filesystem at all.
        $normalized = self::resolveConfigFormat($normalized);
        // The framework fingerprint is part of the key for the same reason it is part of
        // ConfigCache's filename: nothing else invalidates a compiled config when the framework
        // changes, and an APCu hit never re-checks the filesystem at all, so a stale entry here
        // survives until TTL expiry rather than until the next request.
        $cacheKey = $normalized . '|' . ($context ?? '') . '|' . ConfigCache::frameworkFingerprint();
        return self::$keyCache[$cacheKey] ??= self::$configPrefix . md5($cacheKey);
    }
    
    /**
     * Check if APCu cache is warmed up
     */
    public static function isWarmedUp(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        
        $meta = \apcu_fetch(self::$metaKey);
        return $meta !== false;
    }
    
    /**
     * Get default configuration files to warm up in the same order as original Quiote loading
     */
    /**
     * The core config files, in dependency order, that a cold worker will load.
     * Public so the `cache:warmup` command can compile the same set into the
     * on-disk cache for the non-APCu backend (single source of truth).
     * @return array<int, string>
     */
    public static function getDefaultConfigs(): array
    {
        return [
            // Bootstrap phase (from Quiote.php) - these get cached after first load
            'settings.xml',      // First - establishes core settings

            // Context initialization phase (from Context::initialize)
            'factories.xml',     // Creates factories including session storage

            // Controller initialization phase (from Controller::initialize)
            'output_types.xml',  // Defines output types

            // Runtime phase (loaded during execution as needed)
            'databases.xml',     // Database configuration (loaded by DatabaseManager)
            'translation.xml',   // Translation configuration
            'config_handlers.xml' // Config handlers
            // NOTE: compile.xml (dormant -- aggregation removed, see Quiote.php)
            // and routing.xml (no ConfigCache handler in this repo; routing is
            // the Routing class's job, and warmup() handles route data via
            // warmupRouting()) are intentionally omitted -- neither is loadable
            // through the config cache, so warming them only produced errors.
        ];
    }
    
    /**
     * Check if a path is absolute
     */
    private static function isAbsolutePath(string $path): bool
    {
        return isset($path[0]) && ($path[0] === '/' || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:/', $path)));
    }
    
    /**
     * Clear all APCu cached data
     * @return void
     */
    #[\Override]
    public static function clear()
    {
        // Clear APCu cache
        if (self::isAvailable()) {
            self::clearApcu();
        }
        
        // Clear parent file cache (which also forgets which configs load() has applied)
        parent::clear();
    }
    
    /**
     * Clear APCu cached data
     */
    private static function clearApcu(): bool
    {
        // Clear all Quiote-related APCu keys
        if (class_exists('APCUIterator')) {
            $iterator = new \APCUIterator('/^quiote_/');
            $cleared = 0;
            
            foreach ($iterator as $key => $value) {
                if (\apcu_delete($key)) {
                    $cleared++;
                }
            }
        } else {
            // Fallback: try to delete known keys
            $cleared = 0;
            $knownKeys = [self::$metaKey, self::$routingPrefix . 'trie'];
            foreach ($knownKeys as $key) {
                if (\apcu_delete($key)) {
                    $cleared++;
                }
            }
        }
        
        return $cleared > 0;
    }
    
    /**
     * Get warmup status and statistics
     * @return array<string, mixed>
     */
    public static function getStatus(): array
    {
        if (!self::isAvailable()) {
            return ['available' => false];
        }
        
        $meta = \apcu_fetch(self::$metaKey);
        $status = [
            'available' => true,
            'warmed_up' => $meta !== false,
            'memory_usage' => self::getApcuMemoryUsage(),
            'igbinary_available' => self::isIgbinaryAvailable()
        ];
        
        if ($meta !== false) {
            if (!is_array($meta) || !isset($meta['timestamp']) || !is_int($meta['timestamp'])) {
                throw new \Quiote\Exception\CacheException(sprintf(
                    'APCu warmup metadata entry "%s" was expected to be an array with an int "timestamp" key, got %s.',
                    self::$metaKey,
                    get_debug_type($meta)
                ));
            }
            $status = array_merge($status, $meta);
            $status['age_seconds'] = \Quiote\Support\Clock\Clock::instance()->unixTimestamp() - $meta['timestamp'];
        }
        
        return $status;
    }
    
    /**
     * Internal helper to obtain a logger without hard-failing if context/logging not ready.
     * @return \Quiote\Logging\CategoryLogger
     */
    private static function getLoggerFor(?string $context)
    {
        return \Quiote\Logging\Log::create('Quiote.Config.APCuConfigCache');
    }
}