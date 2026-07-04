<?php
namespace Quiote\Database;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Exception\DatabaseException;

/**
 * DatabaseManager allows you to setup your database connectivity before 
 * the request is handled. This eliminates the need for a filter to manage 
 * database connections.
 * @since      1.0.0
 * @version    1.0.0
 */
class DatabaseManager
{
	/**
	 * @var        string The name of the default database.
	 */
	protected $defaultDatabaseName = null;
	
	/**
	 * @var        array An array of Databases.
	 */
	protected $databases = [];

	/**
	 * @var        Context An Context instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the database connection associated with this Database
	 * implementation.
	 * @param      string $name A database name.
	 * @return     mixed A Database instance.
	 * @throws     \Quiote\Exception\DatabaseException If the requested database name
	 *                                           does not exist.
	 * @since      1.0.0
	 */
	public function getDatabase($name = null)
	{
        $logger = \Quiote\Logging\Log::for($this);
		if($name === null) {
			$name = $this->defaultDatabaseName;
		}
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] getDatabase(' . $name . ') - manager_id=' . spl_object_id($this));
		}
		
		if(isset($this->databases[$name])) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[DatabaseManager] returning existing database: ' . $name . ' id=' . spl_object_id($this->databases[$name]));
			}
			return $this->databases[$name];
		}

		// nonexistent database name
		$error = 'Database "%s" does not exist';
		$error = sprintf($error, $name);
		throw new DatabaseException($error);
	}
	
	/**
	 * Retrieve the name of the given database instance.
	 * @param      Database $database The database to fetch the name of.
	 * @return     string The name of the database, or false if it was not found.
	 * @since      1.0.0
	 */
	public function getDatabaseName(Database $database)
	{
		return array_search($database, $this->databases, true);
	}

	/**
	 * Returns the name of the default database.
	 * @return     string The name of the default database.
	 * @since      1.0.0
	 */
	public function getDefaultDatabaseName()
	{
		return $this->defaultDatabaseName;
	}

	/**
	 * Initialize this DatabaseManager.
	 * @param      Context $context An Context instance.
	 * @param      array $parameters An array of initialization parameters.
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing this 
	 *                                                 DatabaseManager.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
        $logger = \Quiote\Logging\Log::for($this);

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] initialize() called - id=' . spl_object_id($this));
		}
		
		$this->context = $context;

		// load database configuration
		if(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
			$cacheResult = APCuConfigCache::checkConfig(Config::get('core.config_dir') . '/databases.xml');
			if (str_starts_with($cacheResult, 'APCU:')) {
				eval('?>' . substr($cacheResult, 5));
			} else {
				require($cacheResult);
			}
		} else {
			require(ConfigCache::checkConfig(Config::get('core.config_dir') . '/databases.xml'));
		}
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] initialize() completed - databases loaded: ' . implode(', ', array_keys($this->databases)));
		}
	}

	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 * @since      1.0.0
	 */
	public function startup()
	{
        $logger = \Quiote\Logging\Log::for($this);

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] startup() called - id=' . spl_object_id($this) . ' databases=' . count($this->databases));
		}
		
		foreach($this->databases as $name => $database) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[DatabaseManager] starting up database: ' . $name . ' id=' . spl_object_id($database));
			}
			$database->startup();
		}
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] startup() completed');
		}
	}

	/**
	 * Execute the shutdown procedure.
	 * @throws     \Quiote\Exception\DatabaseException If an error occurs while shutting
	 *                                           down this DatabaseManager.
	 * @since      1.0.0
	 */
	public function shutdown()
	{
        $logger = \Quiote\Logging\Log::for($this);

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] shutdown() called - id=' . spl_object_id($this) . ' databases=' . count($this->databases));
		}
		
		// loop through databases and shutdown connections
		foreach($this->databases as $name => $database) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[DatabaseManager] shutting down database: ' . $name . ' id=' . spl_object_id($database));
			}
			$database->shutdown();
		}
		
		// Close Propel static connections to prevent stale connection reuse in worker mode
		if (class_exists('\Propel\Propel', false)) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[DatabaseManager] closing Propel static connections');
			}
			\Propel\Propel::close();
		}
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[DatabaseManager] shutdown() completed');
		}
	}

	/**
	 * Probe all managed database connections and null any that are stale.
	 * Called from Context::reset() instead of shutdown() so that this
	 * manager object stays alive across requests, avoiding the re-initialization
	 * cost on every request. Any connection that fails its ping() is nulled
	 * inside the database object; getConnection() will then reconnect lazily on
	 * the next use — which fixes "connection lost after laptop sleep" without a
	 * full restart.
	 */
	public function recycleConnections(): void
	{
		$logger = \Quiote\Logging\Log::for($this);

		foreach ($this->databases as $name => $database) {
			$alive = $database->ping();
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug(
					'[DatabaseManager] recycle ' . $name
					. ' alive=' . ($alive ? 'yes' : 'no — will reconnect lazily')
				);
			}
		}

		// Close Propel static connections — Propel maintains its own pool
		// which does not go through our ping() path.
		if (class_exists('\Propel\Propel', false)) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[DatabaseManager] recycleConnections: closing Propel static connections');
			}
			\Propel\Propel::close();
		}
	}
}

?>