<?php
namespace Quiote\Security\Auth\Provider;

use InvalidArgumentException;
use PDO;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\UserIdentity;
use Quiote\Security\Auth\UserProviderInterface;
use RuntimeException;

/**
 * Resolves identities from a single users table via the framework's
 * `DatabaseManager`, matching the `security.xml` `<provider type="pdo"
 * connection="main" table="users" identifier-column="email"
 * password-column="password_hash">` shape. Role/permission assignment is
 * left to `RbacSecurityUser`'s own definitions, not this table.
 * @since      1.0.0
 */
final class PdoUserProvider implements UserProviderInterface
{
	/**
	 * @param      DatabaseManager $databaseManager The framework's database manager.
	 * @param      string $connection The `databases.xml` connection name to use.
	 * @param      string $table The users table name.
	 * @param      string $identifierColumn The column holding the stable identifier (e.g. email/username).
	 * @param      string $passwordColumn The column holding the password hash.
	 * @throws     InvalidArgumentException If $table/$identifierColumn/$passwordColumn is not a valid SQL identifier.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly DatabaseManager $databaseManager,
		private readonly string $connection = 'main',
		private readonly string $table = 'users',
		private readonly string $identifierColumn = 'email',
		private readonly string $passwordColumn = 'password_hash',
	) {
		self::assertValidIdentifier($this->table);
		self::assertValidIdentifier($this->identifierColumn);
		self::assertValidIdentifier($this->passwordColumn);
	}

	/**
	 * @param      string $identifier E.g. an email or username.
	 * @return     ?UserIdentity Null if no matching row exists.
	 * @throws     RuntimeException If the configured connection is not PDO-backed.
	 * @since      1.0.0
	 */
	public function loadByIdentifier(string $identifier): ?UserIdentity
	{
		$statement = $this->pdo()->prepare(sprintf(
			'SELECT %s AS identifier, %s AS password_hash FROM %s WHERE %s = :identifier LIMIT 1',
			$this->identifierColumn,
			$this->passwordColumn,
			$this->table,
			$this->identifierColumn,
		));
		$statement->execute(['identifier' => $identifier]);
		/** @var array{identifier: string, password_hash: string}|false $row */
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if($row === false) {
			return null;
		}
		return new InMemoryUserIdentity((string) $row['identifier'], (string) $row['password_hash']);
	}

	/**
	 * Claim -> row mapping (e.g. a legacy user id claim) is app-specific;
	 * apps needing this should use {@see CallableUserProvider} or subclass
	 * this and override this method.
	 * @param      TokenClaims $claims The validated token claims.
	 * @return     null Always null in the base implementation.
	 * @since      1.0.0
	 */
	public function loadByToken(TokenClaims $claims): ?UserIdentity
	{
		return null;
	}

	/**
	 * @return     PDO The connection's raw PDO handle.
	 * @throws     RuntimeException If the configured connection is not PDO-backed.
	 * @since      1.0.0
	 */
	private function pdo(): PDO
	{
		$database = $this->databaseManager->getDatabase($this->connection);
		if(!$database instanceof PdoDatabase) {
			throw new RuntimeException(sprintf(
				'PdoUserProvider requires a PDO-backed database connection "%s", got %s.',
				$this->connection,
				get_debug_type($database),
			));
		}
		return $database->getPdo();
	}

	/**
	 * Table/column names come from operator-supplied config, not request
	 * input; this allow-list guards against a config typo producing an
	 * unquotable/injectable identifier rather than against an attacker.
	 * @param      string $identifier The table/column name to validate.
	 * @return     void
	 * @throws     InvalidArgumentException If $identifier is not a valid SQL identifier.
	 * @since      1.0.0
	 */
	private static function assertValidIdentifier(string $identifier): void
	{
		if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
			throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
		}
	}
}
