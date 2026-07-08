<?php
namespace Quiote\Config;

use Quiote\Exception\ConfigurationException;
use Quiote\Logging\Log;

/**
 * Config acts as global registry of quiote related configuration settings
 * @since      1.0.0
 * @version    1.0.0
 */
class Config
{
	/**
	 * @var        array<string|int, mixed>
	 */
	public static $config = [];

	/**
	 * @var        array<string|int, mixed>
	 */
	private static $readonlies = [];

	/**
	 * Get a configuration value.
	 * Untyped and impossible to check at the call site -- prefer the typed
	 * getString()/getInt()/getFloat()/getBool()/getArray() accessors instead,
	 * which throw when the configuration directive doesn't hold the shape
	 * you expect rather than letting a bad value silently propagate.
	 * @param      string|int $name The name of the configuration directive.
	 * @param      mixed  $default The value to return if the directive is not set.
	 * @return     mixed The value of the directive, or the default if not set.
	 * @deprecated Use getString(), getInt(), getFloat(), getBool() or getArray() instead.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function get(string|int $name, $default = null)
	{
		$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
		Log::create('Quiote.Config.Config')->warning(
			'Config::get("{name}") is untyped; use getString(), getInt(), getFloat(), getBool() or getArray() instead. Called from {file}:{line}',
			[
				'name' => $name,
				'file' => $caller['file'] ?? 'unknown',
				'line' => $caller['line'] ?? 0,
			],
		);
		return self::retrieve($name, $default);
	}

	/**
	 * Look up a configuration value without warning about the untyped access -- used internally
	 * by the typed getters, which perform their own type checking on the result.
	 * @param      string|int $name The name of the configuration directive.
	 * @param      mixed  $default The value to return if the directive is not set.
	 * @return     mixed The value of the directive, or the default if not set.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	private static function retrieve(string|int$name, $default)
	{
		if(isset(self::$config[$name]) || \array_key_exists($name, self::$config)) {
			return self::$config[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Get a configuration value as a string.
	 * Scalars (bool/int/float) are cast to their string representation;
	 * arrays are rejected since there is no sensible string form for them.
	 * @param      string|int  $name The name of the configuration directive.
	 * @param      ?string $default The value to return if the directive is not set.
	 * @return     string The value of the directive, as a string.
	 * @throws     ConfigurationException If the directive is unset with no default given, or holds an array.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getString(string|int $name, ?string $default = null): string
	{
		$value = self::retrieve($name, $default);
		if($value === null) {
			throw new ConfigurationException(\sprintf('Config directive "%s" is not set and no default was given.', $name));
		}
		if(\is_string($value)) {
			return $value;
		}
		if(\is_scalar($value)) {
			return (string) $value;
		}
		throw new ConfigurationException(\sprintf('Config directive "%s" is not convertible to string, got %s.', $name, get_debug_type($value)));
	}

	/**
	 * Get a configuration value as a string, or null if the directive genuinely isn't set.
	 * Unlike getString(), a missing directive is not an error here -- use this for settings
	 * where "unconfigured" is itself a meaningful value (e.g. "no environment override").
	 * @param      string|int $name The name of the configuration directive.
	 * @param      ?string $default The value to return if the directive is not set.
	 * @return     ?string The value of the directive, as a string, or null.
	 * @throws     ConfigurationException If the directive holds a non-scalar value.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getNullableString(string|int $name, ?string $default = null): ?string
	{
		$value = self::retrieve($name, $default);
		if($value === null) {
			return null;
		}
		if(\is_string($value)) {
			return $value;
		}
		if(\is_scalar($value)) {
			return (string) $value;
		}
		throw new ConfigurationException(\sprintf('Config directive "%s" is not convertible to string, got %s.', $name, get_debug_type($value)));
	}

	/**
	 * Get a configuration value as an int.
	 * @template  AsString of bool
	 * @param      string|int   $name The name of the configuration directive.
	 * @param      ?int     $default The value to return if the directive is not set.
	 * @param      AsString $asString Whether to return the value as its string representation instead of an int.
	 * @return     (AsString is true ? string : int)
	 * @throws     ConfigurationException If the directive is unset with no default given, or does not hold an int.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getInt(string|int $name, ?int $default = null, bool $asString = false): int|string
	{
		$value = self::retrieve($name, $default);
		if(!\is_int($value)) {
			throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid int, got %s.', $name, get_debug_type($value)));
		}
		return $asString ? (string) $value : $value;
	}

	/**
	 * Get a configuration value as a float.
	 * An int value is widened to float without complaint.
	 * @template  AsString of bool
	 * @param      string|int   $name The name of the configuration directive.
	 * @param      ?float   $default The value to return if the directive is not set.
	 * @param      AsString $asString Whether to return the value as its string representation instead of a float.
	 * @return     (AsString is true ? string : float)
	 * @throws     ConfigurationException If the directive is unset with no default given, or does not hold a float.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getFloat(string|int $name, ?float $default = null, bool $asString = false): float|string
	{
		$value = self::retrieve($name, $default);
		if(\is_int($value)) {
			$value = (float) $value;
		} elseif(!\is_float($value)) {
			throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid float, got %s.', $name, get_debug_type($value)));
		}
		return $asString ? (string) $value : $value;
	}

	/**
	 * Get a configuration value as a bool.
	 * @param      string|int $name The name of the configuration directive.
	 * @param      bool  $default The value to return if the directive is not set. Defaults to false.
	 * @return     bool The value of the directive.
	 * @throws     ConfigurationException If the directive is set but does not hold a bool.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getBool(string|int $name, bool $default = false): bool
	{
		$value = self::retrieve($name, $default);
		if(!\is_bool($value)) {
			throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid bool, got %s.', $name, get_debug_type($value)));
		}
		return $value;
	}

	/**
	 * Get a configuration value as an array.
	 * @param      string|int             $name The name of the configuration directive.
	 * @param      ?array<mixed>      $default The value to return if the directive is not set.
	 * @return     array<mixed> The value of the directive.
	 * @throws     ConfigurationException If the directive is unset with no default given, or does not hold an array.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getArray(string|int $name, ?array $default = null): array
	{
		$value = self::retrieve($name, $default);
		if(!\is_array($value)) {
			throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid array, got %s.', $name, get_debug_type($value)));
		}
		return $value;
	}

	/**
	 * Get a configuration value that may be configured as either a single string or an
	 * array of strings, normalized to a list. A single string becomes a one-element list;
	 * an unset directive (with no default) becomes an empty list.
	 * @param      string|int        $name The name of the configuration directive.
	 * @param      array<string> $default The value to return if the directive is not set.
	 * @return     array<int, string> The value of the directive, normalized to a list of strings.
	 * @throws     ConfigurationException If the directive holds something other than a string or an array of scalars.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getStringList(string|int $name, array $default = []): array
	{
		$value = self::retrieve($name, $default);
		if($value === null) {
			return [];
		}
		if(\is_string($value)) {
			return $value === '' ? [] : [$value];
		}
		if(\is_array($value)) {
			return array_map(static function ($item) use ($name) {
				if(!\is_scalar($item)) {
					throw new ConfigurationException(\sprintf('Config directive "%s" contains a non-scalar entry, got %s.', $name, get_debug_type($item)));
				}
				return (string) $item;
			}, array_values($value));
		}
		throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid string or array of strings, got %s.', $name, get_debug_type($value)));
	}

	/**
	 * Check if a configuration directive has been set.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool Whether the directive was set.
	 * @since      1.0.0
	 */
	public static function has(string|int $name): bool
	{
		return isset(self::$config[$name]) || \array_key_exists($name, self::$config);
	}

	/**
	 * Check if a configuration directive has been set as read-only.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool Whether the directive is read-only.
	 * @since      1.0.0
	 */
	public static function isReadonly(string|int $name): bool
	{
		return isset(self::$readonlies[$name]);
	}

	/**
	 * Set a configuration value.
	 * @param      string $name The name of the configuration directive.
	 * @param      mixed  $value The configuration value.
	 * @param      bool   $overwrite Whether or not an existing value should be overwritten.
	 * @param      bool   $readonly Whether or not this value should be read-only once set.
	 * @return     bool   Whether or not the configuration directive has been set.
	 * @since      1.0.0
	 */
	public static function set(string|int $name, $value, bool $overwrite = true, bool $readonly = false): bool
	{
		$retval = false;
		if(($overwrite || !(isset(self::$config[$name]) || \array_key_exists($name, self::$config))) && !isset(self::$readonlies[$name])) {
			self::$config[$name] = $value;
			if($readonly) {
				self::$readonlies[$name] = $value;
			}
			$retval = true;
		}
		return $retval;
	}

	/**
	 * Remove a configuration value.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool true, if removed successfully, false otherwise.
	 * @since      1.0.0
	 */
	public static function remove(string|int $name): bool
	{
		$retval = false;
		if((isset(self::$config[$name]) || \array_key_exists($name, self::$config)) && !isset(self::$readonlies[$name])) {
			unset(self::$config[$name]);
			$retval = true;
		}
		return $retval;
	}

	/**
	 * Import a list of configuration directives.
	 * @param      array<string|int, mixed> $data An array of configuration directives.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function fromArray(array $data): void
	{
		// array_merge would reindex numeric keys, so we use the + operator
		// mind the operand order: keys that exist in the left one aren't overridden
		self::$config = self::$readonlies + $data + self::$config;
	}

	/**
	 * Get all configuration directives and values.
	 * @return     array<string|int, mixed> An associative array of configuration values.
	 * @since      1.0.0
	 */
	public static function toArray(): array
	{
		return self::$config;
	}

	/**
	 * Clear the configuration.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function clear(): void
	{
		$restore = array_intersect_assoc(self::$readonlies, self::$config);
		self::$config = $restore;
	}

	/**
	 * Reset configuration state for FrankenPHP worker mode.
	 * This preserves readonly configuration while clearing request-specific config.
	 * @param array<int, string> $preserveKeys Configuration keys to preserve (in addition to readonly)
	 * @return     void
	 * @since      1.0.0
	 */
	public static function resetWorkerState(array $preserveKeys = []): void
	{
		// Preserve readonly config and specified keys
		$preserve = [];

		// Keep readonly config
		foreach (self::$readonlies as $key => $dummy) {
			if (isset(self::$config[$key])) {
				$preserve[$key] = self::$config[$key];
			}
		}

		// Keep explicitly preserved keys
		foreach ($preserveKeys as $key) {
			if ($key === 'modules') {
				// Special handling for modules: preserve all module.* configurations
				foreach (self::$config as $configKey => $configValue) {
					if (str_starts_with((string) $configKey, 'modules.')) {
						$preserve[$configKey] = $configValue;
					}
				}
			} elseif (isset(self::$config[$key])) {
				$preserve[$key] = self::$config[$key];
			}
		}

		// Reset config to preserved values
		self::$config = $preserve;
	}
}
?>