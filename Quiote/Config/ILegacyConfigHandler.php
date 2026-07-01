<?php
namespace Quiote\Config;
/**
 * ILegacyConfigHandler is the interface that all old-style config handlers
 * which deal with ConfigValueHolders and parse configs themselves implement.
 * @since      1.0.0
 * @version    1.0.0
 */
interface ILegacyConfigHandler
{
	/**
	 * Initialize this ConfigHandler.
	 * @param      string The path to a validation file for this config handler.
	 * @param      string The parser class to use.
	 * @param      array An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing the
	 *                                                 ConfigHandler
	 * @since      1.0.0
	 */
	public function initialize($validationFile = null, $parser = null, $parameters = []);
	
	/**
	 * Execute this configuration handler.
	 * @param      string An absolute filesystem path to a configuration file.
	 * @param      string Name of the executing context (if any).
	 * @return     string Data to be written to a cache file.
	 * @throws     <b>UnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute($config, $context = null);
}

?>