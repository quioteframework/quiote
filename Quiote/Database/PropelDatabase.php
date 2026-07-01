<?php
namespace Quiote\Database;

use Quiote\Exception\DatabaseException;
use Quiote\Util\Toolkit;
use Propel\Propel;
use Propel\Config\PropelConfiguration;

/**
 * An Quiote Database driver for Propel. Supports Propel 1.3 and later.
 * <b>Optional parameters:</b>
 * # <b>config</b>         - [none]    - path to the Propel runtime config file
 * # <b>datasource</b>     - [default] - datasource to use for the connection
 * # <b>use_as_default</b> - [false]   - use as default if multiple connections
 *                                       are specified. The configuration file
 *                                       that has been flagged using this param
 *                                       is be used when Propel is initialized
 *                                       via PropelAutoload. By default, the
 *                                       last config file in database.ini will
 *                                       be used.
 * # <b>enable_instance_pooling</b> - [none] - set this to false if you want to 
 *                                             explicitly disable propel 1.3 
 *                                             instance pooling, to true if 
 *                                             you want to explicitly enable it.
 *                                             Leave empty to use propels default.
 * @since      1.0.0
 * @version    1.0.0
 */

class PropelDatabase extends Database
{
	/**
	 * Connect to the database.
	 * @throws     <b>DatabaseException</b> If a connection could not be 
	 *                                           created.
	 * @since      1.0.0
	 */
	protected function connect()
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] connect() called - database_id=' . spl_object_id($this) . ' datasource=' . $this->getParameter('datasource'));
		}

		$this->connection = Propel::getConnection($this->getParameter('datasource'));

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] connect() completed - connection_id=' . spl_object_id($this->connection) . ' type=' . $this->connection::class);
		}
	}

	/**
	 * Load Propel config
	 * @param      DatabaseManager The database manager of this instance.
	 * @param      array                An assoc array of initialization params.
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(DatabaseManager $databaseManager, array $parameters = [])
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] initialize() called - database_id=' . spl_object_id($this));
		}

		parent::initialize($databaseManager, $parameters);
		$configPath = Toolkit::expandDirectives($this->getParameter('config'));
		$datasource = $this->getParameter('datasource', null);
		$use_as_default = $this->getParameter('use_as_default', false);
		$config = require($configPath);
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] loading config from: ' . $configPath . ' datasource=' . $datasource);
		}
		
		if($datasource === null || $datasource == 'default') {
			if(isset($config['propel']['datasources']['default'])) {
				$datasource = $config['propel']['datasources']['default'];
			} elseif(isset($config['datasources']['default'])) {
				$datasource = $config['datasources']['default'];
			} else {
				throw new DatabaseException('No datasource given for Propel connection, and no default datasource specified in runtime configuration file.');
			}
		}
		
		if(!Propel::isInit()) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[PropelDatabase] Propel not initialized, calling Propel::init()');
			}
			Propel::init($configPath);
		} else {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[PropelDatabase] Propel already initialized');
			}
		}
		
		
		$config = Propel::getConfiguration(PropelConfiguration::TYPE_OBJECT);
		
		$overrides = (array)$this->getParameter('overrides');
		
		// set override values
		foreach($overrides as $key => $value) {
			$config->setParameter($key, $value);
		}
		
		// handle init queries in a cross-adapter fashion (they all support the "init_queries" param)
		$queries = (array)$config->getParameter('datasources.' . $datasource . '.connection.settings.queries.query', []);
		// yes... it's one array, [connection][settings][queries][query], with all the init queries from the config, so we append to that
		$queries = array_merge($queries, (array)$this->getParameter('init_queries'));
		$config->setParameter('datasources.' . $datasource . '.connection.settings.queries.query', $queries);
		
		if(true === $this->getParameter('enable_instance_pooling')) {
			Propel::enableInstancePooling();
		} elseif(false === $this->getParameter('enable_instance_pooling')) {
			Propel::disableInstancePooling();
		}
		
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] initialize() completed - datasource=' . $datasource);
		}
	}

	/**
	 * Get the path to the Propel config file for this connection which has been
	 * specified in databases.xml.
	 * @return     string The path to the Propel configuration file
	 * @since      1.0.0
	 */
	public function getConfigPath()
	{
		return $this->getParameter('config');
	}

	/**
	 * Execute the shutdown procedure.
	 * @throws     <b>DatabaseException</b> If an error occurs while shutting
	 *                                           down this database.
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] shutdown() called - database_id=' . spl_object_id($this) . ' connection_id=' . ($this->connection ? spl_object_id($this->connection) : 'NULL'));
		}

		$this->connection = $this->resource = null;

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[PropelDatabase] shutdown() completed - connection cleared');
		}
	}

	/**
	 * Probe whether the shared Propel connection is still alive.
	 * Quiote's worker reset closes Propel's static connection pool, but this wrapper
	 * can still retain the old PDO object. If that handle is dead, clear it here so
	 * the next getConnection() call reconnects cleanly.
	 */
	#[\Override]
    public function ping(): bool
	{
		if ($this->connection === null) {
			return true;
		}

		try {
			$this->connection->query('SELECT 1');
			return true;
		} catch (\Throwable) {
			$this->connection = $this->resource = null;
			return false;
		}
	}
}

?>