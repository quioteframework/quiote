<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\AbstractOrmDatabase;
use Quiote\Database\Database;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Quiote\Exception\DatabaseException;

/**
 * Concrete test double exposing AbstractOrmDatabase's protected resolution
 * helpers, so we can exercise layer/standalone connection resolution without a
 * real ORM installed.
 */
class _OrmDatabaseDouble extends AbstractOrmDatabase
{
    protected function connect()
    {
        $this->connection = new \stdClass();
    }

    public function exposeResolveUnderlyingConnection(): mixed
    {
        return $this->resolveUnderlyingConnection();
    }

    public function exposeResolveUnderlyingPdo(): \PDO
    {
        return $this->resolveUnderlyingPdo();
    }

    public function exposeRequireLibrary(string $probeClass, string $package): void
    {
        $this->requireLibrary($probeClass, $package);
    }
}

/** A Database whose connection is an arbitrary (non-PDO) object. */
class _NonPdoDatabaseDouble extends Database
{
    protected function connect()
    {
        $this->connection = new \stdClass();
    }

    public function shutdown()
    {
        $this->connection = null;
    }
}

class AbstractOrmDatabaseTest extends TestCase
{
    /**
     * @param array<string, Database> $databases
     */
    private function managerWith(array $databases): DatabaseManager
    {
        $mgr = new DatabaseManager();
        $ref = new ReflectionProperty($mgr, 'databases');
        $ref->setValue($mgr, $databases);
        return $mgr;
    }

    public function testResolveNullWhenNoConnectionParam(): void
    {
        $mgr = new DatabaseManager();
        $db = new _OrmDatabaseDouble();
        $db->initialize($mgr, []);
        $this->assertNull($db->exposeResolveUnderlyingConnection());
    }

    public function testResolveArrayReturnsItVerbatim(): void
    {
        $mgr = new DatabaseManager();
        $db = new _OrmDatabaseDouble();
        $db->initialize($mgr, ['connection' => ['driver' => 'mysql', 'host' => 'x']]);
        $this->assertSame(['driver' => 'mysql', 'host' => 'x'], $db->exposeResolveUnderlyingConnection());
    }

    public function testLayerModeResolvesReferencedConnection(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
        $under = new PdoDatabase();
        $orm = new _OrmDatabaseDouble();
        $mgr = $this->managerWith(['under' => $under, 'orm' => $orm]);
        $under->initialize($mgr, ['dsn' => 'sqlite::memory:']);
        $orm->initialize($mgr, ['connection' => 'under']);

        $resolved = $orm->exposeResolveUnderlyingConnection();
        $this->assertInstanceOf(PDO::class, $resolved);
        $this->assertInstanceOf(PDO::class, $orm->exposeResolveUnderlyingPdo());
    }

    public function testUnknownReferencedConnectionThrows(): void
    {
        $orm = new _OrmDatabaseDouble();
        $mgr = $this->managerWith(['orm' => $orm]);
        $orm->initialize($mgr, ['connection' => 'missing']);

        $this->expectException(DatabaseException::class);
        $orm->exposeResolveUnderlyingConnection();
    }

    public function testSelfReferenceThrows(): void
    {
        $orm = new _OrmDatabaseDouble();
        $mgr = $this->managerWith(['orm' => $orm]);
        $orm->initialize($mgr, ['connection' => 'orm']);

        $this->expectException(DatabaseException::class);
        $orm->exposeResolveUnderlyingConnection();
    }

    public function testResolvePdoRejectsNonPdoConnection(): void
    {
        $under = new _NonPdoDatabaseDouble();
        $orm = new _OrmDatabaseDouble();
        $mgr = $this->managerWith(['under' => $under, 'orm' => $orm]);
        $under->initialize($mgr, []);
        $orm->initialize($mgr, ['connection' => 'under']);

        $this->expectException(DatabaseException::class);
        $orm->exposeResolveUnderlyingPdo();
    }

    public function testRequireLibraryThrowsForMissingClass(): void
    {
        $mgr = new DatabaseManager();
        $db = new _OrmDatabaseDouble();
        $db->initialize($mgr, []);

        $this->expectException(DatabaseException::class);
        $db->exposeRequireLibrary('Totally\\Missing\\OrmClass', 'vendor/package');
    }

    public function testRequireLibraryPassesForExistingClass(): void
    {
        $mgr = new DatabaseManager();
        $db = new _OrmDatabaseDouble();
        $db->initialize($mgr, []);

        $db->exposeRequireLibrary(PDO::class, 'ext-pdo');
        $this->assertTrue(true); // no exception
    }
}
