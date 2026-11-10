<?php

use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Schema;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Cycle\CycleDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;

/**
 * Unit test for CycleDatabase::getPdo() — always unsupported, since
 * cycle/database never exposes its driver's PDO/PDOInterface publicly.
 */
class CycleDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ORM::class)) {
            $this->markTestSkipped('cycle/orm not installed');
        }
    }

    public function testGetPdoAlwaysThrows(): void
    {
        $cycleConfig = [
            'default'     => 'default',
            'databases'   => ['default' => ['connection' => 'sqlite']],
            'connections' => [
                'sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig()),
            ],
        ];

        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'cycle'  => $cycleConfig,
            'schema' => [],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/does not expose a raw PDO connection/');
        $db->getPdo();
    }

    public function testGetOrmAndGetCycleDatabaseManagerReturnTypedInstances(): void
    {
        $cycleConfig = [
            'default'     => 'default',
            'databases'   => ['default' => ['connection' => 'sqlite']],
            'connections' => [
                'sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig()),
            ],
        ];

        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'cycle'  => $cycleConfig,
            'schema' => [],
        ]);

        $this->assertInstanceOf(ORMInterface::class, $db->getOrm());
        $this->assertInstanceOf(DatabaseProviderInterface::class, $db->getCycleDatabaseManager());
    }

    public function testBuildDatabaseConfigRejectsNonStringKeys(): void
    {
        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'cycle'  => [0 => 'unexpected', 'default' => 'default'],
            'schema' => [],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"cycle" parameter array must have string keys/');
        $db->getConnection();
    }

    public function testBuildDatabaseConfigRejectsMissingCycleParameter(): void
    {
        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'schema' => [],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/requires a "cycle" parameter/');
        $db->getConnection();
    }

    // --- schema ------------------------------------------------------------

    public function testAnAlreadyCompiledSchemaObjectIsUsedAsIs(): void
    {
        $schema = new Schema([]);
        $db = $this->database(['schema' => $schema]);

        $this->assertInstanceOf(ORMInterface::class, $db->getOrm());
    }

    /**
     * The provider is how an application hands over a schema it cached or
     * compiled itself, so it has to be called with the adapter and its result
     * honoured -- both the Schema and the raw-array form.
     */
    public function testASchemaProviderCallableIsGivenTheAdapterAndItsSchemaUsed(): void
    {
        $seen = null;
        $db = $this->database([
            'schema_provider' => function (CycleDatabase $database) use (&$seen): Schema {
                $seen = $database;

                return new Schema([]);
            },
        ]);

        $this->assertInstanceOf(ORMInterface::class, $db->getOrm());
        $this->assertSame($db, $seen);
    }

    public function testASchemaProviderMayReturnAPlainSchemaArray(): void
    {
        $db = $this->database(['schema_provider' => static fn(): array => []]);

        $this->assertInstanceOf(ORMInterface::class, $db->getOrm());
    }

    /**
     * A provider is the application's own code, so a wrong return type has to
     * name the provider rather than surface as a Cycle type error.
     */
    public function testASchemaProviderReturningSomethingElseIsRejected(): void
    {
        $db = $this->database(['schema_provider' => static fn(): string => 'nope']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"schema_provider" must return a Cycle\\\\ORM\\\\Schema/');
        $db->getConnection();
    }

    public function testASchemaProviderWinsOverAConfiguredSchema(): void
    {
        $used = false;
        $db = $this->database([
            'schema' => [],
            'schema_provider' => function () use (&$used): Schema {
                $used = true;

                return new Schema([]);
            },
        ]);

        $db->getOrm();

        $this->assertTrue($used);
    }

    public function testNoSchemaAtAllIsReportedWithWhatToSupply(): void
    {
        $db = $this->database([]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/requires a compiled schema/');
        $db->getConnection();
    }

    // --- worker lifecycle --------------------------------------------------

    public function testPingIsTrueBeforeAnythingHasConnected(): void
    {
        $db = $this->database(['schema' => []]);

        $this->assertTrue($db->ping(), 'lazy connect handles the first use');
    }

    public function testPingProbesALiveConnection(): void
    {
        $db = $this->database(['schema' => []]);
        $db->getOrm();

        $this->assertTrue($db->ping());
    }

    /**
     * The heap is the identity map: entities hydrated during one request must
     * not be visible to the next one this worker serves.
     */
    public function testResetCleansTheOrmHeap(): void
    {
        $db = $this->database(['schema' => []]);
        $orm = $db->getOrm();
        $entity = new stdClass();
        $orm->getHeap()->attach($entity, new Node(Node::MANAGED, ['id' => 1], 'user'));
        $this->assertTrue($orm->getHeap()->has($entity));

        $db->reset();

        $this->assertFalse($orm->getHeap()->has($entity));
    }

    /**
     * reset() is Database's teardown to the pre-initialize() state, so it has
     * to leave nothing behind that a later use could run against -- the
     * parameters included. Recycling a worker's connection between requests
     * is ping()'s job, not this one's.
     */
    public function testResetReturnsTheDatabaseToItsPreInitializeState(): void
    {
        $db = $this->database(['schema' => []]);
        $db->getOrm();

        $db->reset();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
        $this->assertFalse($db->hasParameter('cycle'));
    }

    public function testShutdownDropsTheOrmSoTheNextUseRebuildsIt(): void
    {
        $db = $this->database(['schema' => []]);
        $orm = $db->getOrm();

        $db->shutdown();

        $this->assertNotSame($orm, $db->getOrm());
    }

    public function testShutdownBeforeConnectingIsANoOp(): void
    {
        $db = $this->database(['schema' => []]);

        $db->shutdown();

        $this->assertInstanceOf(ORMInterface::class, $db->getOrm());
    }

    // --- typed accessors ---------------------------------------------------

    public function testGetOrmRejectsAConnectionThatIsNotAnOrm(): void
    {
        $db = new class extends CycleDatabase {
            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = new stdClass();
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('connection is not an ORMInterface (got stdClass)');
        $db->getOrm();
    }

    public function testGetCycleDatabaseManagerRejectsAResourceOfTheWrongType(): void
    {
        $db = new class extends CycleDatabase {
            #[\Override]
            protected function connect()
            {
                $this->connection = new stdClass();
                $this->resource = new stdClass();
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('resource is not a DatabaseProviderInterface (got stdClass)');
        $db->getCycleDatabaseManager();
    }

    // --- request boundary ---------------------------------------------------

    /**
     * The heap is the identity map: an entity a worker hydrated in one request would otherwise
     * be served in the next however often another process has updated its row since. The
     * boundary has to clean it while keeping the ORM for reuse.
     */
    public function testResetRequestStateCleansTheHeapAndKeepsTheOrm(): void
    {
        $db = $this->database(['schema' => []]);
        $orm = $db->getOrm();
        $entity = new stdClass();
        $orm->getHeap()->attach($entity, new Node(Node::MANAGED, ['id' => 1], 'user'));

        $db->resetRequestState();

        $this->assertFalse($orm->getHeap()->has($entity));
        $this->assertSame($orm, $db->getOrm(), 'the ORM is reused, not rebuilt');
        $this->assertTrue($db->hasParameter('cycle'), 'the configuration survives the request boundary');
    }

    public function testResetRequestStateRollsBackATransactionARequestLeftOpen(): void
    {
        $db = $this->database(['schema' => []]);
        $database = $db->getCycleDatabaseManager()->database('default');
        $database->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $driver = $database->getDriver();
        $driver->beginTransaction();
        $driver->beginTransaction();
        $database->execute("INSERT INTO t (name) VALUES ('uncommitted')");

        $db->resetRequestState();

        $this->assertSame(0, $driver->getTransactionLevel(), 'every nesting level is rolled back');
        $this->assertSame(0, $database->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testResetRequestStateBeforeConnectingDoesNothing(): void
    {
        $db = $this->database(['schema' => []]);

        $db->resetRequestState();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db), 'resetting does not connect');
    }

    public function testResetRequestStateReportsAFailure(): void
    {
        $orm = $this->createStub(ORMInterface::class);
        $orm->method('getHeap')->willThrowException(new RuntimeException('heap is locked'));
        $db = $this->database(['schema' => []]);
        (new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->setValue($db, $orm);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('heap is locked');
        $db->resetRequestState();
    }

    /**
     * @param array<string, mixed> $parameters Merged over a working sqlite `cycle` config.
     */
    private function database(array $parameters): CycleDatabase
    {
        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'cycle' => [
                'default' => 'default',
                'databases' => ['default' => ['connection' => 'sqlite']],
                'connections' => [
                    'sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig()),
                ],
            ],
            ...$parameters,
        ]);

        return $db;
    }
}
