<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
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

use Agavi\Exception\AgaviDatabaseException;
use Agavi\Util\AgaviToolkit;
use Propel\Propel;
use Propel\Config\PropelConfiguration;

/**
 * An Agavi Database driver for Propel. Supports Propel 1.3 and later.
 * 
 * <b>Optional parameters:</b>
 *
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
 * 
 *
 * @package    agavi
 * @subpackage database
 * 
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */

class AgaviPropelDatabase extends AgaviDatabase
{
	/**
	 * Connect to the database.
	 * 
	 * @throws     <b>AgaviDatabaseException</b> If a connection could not be 
	 *                                           created.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	protected function connect()
	{
		$logger = \Agavi\Logging\Log::for($this);
		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] connect() called - database_id=' . spl_object_id($this) . ' datasource=' . $this->getParameter('datasource'));
		}

		$this->connection = Propel::getConnection($this->getParameter('datasource'));

		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] connect() completed - connection_id=' . spl_object_id($this->connection) . ' type=' . $this->connection::class);
		}
	}

	/**
	 * Load Propel config
	 * 
	 * @param      AgaviDatabaseManager The database manager of this instance.
	 * @param      array                An assoc array of initialization params.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	#[\Override]
    public function initialize(AgaviDatabaseManager $databaseManager, array $parameters = [])
	{
		$logger = \Agavi\Logging\Log::for($this);
		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] initialize() called - database_id=' . spl_object_id($this));
		}

		parent::initialize($databaseManager, $parameters);
		$configPath = AgaviToolkit::expandDirectives($this->getParameter('config'));
		$datasource = $this->getParameter('datasource', null);
		$use_as_default = $this->getParameter('use_as_default', false);
		$config = require($configPath);
		
		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] loading config from: ' . $configPath . ' datasource=' . $datasource);
		}
		
		if($datasource === null || $datasource == 'default') {
			if(isset($config['propel']['datasources']['default'])) {
				$datasource = $config['propel']['datasources']['default'];
			} elseif(isset($config['datasources']['default'])) {
				$datasource = $config['datasources']['default'];
			} else {
				throw new AgaviDatabaseException('No datasource given for Propel connection, and no default datasource specified in runtime configuration file.');
			}
		}
		
		if(!Propel::isInit()) {
			if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
				$logger->debug('[AgaviPropelDatabase] Propel not initialized, calling Propel::init()');
			}
			Propel::init($configPath);
		} else {
			if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
				$logger->debug('[AgaviPropelDatabase] Propel already initialized');
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
		
		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] initialize() completed - datasource=' . $datasource);
		}
	}

	/**
	 * Get the path to the Propel config file for this connection which has been
	 * specified in databases.xml.
	 *
	 * @return     string The path to the Propel configuration file
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function getConfigPath()
	{
		return $this->getParameter('config');
	}

	/**
	 * Execute the shutdown procedure.
	 *
	 * @throws     <b>AgaviDatabaseException</b> If an error occurs while shutting
	 *                                           down this database.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function shutdown()
	{
		$logger = \Agavi\Logging\Log::for($this);
		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] shutdown() called - database_id=' . spl_object_id($this) . ' connection_id=' . ($this->connection ? spl_object_id($this->connection) : 'NULL'));
		}

		$this->connection = $this->resource = null;

		if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
			$logger->debug('[AgaviPropelDatabase] shutdown() completed - connection cleared');
		}
	}

	/**
	 * Probe whether the shared Propel connection is still alive.
	 *
	 * Agavi's worker reset closes Propel's static connection pool, but this wrapper
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