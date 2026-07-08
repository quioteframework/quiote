<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Quiote\Security\Auth\Provider\PdoUserProvider;

class PdoUserProviderTest extends TestCase
{
	private function makeManagerWithUsersTable(): DatabaseManager
	{
		if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			$this->markTestSkipped('pdo_sqlite driver not available in test environment');
		}

		$db = new PdoDatabase();
		$db->initialize(new DatabaseManager(), ['dsn' => 'sqlite::memory:']);
		$pdo = $db->getPdo();
		$pdo->exec('CREATE TABLE users (email TEXT PRIMARY KEY, password_hash TEXT NOT NULL)');
		$pdo->exec("INSERT INTO users (email, password_hash) VALUES ('alice@example.com', 'hash1')");

		$manager = new DatabaseManager();
		(new ReflectionProperty($manager, 'databases'))->setValue($manager, ['main' => $db]);

		return $manager;
	}

	public function testLoadByIdentifierReturnsAMatchingRow(): void
	{
		$provider = new PdoUserProvider($this->makeManagerWithUsersTable());

		$identity = $provider->loadByIdentifier('alice@example.com');

		$this->assertInstanceOf(\Quiote\Security\Auth\PasswordProtectedUserIdentity::class, $identity);
		$this->assertSame('alice@example.com', $identity->getIdentifier());
		$this->assertSame('hash1', $identity->getPasswordHash());
	}

	public function testLoadByIdentifierReturnsNullWhenNoRowMatches(): void
	{
		$provider = new PdoUserProvider($this->makeManagerWithUsersTable());

		$this->assertNull($provider->loadByIdentifier('nobody@example.com'));
	}

	public function testConstructorRejectsAnInvalidTableName(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new PdoUserProvider(new DatabaseManager(), table: 'users; DROP TABLE users');
	}

	public function testConstructorRejectsAnInvalidColumnName(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new PdoUserProvider(new DatabaseManager(), identifierColumn: 'email; --');
	}

	public function testLoadByIdentifierThrowsWhenConnectionIsNotPdoBacked(): void
	{
		$manager = new DatabaseManager();
		$notPdo = $this->createStub(\Quiote\Database\Database::class);
		(new ReflectionProperty($manager, 'databases'))->setValue($manager, ['main' => $notPdo]);

		$provider = new PdoUserProvider($manager);

		$this->expectException(RuntimeException::class);
		$provider->loadByIdentifier('alice@example.com');
	}
}
