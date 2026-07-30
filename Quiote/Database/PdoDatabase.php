<?php
namespace Quiote\Database;

use Quiote\Exception\DatabaseException;
use PDO;

/**
 * PdoDatabase provides connectivity for the PDO database API layer.
 * @since      1.0.0
 * @version    1.0.0
 */
class PdoDatabase extends Database
{
	/**
	 * Initialize this Database.
	 * @param      DatabaseManager $databaseManager The database manager of this instance.
	 * @param      array<string, mixed> $parameters An assoc array of initialization params.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(DatabaseManager $databaseManager, array $parameters = [])
	{
		parent::initialize($databaseManager, $parameters);

		$dsn = $this->getParameter('dsn');
		if ($dsn !== null && !is_string($dsn)) {
			throw new DatabaseException(sprintf('Database configuration parameter "dsn" must be a string, %s given.', get_debug_type($dsn)));
		}

		if($this->getParameter('warn_mysql_charset', true) && is_string($dsn) && str_starts_with($dsn, 'mysql:')) {
			if($matches = preg_grep('/^\s*SET\s+NAMES\b/i', (array)$this->getParameter('init_queries'))) {
				throw new DatabaseException(sprintf(
					'Depending on your MySQL server configuration, it may not be safe to use "SET NAMES" to configure the connection encoding, as the underlying MySQL client library will not be aware of the changed character set.' .
					'As a result, string escaping may be applied incorrectly, leading to potential attack vectors in combination with certain multi-byte character sets such as GBK or Big5.' . "\n\n" .
					'Please use the "charset" DSN option instead and remove the "%s" statement from the "init_queries" configuration parameter in databases.xml.' . "\n\n" .
					'The associated PHP bug ticket http://bugs.php.net/47802 contains further information.',
					$matches[0]
				));
			}
		}
	}

	/**
	 * Connect to the database.
	 * @throws     DatabaseException If a connection could not be
	 *                                           created.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function connect()
	{
		// determine how to get our parameters
		$method = $this->getParameter('method', 'dsn');
		if (!is_string($method)) {
			throw new DatabaseException(sprintf('Database configuration parameter "method" must be a string, %s given.', get_debug_type($method)));
		}

		// get parameters
		switch($method) {
			case 'dsn' :
				$dsn = $this->getParameter('dsn');
				if($dsn == null) {
					// missing required dsn parameter
					$error = 'Database configuration specifies method "dsn", but is missing dsn parameter';
					throw new DatabaseException($error);
				}
				if (!is_string($dsn)) {
					throw new DatabaseException(sprintf('Database configuration parameter "dsn" must be a string, %s given.', get_debug_type($dsn)));
				}
				break;
			default :
				throw new DatabaseException(sprintf('Database configuration specifies unsupported connection method "%s"', $method));
		}

		try {
			$username = $this->getParameter('username');
			if ($username !== null && !is_string($username)) {
				throw new DatabaseException(sprintf('Database configuration parameter "username" must be a string, %s given.', get_debug_type($username)));
			}
			$password = $this->getParameter('password');
			if ($password !== null && !is_string($password)) {
				throw new DatabaseException(sprintf('Database configuration parameter "password" must be a string, %s given.', get_debug_type($password)));
			}

			$options = [];

			if($this->hasParameter('options')) {
				foreach((array)$this->getParameter('options') as $key => $value) {
					[$optionKey, $optionValue] = self::resolveConstantPair($key, $value);
					$options[$optionKey] = $optionValue;
				}
			}

			$this->connection = $this->resource = new PDO($dsn, $username, $password, $options);

			// default connection attributes
			$attributes = [
				// lets generate exceptions instead of silent failures
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
			];
			if($this->hasParameter('attributes')) {
				foreach((array)$this->getParameter('attributes') as $key => $value) {
					[$attributeKey, $attributeValue] = self::resolveConstantPair($key, $value);
					$attributes[$attributeKey] = $attributeValue;
				}
			}
			foreach($attributes as $key => $value) {
				if (!is_int($key)) {
					throw new DatabaseException(sprintf('PDO connection attribute keys must resolve to a PDO::ATTR_* int constant, got string "%s".', $key));
				}
				$this->connection->setAttribute($key, $value);
			}
			foreach((array)$this->getParameter('init_queries') as $query) {
				if (!is_string($query)) {
					throw new DatabaseException(sprintf('Database configuration parameter "init_queries" entries must be strings, %s given.', get_debug_type($query)));
				}
				$this->connection->exec($query);
			}
		} catch(\PDOException $e) {
			throw new DatabaseException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * Resolve a raw options/attributes key/value pair, translating "Class::CONST"
	 * style string references (as written in databases.xml) to their actual
	 * constant values.
	 * @return array{0:int|string,1:mixed}
	 */
	private static function resolveConstantPair(mixed $key, mixed $value): array
	{
		if (is_string($key) && strpos($key, '::')) {
			if (!defined($key)) {
				throw new DatabaseException(sprintf('Database configuration references undefined constant "%s".', $key));
			}
			$resolvedKey = constant($key);
			if (!is_int($resolvedKey) && !is_string($resolvedKey)) {
				throw new DatabaseException(sprintf('Constant "%s" must resolve to an int or string to be used as an array key, %s given.', $key, get_debug_type($resolvedKey)));
			}
			$key = $resolvedKey;
		} elseif (!is_int($key) && !is_string($key)) {
			throw new DatabaseException(sprintf('Database configuration option/attribute keys must be int or string, %s given.', get_debug_type($key)));
		}

		if (is_string($value) && strpos($value, '::')) {
			if (!defined($value)) {
				throw new DatabaseException(sprintf('Database configuration references undefined constant "%s".', $value));
			}
			$value = constant($value);
		}

		return [$key, $value];
	}

	/**
	 * Retrieve the underlying PDO connection.
	 * @since      1.0.0
	 */
	#[\Override]
    public function getPdo(): PDO
	{
		$connection = $this->getConnection();
		if (!$connection instanceof PDO) {
			throw new DatabaseException(sprintf(
				'PdoDatabase "%s" expected a PDO connection, got %s.',
				$this->getName(),
				get_debug_type($connection)
			));
		}

		return $connection;
	}

	/**
	 * Execute the shutdown procedure.
	 * @throws     DatabaseException If an error occurs while shutting
	 *                                           down this database.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		// assigning null to a previously open connection object causes a disconnect
		$this->connection = $this->resource = null;
	}

	/**
	 * Probe whether the PDO connection is still alive by running a lightweight query.
	 * On failure (e.g. the MySQL server went away because the laptop slept while
	 * Docker was running) the stale connection is nulled so that the next call to
	 * getConnection() will reconnect transparently.
	 */
	#[\Override]
    public function ping(): bool
	{
		if ($this->connection === null) {
			return true; // will connect lazily on first getConnection()
		}
		if ($this->wasRecentlyVerified()) {
			return true;
		}
		try {
			if (!$this->connection instanceof PDO) {
				// A PdoDatabase's connection is only ever set to a real PDO instance
				// by connect(); anything else means corrupted internal state, which
				// we treat the same as a dead connection rather than crash here.
				throw new \PDOException(sprintf('Expected a PDO connection, got %s.', get_debug_type($this->connection)));
			}
			$this->connection->query('SELECT 1');
			$this->lastUsedAt = microtime(true);
			return true;
		} catch (\PDOException) {
			// Connection lost — null it so getConnection() reconnects lazily.
			$logger = \Quiote\Logging\Log::for($this);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug(
					'[PdoDatabase] ping() failed — nulling stale connection for lazy reconnect'
				);
			}
			$this->connection = $this->resource = null;
			return false;
		}
	}
}

?>