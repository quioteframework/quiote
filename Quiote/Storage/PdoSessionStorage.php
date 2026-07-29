<?php
namespace Quiote\Storage;

use Quiote\Context;
use Quiote\Exception\DatabaseException;
use Quiote\Exception\InitializationException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Provides support for session storage using a PDO database abstraction
 * layer.
 * <b>Required parameters:</b>
 * # <b>db_table</b> - [none] - The database table in which session data will be
 *                              stored.
 * <b>Optional parameters:</b>
 * # <b>database</b>     - [default]   - The database connection to use
 *                                       (see databases.xml).
 * # <b>db_id_col</b>    - [sess_id]   - The database column in which the
 *                                       session id will be stored.
 * # <b>db_data_col</b>  - [sess_data] - The database column in which the
 *                                       session data will be stored.
 * # <b>db_time_col</b>  - [sess_time] - The database column in which the
 *                                       session timestamp will be stored.
 * # <b>data_as_lob</b>  - [true]      - If true, data is stored as a LOB
 *                                       other wise as a string.
 *                                       (Note: with Oracle LOBs are always
 *                                        used)
 * # <b>date_format</b>  - [U]         - The format string passed to date() to
 *                                       format timestamps. Defaults to "U",
 *                                       which means a Unix Timestamp again.
 * @since      1.0.0
 * @version    1.0.0
 */
class PdoSessionStorage extends SessionStorage implements ResetInterface
{
	/**
	 * @var        ?\PDO A Database Connection.
	 */
	protected $connection;

	/**
	 * @var ?string Memoized PDO::ATTR_DRIVER_NAME probe, invalidated in open()
	 *              whenever the connection instance changes.
	 */
	private ?string $driverName = null;

	/**
	 * @var ?\PDOStatement Cached read() statement, rebuilt only when the
	 *                     connection changes (open()).
	 */
	private ?\PDOStatement $readStmt = null;

	/**
	 * @var ?\PDOStatement Cached write() statement (native upsert, or the
	 *                     UPDATE half of the UPDATE-first fallback for
	 *                     drivers without a verified native upsert).
	 */
	private ?\PDOStatement $writeStmt = null;

	/**
	 * @var ?\PDOStatement Cached INSERT statement used only by the
	 *                     UPDATE-first fallback path, when the UPDATE
	 *                     affected no rows.
	 */
	private ?\PDOStatement $writeInsertStmt = null;

	/**
	 * Initialize this Storage.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing this Storage.
	 * @since      1.0.0
	 */
    #[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		// initialize the parent
		parent::initialize($context, $parameters);

		if(!$this->hasParameter('db_table')) {
			// missing required 'db_table' parameter
			$error = 'Factory configuration file is missing required "db_table" parameter for the Storage category';
			throw new InitializationException($error);
		}

		// use this object as the session handler
		session_set_save_handler($this);
	}

	/**
	 * Close a session.
	 * @return     bool true, if the session was closed, otherwise false.
	 * @since      1.0.0
	 */
	#[\Override]
    public function close() : bool
	{
		if($this->connection) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Destroy a session.
	 * @param      string $id A session ID.
	 * @return     bool true, if the session was destroyed, otherwise an
	 *                  exception is thrown.
	 * @throws     \Quiote\Exception\DatabaseException If the session cannot be
	 *                                           destroyed.
	 * @since      1.0.0
	 */
	#[\Override]
    public function destroy($id) : bool
	{
		if(!$this->connection) {
			return false;
		}
		
		// get table/column
		$db_table  = $this->stringParameter('db_table');
		$db_id_col = $this->stringParameter('db_id_col', 'sess_id');

		// delete the record associated with this id
		$sql = sprintf('DELETE FROM %s WHERE %s = ?', $db_table, $db_id_col);

		try {
			$stmt = $this->connection->prepare($sql);
			$result = $stmt->execute([$id]);
			if(!$result) {
				$this->throwPdoError($stmt);
			}
			return true;
		} catch(\PDOException $e) {
			$error = sprintf('PDOException was thrown when trying to manipulate session data. Message: "%s"', $e->getMessage());
			throw new DatabaseException($error, 0, $e);
		}
	}

	/**
	 * Cleanup old sessions.
	 * @param      int $lifetime The lifetime of a session.
	 * @return     int|false The number of sessions removed, or false on failure.
	 * @throws     \Quiote\Exception\DatabaseException If old sessions cannot be
	 *                                           cleaned.
	 * @since      1.0.0
	 */
	#[\Override]
    public function gc(int$lifetime) : int|false
	{
		if(!$this->connection) {
			return false;
		}
		
		// determine deletable session time
		$time = time() - $lifetime;
		$time = date($this->stringParameter('date_format', 'U'), $time);

		// get table/column
		$db_table    = $this->stringParameter('db_table');
		$db_time_col = $this->stringParameter('db_time_col', 'sess_time');

		// delete the records that are expired
		$sql = sprintf('DELETE FROM %s WHERE %s < :time', $db_table, $db_time_col);

		try {
			$stmt = $this->connection->prepare($sql);
			if(is_numeric($time)) {
				$time = (int)$time;
				$stmt->bindValue(':time', $time, \PDO::PARAM_INT);
			} else {
				$stmt->bindValue(':time', $time, \PDO::PARAM_STR);
			}
			$result = $stmt->execute();

			if(!$result) {
				$this->throwPdoError($stmt);
			}

			return $stmt->rowCount();
		} catch(\PDOException $e) {
			$error = sprintf('PDOException was thrown when trying to manipulate session data. Message: "%s"', $e->getMessage());
			throw new DatabaseException($error, 0, $e);
		}
	}

	/**
	 * Open a session.
	 * @param      string $path The path is ignored.
	 * @param      string $name The name is ignored.
	 * @return     bool true, if the session was opened, otherwise an exception
	 *                  is thrown.
	 * @throws     \Quiote\Exception\DatabaseException If a connection with the database
	 *                                           does not exist or cannot be
	 *                                           created.
	 * @since      1.0.0
	 */
	#[\Override]
    public function open($path, $name) : bool
	{
		// what database are we using?
		$databaseParam = $this->getParameter('database', null);
		$database = is_string($databaseParam) ? $databaseParam : null;

		/** @phan-suppress-next-line UnusedSuppression */
		$connection = $this->getContext()->getDatabaseConnection($database);
		if($connection === null || !$connection instanceof \PDO) {
			$error = 'Database connection "' . ($database ?? '') . '" could not be found or is not a PDO database connection.';
			throw new DatabaseException($error);
		}

		if($connection !== $this->connection) {
			// A prepared statement is bound to the PDO connection it was
			// prepared against; when the connection changes (or on first use)
			// every cached statement/driver probe is stale.
			$this->driverName = null;
			$this->readStmt = null;
			$this->writeStmt = null;
			$this->writeInsertStmt = null;
		}
		$this->connection = $connection;

		return true;
	}

	/**
	 * Memoized PDO::ATTR_DRIVER_NAME probe. Only called from read()/write(),
	 * both of which already guard on $this->connection being non-null.
	 */
	private function driverName(): string
	{
		if($this->driverName === null) {
			$connection = $this->connection;
			$this->driverName = $connection instanceof \PDO ? $this->scalarToString($connection->getAttribute(\PDO::ATTR_DRIVER_NAME)) : '';
		}
		return $this->driverName;
	}

	/**
	 * Build the single write() statement for a given driver: a native
	 * one-round-trip upsert for drivers it's verified against, or the UPDATE
	 * half of the UPDATE-first fallback for everything else (see write()).
	 */
	private function buildWriteSql(string $driver, string $table, string $idCol, string $dataCol, string $timeCol): string
	{
		return match($driver) {
			'mysql' => sprintf(
				'INSERT INTO %1$s (%2$s, %3$s, %4$s) VALUES (:id, :data, :time) ON DUPLICATE KEY UPDATE %3$s = VALUES(%3$s), %4$s = VALUES(%4$s)',
				$table, $idCol, $dataCol, $timeCol
			),
			'pgsql', 'sqlite' => sprintf(
				'INSERT INTO %1$s (%2$s, %3$s, %4$s) VALUES (:id, :data, :time) ON CONFLICT (%2$s) DO UPDATE SET %3$s = EXCLUDED.%3$s, %4$s = EXCLUDED.%4$s',
				$table, $idCol, $dataCol, $timeCol
			),
			'oracle' => sprintf(
				'UPDATE %s SET %s = EMPTY_BLOB(), %s = :time WHERE %s = :id RETURNING %s INTO :data',
				$table, $dataCol, $timeCol, $idCol, $dataCol
			),
			default => sprintf(
				'UPDATE %s SET %s = :data, %s = :time WHERE %s = :id',
				$table, $dataCol, $timeCol, $idCol
			),
		};
	}

	private function throwPdoError(\PDOStatement $stmt): never
	{
		$errorInfo = $stmt->errorInfo();
		$e = new \PDOException((string) ($errorInfo[2] ?? ''), 0);
		$e->errorInfo = $errorInfo;
		throw $e;
	}

	/**
	 * Narrow a config parameter (db_table, db_id_col, date_format, ...) to
	 * string, falling back to $default for a misconfigured non-string value.
	 */
	private function stringParameter(string $name, string $default = ''): string
	{
		$value = $this->getParameter($name, $default);
		return is_string($value) ? $value : $default;
	}

	/**
	 * Coerce a mixed DB column value to string using the same scalar rule
	 * PHP's own (string) cast uses, falling back to '' for values that can't
	 * be meaningfully stringified.
	 */
	private function scalarToString(mixed $value): string
	{
		if(is_string($value)) {
			return $value;
		}
		if(is_scalar($value) || $value instanceof \Stringable) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Read a session.
	 * @param      string $id A session ID.
	 * @return     string|false The session data, or false if the session is closed.
	 * @throws     \Quiote\Exception\DatabaseException If the session cannot be read.
	 * @since      1.0.0
	 */
	#[\Override]
    public function read($id): string|false
	{
		if(!$this->connection) {
			return false;
		}

		try {
			if($this->readStmt === null) {
				$db_table    = $this->stringParameter('db_table');
				$db_data_col = $this->stringParameter('db_data_col', 'sess_data');
				$db_id_col   = $this->stringParameter('db_id_col', 'sess_id');
				$sql = sprintf('SELECT %s FROM %s WHERE %s = ?', $db_data_col, $db_table, $db_id_col);
				$this->readStmt = $this->connection->prepare($sql);
			}
			$stmt = $this->readStmt;
		} catch(\PDOException $e) {
			$error = sprintf('PDOException was thrown when trying to manipulate session data. Message: "%s"', $e->getMessage());
			throw new DatabaseException($error, 0, $e);
		}

		try {
			$result = $stmt->execute([$id]);

			if(!$result) {
				$this->throwPdoError($stmt);
			}

			$row = $stmt->fetch(\PDO::FETCH_NUM);
			$retval = '';
			if(is_array($row) && array_key_exists(0, $row)) {
				$value = $row[0];
				// pdo is returning the LOB as stream, so check if we had a lob (this seems to differ from db to db).
				// Drain it here, while the cursor is still open — the finally below closes it.
				if(is_resource($value)) {
					$contents = stream_get_contents($value);
					$retval = $contents === false ? '' : $contents;
				} else {
					$retval = $this->scalarToString($value);
				}
			}

			return $retval;
		} catch(\PDOException $e) {
			$error = sprintf('PDOException was thrown when trying to manipulate session data. Message: "%s"', $e->getMessage());
			throw new DatabaseException($error, 0, $e);
		} finally {
			// Release the cursor. A statement fetched from but never exhausted or closed
			// leaves the connection inside an implicit read transaction holding a shared
			// lock; write()'s upsert then has to upgrade shared -> exclusive, which SQLite
			// refuses immediately with SQLITE_BUSY rather than waiting (busy_timeout does
			// not apply to upgrades, which is why raising it never helped). The cached
			// $readStmt makes it worse: re-executing a statement whose cursor is still open
			// is "bad parameter or other API misuse" (SQLSTATE HY000 / 21).
			// In a finally so a throwing read cannot wedge the connection for the
			// remaining life of a worker process.
			$stmt->closeCursor();
		}
	}

	/**
	 * Write session data.
	 * @param      string $id A session ID.
	 * @param      string $data A serialized chunk of session data.
	 * @return     bool true, if the session was written, otherwise an exception
	 *                  is thrown.
	 * @throws     \Quiote\Exception\DatabaseException If session data cannot be
	 *                                           written.
	 * @since      1.0.0
	 */
	#[\Override]
    public function write(string $id, string $data): bool
	{
		if(!$this->connection) {
			return false;
		}

		$driver = $this->driverName();
		$isOracle = $driver === 'oracle';
		$useLob = $this->getParameter('data_as_lob', true);
		$columnType = ($isOracle || $useLob) ? \PDO::PARAM_LOB : \PDO::PARAM_STR;

		if($isOracle) {
			$sp = fopen('php://memory', 'r+');
			if ($sp === false) {
				throw new DatabaseException('Unable to open in-memory stream for LOB data');
			}
			fwrite($sp, $data);
			rewind($sp);
		} else {
			$sp = $data;
		}

		$ts = date($this->stringParameter('date_format', 'U'));
		if(is_numeric($ts)) {
			$ts = (int)$ts;
		}

		try {
			if($this->writeStmt === null) {
				$db_table    = $this->stringParameter('db_table');
				$db_data_col = $this->stringParameter('db_data_col', 'sess_data');
				$db_id_col   = $this->stringParameter('db_id_col', 'sess_id');
				$db_time_col = $this->stringParameter('db_time_col', 'sess_time');
				$this->writeStmt = $this->connection->prepare(
					$this->buildWriteSql($driver, $db_table, $db_id_col, $db_data_col, $db_time_col)
				);
			}
			$stmt = $this->writeStmt;
			$stmt->bindParam(':id', $id);
			$stmt->bindParam(':data', $sp, $columnType);
			if(is_int($ts)) {
				$stmt->bindValue(':time', $ts, \PDO::PARAM_INT);
			} else {
				$stmt->bindValue(':time', $ts, \PDO::PARAM_STR);
			}
			if(!$stmt->execute()) {
				$this->throwPdoError($stmt);
			}

			if($driver === 'mysql' || $driver === 'pgsql' || $driver === 'sqlite') {
				// Single-statement native upsert: the common case (writing an
				// existing session) never pays for a failed INSERT + caught
				// exception + rollback + retry.
				return true;
			}

			// Drivers without a verified native upsert (Oracle, SQL Server,
			// unrecognized): UPDATE-first -- an existing session is still the
			// common case -- and only pay for a second round trip (INSERT)
			// when the row doesn't exist yet.
			if($stmt->rowCount() === 0) {
				if($this->writeInsertStmt === null) {
					$db_table    = $this->stringParameter('db_table');
					$db_data_col = $this->stringParameter('db_data_col', 'sess_data');
					$db_id_col   = $this->stringParameter('db_id_col', 'sess_id');
					$db_time_col = $this->stringParameter('db_time_col', 'sess_time');
					$this->writeInsertStmt = $this->connection->prepare(
						sprintf('INSERT INTO %s (%s, %s, %s) VALUES (:id, :data, :time)', $db_table, $db_id_col, $db_data_col, $db_time_col)
					);
				}
				$insertStmt = $this->writeInsertStmt;
				$insertStmt->bindParam(':id', $id);
				$insertStmt->bindParam(':data', $sp, $columnType);
				if(is_int($ts)) {
					$insertStmt->bindValue(':time', $ts, \PDO::PARAM_INT);
				} else {
					$insertStmt->bindValue(':time', $ts, \PDO::PARAM_STR);
				}
				if(!$insertStmt->execute()) {
					$this->throwPdoError($insertStmt);
				}
			}

			return true;
		} catch(\PDOException $e) {
			$error = sprintf('PDOException was thrown when trying to manipulate session data. Message: "%s"', $e->getMessage());
			throw new DatabaseException($error, 0, $e);
		}
	}

}

?>