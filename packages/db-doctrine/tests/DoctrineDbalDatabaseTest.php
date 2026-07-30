<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;
use Doctrine\DBAL\DriverManager;

/**
 * Unit tests for DoctrineDbalDatabase::getPdo() covering both the pdo_* driver
 * (happy path) and native driver (failure path) cases. Uses sqlite so no
 * container/network dependency is needed.
 */
class DoctrineDbalDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DriverManager::class)) {
            $this->markTestSkipped('doctrine/dbal not installed');
        }
    }

    public function testGetPdoReturnsRawPdoWithPdoSqliteDriver(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $pdo = $db->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO t (name) VALUES ('quiote')");
        $stmt = $pdo->query('SELECT name FROM t WHERE id = 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame('quiote', $stmt->fetchColumn());
    }

    public function testGetPdoThrowsWithNativeSqlite3Driver(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('ext-sqlite3 not available');
        }

        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'sqlite3', 'memory' => true],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/native \(non-PDO\)/');
        $db->getPdo();
    }

    public function testUrlParameterIsParsedViaDsnParser(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'url' => 'pdo-sqlite:///:memory:',
        ]);

        $pdo = $db->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testFlatDriverParamRejectsUnsupportedDriver(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'driver' => 'not_a_real_driver',
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/unsupported "driver" parameter/');
        $db->getConnection();
    }

    public function testFlatHostParamRejectsNonStringType(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'driver' => 'pdo_sqlite',
            'host'   => ['not', 'a', 'string'],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"host" parameter must be a string/');
        $db->getConnection();
    }

    public function testInlineConnectionArrayRejectsUnknownKey(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true, 'not_a_real_key' => 'x'],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/not supported/');
        $db->getConnection();
    }

    public function testInlineConnectionArrayRejectsInvalidDriver(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'not_a_real_driver'],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/unsupported "driver" value/');
        $db->getConnection();
    }
}
