<?php
namespace Quiote\Database;

use Quiote\Util\ParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Database is a base abstraction class that allows you to setup any type
 * of database connection via a configuration file.
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class Database extends ParameterHolder implements ResetInterface
{
	/**
	 * @var        DatabaseManager An DatabaseManager instance.
	 */
	protected $databaseManager = null;
	
	/**
	 * @var        mixed A database connection.
	 */
	protected $connection = null;

	/**
	 * @var        string The name of the database.
	 */
	private $name = null;

	/**
	 * @var        mixed A database resource.
	 */
	protected $resource = null;

	/**
	 * Connect to the database.
	 * @throws     <b>DatabaseException</b> If a connection could not be 
	 *                                           created.
	 * @since      1.0.0
	 */
	abstract protected function connect();
	
	/**
	 * Retrieve the Database Manager instance for this implementation.
	 * @return     DatabaseManager A Database Manager instance.
	 * @since      1.0.0
	 */
	public function getDatabaseManager()
	{
		return $this->databaseManager;
	}

	/**
	 * Retrieve the name of this database connection.
	 * @return     string The name of the database.
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->name;
	}
	
	/**
	 * Retrieve the database connection associated with this Database
	 * implementation.
	 * When this is executed on a Database implementation that isn't an
	 * abstraction layer, a copy of the resource will be returned.
	 * @return     mixed A database connection.
	 * @throws     <b>DatabaseException</b> If a connection could not be 
	 *                                           retrieved.
	 * @since      1.0.0
	 */
	public function getConnection()
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[Database] getConnection() called - database_id=' . spl_object_id($this) . ' connection_exists=' . ($this->connection ? 'YES' : 'NO'));
		}

		if($this->connection === null) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[Database] connection is null, calling connect()');
			}
			$this->connect();
		}

		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[Database] getConnection() returning connection_id=' . spl_object_id($this->connection) . ' type=' . $this->connection::class);
		}
		
		return $this->connection;
	}

	/**
	 * Retrieve a raw database resource associated with this Database
	 * implementation.
	 * @return     mixed A database resource.
	 * @throws     <b>DatabaseException</b> If no resource could be retrieved
	 * @since      1.0.0
	 */
	public function getResource()
	{
		if($this->resource === null) {
			$this->connect();
		}

		return $this->resource;
	}

	/**
	 * Initialize this Database.
	 * @param      DatabaseManager The database manager of this instance.
	 * @param      array                An assoc array of initialization params.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing this Database.
	 * @since      1.0.0
	 */
	public function initialize(DatabaseManager $databaseManager, array $parameters = [])
	{
		$this->databaseManager = $databaseManager;
		
		$this->setParameters($parameters);
		
		$this->name = $databaseManager->getDatabaseName($this);
	}

	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 * It is called during the startup() of the database manager.
	 * @since      1.0.0
	 */
	public function startup()
	{
	}

	/**
	 * Execute the shutdown procedure.
	 * @throws     <b>DatabaseException</b> If an error occurs while shutting
	 *                                           down this database.
	 * @since      1.0.0
	 */
	abstract public function shutdown();

	#[\Override]
    public function reset(): void
	{
		// Reset the database connection
		if ($this->connection !== null) {
			$this->shutdown();
			$this->connection = null;
			$this->resource = null;
		}

		// Reset parameters
		$this->clearParameters();

		// Reset the database manager reference
		$this->databaseManager = null;

		// Reset the name
		$this->name = null;
	}

	/**
	 * Probe whether the connection is still alive.
	 * Returns true if healthy or no connection has been established yet
	 * (lazy connect will handle it on first getConnection()). Returns false
	 * if the connection appears dead, signalling recycleConnections() to null
	 * it so getConnection() reconnects lazily on the next use.
	 * Subclasses SHOULD override with a driver-specific probe (e.g. SELECT 1).
	 */
	public function ping(): bool
	{
		if ($this->connection === null) {
			// No connection yet — lazy connect will create it on first use.
			return true;
		}
		// Unknown driver — conservatively treat as potentially dead so we force
		// a reconnect rather than silently using a broken connection.
		return false;
	}
}

?>