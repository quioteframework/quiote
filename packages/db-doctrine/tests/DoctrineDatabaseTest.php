<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Doctrine\DoctrineDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;
use Doctrine\ORM\EntityManager;

/**
 * Unit tests for DoctrineDatabase::getPdo() covering both the pdo_* driver
 * (happy path) and native driver (failure path) cases. Uses sqlite so no
 * container/network dependency is needed.
 */
class DoctrineDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EntityManager::class)) {
            $this->markTestSkipped('doctrine/orm not installed');
        }
    }

    public function testGetPdoReturnsRawPdoWithPdoSqliteDriver(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'dev_mode'   => true,
        ]);

        $pdo = $db->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO t (name) VALUES ('quiote')");
        $this->assertSame('quiote', $pdo->query('SELECT name FROM t WHERE id = 1')->fetchColumn());
    }

    public function testGetPdoThrowsWithNativeSqlite3Driver(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('ext-sqlite3 not available');
        }

        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'sqlite3', 'memory' => true],
            'dev_mode'   => true,
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/native \(non-PDO\)/');
        $db->getPdo();
    }
}
