<?php
namespace Quiote\Config;

use Quiote\Logging\Log;

/**
 * Static facade over the application's configuration.
 *
 * The behaviour lives in {@see ConfigRepository}; this is the process-wide entry point every
 * call site already uses. Code that can accept a collaborator should take a ConfigRepository
 * instead -- it is injectable, swappable and testable in isolation -- and reach for this only
 * where threading one through is not practical.
 *
 * @since      1.0.0
 * @version    1.0.0
 */
class Config
{
	/**
	 * The repository every static call delegates to.
	 */
	private static ?ConfigRepository $repository = null;

	/**
	 * Directives that have already logged the get() deprecation warning.
	 * Keyed by directive rather than call site so a repeat get() for the
	 * same directive skips debug_backtrace() entirely (not just the log
	 * write) -- any app/plugin still on get() pays this at most once per
	 * directive per process instead of on every single call.
	 * @var        array<string|int, true>
	 */
	private static array $warnedGetDirectives = [];

	/**
	 * The repository backing the facade, created on first use.
	 */
	public static function repository(): ConfigRepository
	{
		return self::$repository ??= new ConfigRepository();
	}

	/**
	 * Install a repository for the facade to delegate to.
	 *
	 * The seam for a test that needs a configuration of its own, and for embedding code that
	 * builds its configuration separately. Pass null to drop the current one, so the next
	 * access starts from an empty repository.
	 *
	 * @return     ?ConfigRepository The repository that was installed before this call, so a
	 *             caller can restore it.
	 * @since      3.2.0
	 */
	public static function useRepository(?ConfigRepository $repository): ?ConfigRepository
	{
		$previous = self::$repository;
		self::$repository = $repository;

		return $previous;
	}

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
		if (!isset(self::$warnedGetDirectives[$name])) {
			self::$warnedGetDirectives[$name] = true;
			$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
			Log::create('Quiote.Config.Config')->warning(
				'Config::get("{name}") is untyped; use getString(), getInt(), getFloat(), getBool() or getArray() instead. Called from {file}:{line}',
				[
					'name' => $name,
					'file' => $caller['file'] ?? 'unknown',
					'line' => $caller['line'] ?? 0,
				],
			);
		}
		return self::repository()->get($name, $default);
	}

	/**
	 * Get a configuration value as a string.
	 * Scalars (bool/int/float) are cast to their string representation;
	 * arrays are rejected since there is no sensible string form for them.
	 * @param      string|int  $name The name of the configuration directive.
	 * @param      ?string $default The value to return if the directive is not set.
	 * @return     string The value of the directive, as a string.
	 * @throws     \Quiote\Exception\ConfigurationException If the directive is unset with no default given, or holds an array.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getString(string|int $name, ?string $default = null): string
	{
		return self::repository()->getString($name, $default);
	}

	/**
	 * Get a configuration value as a string, or null if the directive genuinely isn't set.
	 * Unlike getString(), a missing directive is not an error here -- use this for settings
	 * where "unconfigured" is itself a meaningful value (e.g. "no environment override").
	 * @param      string|int $name The name of the configuration directive.
	 * @param      ?string $default The value to return if the directive is not set.
	 * @return     ?string The value of the directive, as a string, or null.
	 * @throws     \Quiote\Exception\ConfigurationException If the directive holds a non-scalar value.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getNullableString(string|int $name, ?string $default = null): ?string
	{
		return self::repository()->getNullableString($name, $default);
	}

	/**
	 * Get a configuration value as an int.
	 * @template  AsString of bool
	 * @param      string|int   $name The name of the configuration directive.
	 * @param      ?int     $default The value to return if the directive is not set.
	 * @param      AsString $asString Whether to return the value as its string representation instead of an int.
	 * @return     (AsString is true ? string : int)
	 * @throws     \Quiote\Exception\ConfigurationException If the directive is unset with no default given, or does not hold an int.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getInt(string|int $name, ?int $default = null, bool $asString = false): int|string
	{
		$value = self::repository()->getInt($name, $default);

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
	 * @throws     \Quiote\Exception\ConfigurationException If the directive is unset with no default given, or does not hold a float.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getFloat(string|int $name, ?float $default = null, bool $asString = false): float|string
	{
		$value = self::repository()->getFloat($name, $default);

		return $asString ? (string) $value : $value;
	}

	/**
	 * Get a configuration value as a bool.
	 * @param      string|int $name The name of the configuration directive.
	 * @param      bool  $default The value to return if the directive is not set. Defaults to false.
	 * @return     bool The value of the directive.
	 * @throws     \Quiote\Exception\ConfigurationException If the directive is set but does not hold a bool.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getBool(string|int $name, bool $default = false): bool
	{
		return self::repository()->getBool($name, $default);
	}

	/**
	 * Get a configuration value as an array.
	 * @param      string|int             $name The name of the configuration directive.
	 * @param      ?array<mixed>      $default The value to return if the directive is not set.
	 * @return     array<mixed> The value of the directive.
	 * @throws     \Quiote\Exception\ConfigurationException If the directive is unset with no default given, or does not hold an array.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getArray(string|int $name, ?array $default = null): array
	{
		return self::repository()->getArray($name, $default);
	}

	/**
	 * Get a configuration value that may be configured as either a single string or an
	 * array of strings, normalized to a list. A single string becomes a one-element list;
	 * an unset directive (with no default) becomes an empty list.
	 * @param      string|int        $name The name of the configuration directive.
	 * @param      array<string> $default The value to return if the directive is not set.
	 * @return     array<int, string> The value of the directive, normalized to a list of strings.
	 * @throws     \Quiote\Exception\ConfigurationException If the directive holds something other than a string or an array of scalars.
	 * @since      1.0.0
	 * @phpstan-impure
	 */
	public static function getStringList(string|int $name, array $default = []): array
	{
		return self::repository()->getStringList($name, $default);
	}

	/**
	 * Check if a configuration directive has been set.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool Whether the directive was set.
	 * @since      1.0.0
	 */
	public static function has(string|int $name): bool
	{
		return self::repository()->has($name);
	}

	/**
	 * Check if a configuration directive has been set as read-only.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool Whether the directive is read-only.
	 * @since      1.0.0
	 */
	public static function isReadonly(string|int $name): bool
	{
		return self::repository()->isReadonly($name);
	}

	/**
	 * Set a configuration value.
	 * @param      string|int $name The name of the configuration directive.
	 * @param      mixed  $value The configuration value.
	 * @param      bool   $overwrite Whether or not an existing value should be overwritten.
	 * @param      bool   $readonly Whether or not this value should be read-only once set.
	 * @return     bool   Whether or not the configuration directive has been set.
	 * @since      1.0.0
	 */
	public static function set(string|int $name, $value, bool $overwrite = true, bool $readonly = false): bool
	{
		return self::repository()->set($name, $value, $overwrite, $readonly);
	}

	/**
	 * Remove a configuration value.
	 * @param      string|int $name The name of the configuration directive.
	 * @return     bool true, if removed successfully, false otherwise.
	 * @since      1.0.0
	 */
	public static function remove(string|int $name): bool
	{
		return self::repository()->remove($name);
	}

	/**
	 * Import a list of configuration directives.
	 * @param      array<string|int, mixed> $data An array of configuration directives.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function fromArray(array $data): void
	{
		self::repository()->fromArray($data);
	}

	/**
	 * Get all configuration directives and values.
	 * @return     array<string|int, mixed> An associative array of configuration values.
	 * @since      1.0.0
	 */
	public static function toArray(): array
	{
		return self::repository()->toArray();
	}

	/**
	 * Clear the configuration.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function clear(): void
	{
		self::repository()->clear();
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
		self::repository()->resetWorkerState($preserveKeys);
	}
}
