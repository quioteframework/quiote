<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Eloquent\EloquentDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Quiote\Exception\DatabaseException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Integration tests for the Eloquent adapter. Skipped unless illuminate/database
 * is installed (it's a suggested, not required, dependency).
 */
class EloquentDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            $this->markTestSkipped('illuminate/database not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
    }

    public function testStandaloneSqliteRoundTrip(): void
    {
        $mgr = new DatabaseManager();
        $db = new EloquentDatabase();
        $db->initialize($mgr, ['driver' => 'sqlite', 'database' => ':memory:']);

        $capsule = $db->getCapsule();
        $this->assertInstanceOf(Capsule::class, $capsule);

        $conn = $db->getEloquentConnection();
        $conn->statement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->table('t')->insert(['name' => 'quiote']);

        $this->assertSame('quiote', $conn->table('t')->where('id', 1)->value('name'));
        $this->assertTrue($db->ping());
        $this->assertSame($conn->getPdo(), $db->getPdo());
    }

    public function testLayerModeBorrowsPdoFromReferencedDatabase(): void
    {
        $under = new PdoDatabase();
        $orm = new EloquentDatabase();
        $mgr = new DatabaseManager();
        $ref = new ReflectionProperty($mgr, 'databases');
        $ref->setValue($mgr, ['under' => $under, 'orm' => $orm]);

        $under->initialize($mgr, ['dsn' => 'sqlite::memory:']);
        $orm->initialize($mgr, ['connection' => 'under', 'driver' => 'sqlite']);

        // The Eloquent connection should be driving the very PDO the PdoDatabase opened.
        $this->assertSame($under->getConnection(), $orm->getEloquentConnection()->getPdo());
    }

    public function testInlineConnectionArrayIsAcceptedAsIs(): void
    {
        $mgr = new DatabaseManager();
        $db = new EloquentDatabase();
        $db->initialize($mgr, [
            'connection' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $this->assertInstanceOf(Capsule::class, $db->getCapsule());
    }

    public function testConnectionNameRejectsNonStringType(): void
    {
        $mgr = new DatabaseManager();
        $db = new EloquentDatabase();
        $db->initialize($mgr, [
            'driver'          => 'sqlite',
            'database'        => ':memory:',
            'connection_name' => ['not', 'a', 'string'],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"connection_name" parameter must be a string/');
        $db->getCapsule();
    }

    public function testMissingDriverThrows(): void
    {
        $mgr = new DatabaseManager();
        $db = new EloquentDatabase();
        $db->initialize($mgr, ['database' => ':memory:']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/requires a "driver" parameter/');
        $db->getCapsule();
    }

    public function testInlineConnectionArrayRejectsNonStringKeys(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'sqlite', 5 => 'unexpected'],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/inline "connection" array keys must be strings/');
        $db->getCapsule();
    }

    /**
     * The Capsule keys its connections by name, so a database configured
     * under a non-default name has to be reachable under exactly that name.
     */
    public function testAConfiguredConnectionNameIsUsedThroughout(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'connection_name' => 'reporting',
        ]);

        $this->assertSame($db->getCapsule()->getConnection('reporting'), $db->getEloquentConnection());
    }

    public function testGlobalAlsoBootsEloquentSoModelsResolve(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'global' => true,
        ]);

        $capsule = $db->getCapsule();

        $this->assertSame($capsule->getConnection(), Capsule::connection());
        $this->assertInstanceOf(
            \Illuminate\Database\ConnectionResolverInterface::class,
            \Illuminate\Database\Eloquent\Model::getConnectionResolver(),
            'bootEloquent() defaults to the value of "global"',
        );
    }

    public function testGetCapsuleRejectsAConnectionThatIsNotACapsule(): void
    {
        $db = new class extends EloquentDatabase {
            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = new stdClass();
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('is not an Illuminate\Database\Capsule\Manager (got stdClass)');
        $db->getCapsule();
    }

    // --- worker lifecycle --------------------------------------------------

    public function testPingIsTrueBeforeAnythingHasConnected(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertTrue($db->ping(), 'lazy connect handles the first use');
    }

    /**
     * A worker holds its connection across requests, so a handle that has
     * gone away has to be reported as unusable and cleared -- otherwise every
     * later request reuses the dead one.
     */
    public function testPingIsFalseAndClearsAConnectionWhoseHandleHasGoneAway(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), ['driver' => 'sqlite', 'database' => ':memory:']);
        $db->getEloquentConnection()->setPdo(new class ('sqlite::memory:') extends PDO {
            #[\Override]
            public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
            {
                throw new PDOException('server has gone away');
            }
        });

        $this->assertFalse($db->ping());
        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
    }

    public function testShutdownRollsBackAnOpenTransaction(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), ['driver' => 'sqlite', 'database' => ':memory:']);

        $conn = $db->getEloquentConnection();
        $conn->statement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo = $conn->getPdo();
        $conn->beginTransaction();
        $conn->table('t')->insert(['name' => 'uncommitted']);
        $this->assertSame(1, $conn->transactionLevel());

        $db->shutdown();

        $this->assertFalse($pdo->inTransaction(), 'an open transaction would hold locks past shutdown');

        $count = $pdo->query('SELECT COUNT(*) FROM t');
        $this->assertNotFalse($count);
        $this->assertSame(0, (int) $count->fetchColumn(), 'the uncommitted row was rolled back');
    }

    public function testShutdownDropsTheCapsuleSoTheNextUseRebuildsIt(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), ['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule = $db->getCapsule();

        $db->shutdown();

        $this->assertNotSame($capsule, $db->getCapsule());
    }

    public function testShutdownBeforeConnectingIsANoOp(): void
    {
        $db = new EloquentDatabase();
        $db->initialize(new DatabaseManager(), ['driver' => 'sqlite', 'database' => ':memory:']);

        $db->shutdown();

        $this->assertInstanceOf(Capsule::class, $db->getCapsule());
    }
}
