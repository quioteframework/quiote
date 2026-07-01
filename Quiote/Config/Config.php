<?php
namespace Quiote\Config;
/**
 * Config acts as global registry of quiote related configuration settings
 * @since      1.0.0
 * @version    1.0.0
 */
class Config
{
	/**
	 * @var        array
	 */
	public static $config = [];

	/**
	 * @var        array
	 */
	private static $readonlies = [];

	/**
	 * Get a configuration value.
	 * @param      string The name of the configuration directive.
	 * @return     mixed The value of the directive, or null if not set.
	 * @since      1.0.0
	 */
	public static function get($name, $default = null)
	{
		if(isset(self::$config[$name]) || array_key_exists($name, self::$config)) {
			return self::$config[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Check if a configuration directive has been set.
	 * @param      string The name of the configuration directive.
	 * @return     bool Whether the directive was set.
	 * @since      1.0.0
	 */
	public static function has($name)
	{
		return isset(self::$config[$name]) || array_key_exists($name, self::$config);
	}

	/**
	 * Check if a configuration directive has been set as read-only.
	 * @param      string The name of the configuration directive.
	 * @return     bool Whether the directive is read-only.
	 * @since      1.0.0
	 */
	public static function isReadonly($name)
	{
		return isset(self::$readonlies[$name]);
	}

	/**
	 * Set a configuration value.
	 * @param      string The name of the configuration directive.
	 * @param      mixed  The configuration value.
	 * @param      bool   Whether or not an existing value should be overwritten.
	 * @param      bool   Whether or not this value should be read-only once set.
	 * @return     bool   Whether or not the configuration directive has been set.
	 * @since      1.0.0
	 */
	public static function set($name, $value, $overwrite = true, $readonly = false)
	{
		$retval = false;
		if(($overwrite || !(isset(self::$config[$name]) || array_key_exists($name, self::$config))) && !isset(self::$readonlies[$name])) {
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
	 * @param      string The name of the configuration directive.
	 * @return     bool true, if removed successfully, false otherwise.
	 * @since      1.0.0
	 */
	public static function remove($name)
	{
		$retval = false;
		if((isset(self::$config[$name]) || array_key_exists($name, self::$config)) && !isset(self::$readonlies[$name])) {
			unset(self::$config[$name]);
			$retval = true;
		}
		return $retval;
	}

	/**
	 * Import a list of configuration directives.
	 * @param      array An array of configuration directives.
	 * @since      1.0.0
	 */
	public static function fromArray(array $data)
	{
		// array_merge would reindex numeric keys, so we use the + operator
		// mind the operand order: keys that exist in the left one aren't overridden
		self::$config = self::$readonlies + $data + self::$config;
	}

	/**
	 * Get all configuration directives and values.
	 * @return     array An associative array of configuration values.
	 * @since      1.0.0
	 */
	public static function toArray()
	{
		return self::$config;
	}

	/**
	 * Clear the configuration.
	 * @since      1.0.0
	 */
	public static function clear()
	{
		$restore = array_intersect_assoc(self::$readonlies, self::$config);
		self::$config = $restore;
	}

	/**
	 * Reset configuration state for FrankenPHP worker mode.
	 * This preserves readonly configuration while clearing request-specific config.
	 * @param array $preserveKeys Configuration keys to preserve (in addition to readonly)
	 * @since      1.0.0
	 */
	public static function resetWorkerState(array $preserveKeys = [])
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