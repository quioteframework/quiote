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
namespace Agavi\Database;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Exception\AgaviDatabaseException;

/**
 * AgaviDatabaseManager allows you to setup your database connectivity before 
 * the request is handled. This eliminates the need for a filter to manage 
 * database connections.
 *
 * @package    agavi
 * @subpackage database
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @author     Sean Kerr <skerr@mojavi.org>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviDatabaseManager
{
	/**
	 * @var        string The name of the default database.
	 */
	protected $defaultDatabaseName = null;
	
	/**
	 * @var        array An array of AgaviDatabases.
	 */
	protected $databases = [];

	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext The current AgaviContext instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the database connection associated with this Database
	 * implementation.
	 *
	 * @param      string A database name.
	 *
	 * @return     mixed A AgaviDatabase instance.
	 *
	 * @throws     <b>AgaviDatabaseException</b> If the requested database name
	 *                                           does not exist.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getDatabase($name = null)
	{
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();
		if($name === null) {
			$name = $this->defaultDatabaseName;
		}
		
		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] getDatabase(' . $name . ') - manager_id=' . spl_object_id($this));
		}
		
		if(isset($this->databases[$name])) {
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug('[AgaviDatabaseManager] returning existing database: ' . $name . ' id=' . spl_object_id($this->databases[$name]));
			}
			return $this->databases[$name];
		}

		// nonexistent database name
		$error = 'Database "%s" does not exist';
		$error = sprintf($error, $name);
		throw new AgaviDatabaseException($error);
	}
	
	/**
	 * Retrieve the name of the given database instance.
	 *
	 * @param      AgaviDatabase The database to fetch the name of.
	 *
	 * @return     string The name of the database, or false if it was not found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getDatabaseName(AgaviDatabase $database)
	{
		return array_search($database, $this->databases, true);
	}

	/**
	 * Returns the name of the default database.
	 *
	 * @return     string The name of the default database.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getDefaultDatabaseName()
	{
		return $this->defaultDatabaseName;
	}

	/**
	 * Initialize this DatabaseManager.
	 *
	 * @param      AgaviContext An AgaviContext instance.
	 * @param      array        An array of initialization parameters.
	 *
	 * @throws     <b>AgaviInitializationException</b> If an error occurs while
	 *                                                 initializing this 
	 *                                                 DatabaseManager.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();

		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] initialize() called - id=' . spl_object_id($this));
		}
		
		$this->context = $context;

		// load database configuration
		if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
			$cacheResult = AgaviAPCuConfigCache::checkConfig(AgaviConfig::get('core.config_dir') . '/databases.xml');
			if (!str_starts_with($cacheResult, 'APCU:')) {
				require($cacheResult);
			}
		} else {
			require(AgaviConfigCache::checkConfig(AgaviConfig::get('core.config_dir') . '/databases.xml'));
		}
		
		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] initialize() completed - databases loaded: ' . implode(', ', array_keys($this->databases)));
		}
	}

	/**
	 * Do any necessary startup work after initialization.
	 *
	 * This method is not called directly after initialize().
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function startup()
	{
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();

		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] startup() called - id=' . spl_object_id($this) . ' databases=' . count($this->databases));
		}
		
		foreach($this->databases as $name => $database) {
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug('[AgaviDatabaseManager] starting up database: ' . $name . ' id=' . spl_object_id($database));
			}
			$database->startup();
		}
		
		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] startup() completed');
		}
	}

	/**
	 * Execute the shutdown procedure.
	 *
	 * @throws     <b>AgaviDatabaseException</b> If an error occurs while shutting
	 *                                           down this DatabaseManager.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function shutdown()
	{
        $logger = $this->getContext()?->getLoggerManager()?->getlogger();

		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] shutdown() called - id=' . spl_object_id($this) . ' databases=' . count($this->databases));
		}
		
		// loop through databases and shutdown connections
		foreach($this->databases as $name => $database) {
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug('[AgaviDatabaseManager] shutting down database: ' . $name . ' id=' . spl_object_id($database));
			}
			$database->shutdown();
		}
		
		// Close Propel static connections to prevent stale connection reuse in worker mode
		if (class_exists('\Propel\Propel', false)) {
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug('[AgaviDatabaseManager] closing Propel static connections');
			}
			\Propel\Propel::close();
		}
		
		if (\Agavi\Util\DebugFlags::$database) {
			$logger?->debug('[AgaviDatabaseManager] shutdown() completed');
		}
	}

	/**
	 * Probe all managed database connections and null any that are stale.
	 *
	 * Called from AgaviContext::reset() instead of shutdown() so that this
	 * manager object stays alive across requests, avoiding the re-initialization
	 * cost on every request. Any connection that fails its ping() is nulled
	 * inside the database object; getConnection() will then reconnect lazily on
	 * the next use — which fixes "connection lost after laptop sleep" without a
	 * full restart.
	 */
	public function recycleConnections(): void
	{
		$logger = $this->getContext()?->getLoggerManager()?->getLogger();

		foreach ($this->databases as $name => $database) {
			$alive = $database->ping();
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug(
					'[AgaviDatabaseManager] recycle ' . $name
					. ' alive=' . ($alive ? 'yes' : 'no — will reconnect lazily')
				);
			}
		}

		// Close Propel static connections — Propel maintains its own pool
		// which does not go through our ping() path.
		if (class_exists('\Propel\Propel', false)) {
			if (\Agavi\Util\DebugFlags::$database) {
				$logger?->debug('[AgaviDatabaseManager] recycleConnections: closing Propel static connections');
			}
			\Propel\Propel::close();
		}
	}
}

?>