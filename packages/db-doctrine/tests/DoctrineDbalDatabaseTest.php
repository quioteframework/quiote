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

    public function testGetQueryBuilderReturnsAFreshBuilderEachTime(): void
    {
        $db = $this->sqliteDatabase();

        $first = $db->getQueryBuilder();
        $second = $db->getQueryBuilder();

        $this->assertInstanceOf(\Doctrine\DBAL\Query\QueryBuilder::class, $first);
        $this->assertNotSame($first, $second, 'a shared builder would leak one caller\'s clauses into the next');
    }

    public function testGetDbalConnectionRejectsAConnectionOfTheWrongType(): void
    {
        $db = new class extends DoctrineDbalDatabase {
            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = new stdClass();
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('is not a Doctrine\DBAL\Connection (got stdClass)');
        $db->getDbalConnection();
    }

    /**
     * DriverManager rejects a parameter set it cannot build a driver from;
     * the adapter has to name itself and the database rather than let a raw
     * DBAL exception out.
     */
    public function testAConnectionDbalRefusesToBuildIsReportedByTheAdapter(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), ['connection' => ['host' => 'db.internal']]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('could not create a DBAL connection');
        $db->getConnection();
    }

    // --- worker lifecycle --------------------------------------------------

    public function testPingIsTrueBeforeAnythingHasConnected(): void
    {
        $db = $this->sqliteDatabase();

        $this->assertTrue($db->ping(), 'lazy connect handles the first use');
    }

    public function testPingProbesALiveConnection(): void
    {
        $db = $this->sqliteDatabase();
        $db->getDbalConnection();

        $this->assertTrue($db->ping());
    }

    /**
     * A worker holds its connection across requests, so one whose probe fails
     * has to be reported as unusable and cleared -- otherwise every later
     * request reuses the dead one.
     */
    public function testPingIsFalseAndClearsAConnectionWhoseProbeFails(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'wrapperClass' => DoctrineDbalUnreachableConnection::class,
            ],
        ]);
        $db->getDbalConnection();

        $this->assertFalse($db->ping());
        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
    }

    public function testShutdownRollsBackAnOpenTransaction(): void
    {
        $db = $this->sqliteDatabase();
        $connection = $db->getDbalConnection();
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo = $db->getPdo();

        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO t (name) VALUES ('uncommitted')");
        $this->assertTrue($connection->isTransactionActive());

        $db->shutdown();

        $this->assertFalse($pdo->inTransaction(), 'an open transaction would hold locks past shutdown');
    }

    public function testShutdownDropsTheConnectionSoTheNextUseReconnects(): void
    {
        $db = $this->sqliteDatabase();
        $connection = $db->getDbalConnection();

        $db->shutdown();

        $this->assertNotSame($connection, $db->getDbalConnection());
    }

    public function testShutdownBeforeConnectingIsANoOp(): void
    {
        $db = $this->sqliteDatabase();

        $db->shutdown();

        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $db->getDbalConnection());
    }

    private function sqliteDatabase(): DoctrineDbalDatabase
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        return $db;
    }
}

/** A DBAL connection whose probe always fails, standing in for one the server has dropped. */
final class DoctrineDbalUnreachableConnection extends \Doctrine\DBAL\Connection
{
    #[\Override]
    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?\Doctrine\DBAL\Cache\QueryCacheProfile $qcp = null,
    ): \Doctrine\DBAL\Result {
        throw new \RuntimeException('server has gone away');
    }
}
