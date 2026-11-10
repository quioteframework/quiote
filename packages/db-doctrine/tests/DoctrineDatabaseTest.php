<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Doctrine\DoctrineDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;
use Doctrine\ORM\EntityManager;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;

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
        $stmt = $pdo->query('SELECT name FROM t WHERE id = 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame('quiote', $stmt->fetchColumn());
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

    public function testEntityPathsRejectsNonStringElement(): void
    {
        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection'   => ['driver' => 'pdo_sqlite', 'memory' => true],
            'entity_paths' => ['a/valid/path', 123],
            'dev_mode'     => true,
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"entity_paths" must contain only strings/');
        $db->getConnection();
    }

    public function testProxyDirRejectsNonStringType(): void
    {
        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'proxy_dir'  => 123,
            'dev_mode'   => true,
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"proxy_dir" parameter must be a string or null/');
        $db->getConnection();
    }

    public function testMetadataRejectsNonStringType(): void
    {
        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'metadata'   => ['xml'],
            'dev_mode'   => true,
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"metadata" parameter must be a string/');
        $db->getConnection();
    }

    public function testGetRepositoryReturnsTypedEntityRepository(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection'   => ['driver' => 'pdo_sqlite', 'memory' => true],
            'entity_paths' => [__DIR__ . '/Entity'],
            'dev_mode'     => true,
        ]);

        $repository = $db->getRepository(\Quiote\Test\Database\Entity\DoctrineUser::class);

        $this->assertInstanceOf(\Doctrine\ORM\EntityRepository::class, $repository);
    }

    // --- connection resolution ---------------------------------------------

    /**
     * A `connection` string names another configured database to run on, so
     * the entity manager must end up on that database's own DBAL connection
     * rather than opening a second one.
     */
    public function testAConnectionNameReusesTheReferencedDbalDatabase(): void
    {
        $dbal = new DoctrineDbalDatabase();
        $orm = new DoctrineDatabase();
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, ['dbal' => $dbal, 'orm' => $orm]);

        $dbal->initialize($manager, ['connection' => ['driver' => 'pdo_sqlite', 'memory' => true]]);
        $orm->initialize($manager, ['connection' => 'dbal', 'entity_paths' => [__DIR__ . '/Entity']]);

        $this->assertSame($dbal->getDbalConnection(), $orm->getDbalConnection());
    }

    /**
     * DBAL 4 cannot wrap a raw PDO, so referencing a plain PdoDatabase has to
     * say what to reference instead rather than fail somewhere inside DBAL.
     */
    public function testAConnectionNameThatIsNotADbalDatabaseIsReportedWithWhatToUse(): void
    {
        $pdo = new \Quiote\Database\PdoDatabase();
        $orm = new DoctrineDatabase();
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, ['under' => $pdo, 'orm' => $orm]);

        $pdo->initialize($manager, ['dsn' => 'sqlite::memory:']);
        $orm->initialize($manager, ['connection' => 'under']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Reference a DoctrineDbalDatabase');
        $orm->getConnection();
    }

    public function testNoConnectionDetailsAtAllIsReportedAsSuch(): void
    {
        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), []);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('needs connection details');
        $db->getConnection();
    }

    public function testAConnectionDbalRefusesToBuildIsReportedByTheAdapter(): void
    {
        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), ['connection' => ['host' => 'db.internal']]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('could not create a DBAL connection');
        $db->getConnection();
    }

    public function testGetEntityManagerRejectsAConnectionOfTheWrongType(): void
    {
        $db = new class extends DoctrineDatabase {
            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = new stdClass();
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('is not an EntityManagerInterface (got stdClass)');
        $db->getEntityManager();
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
        $db->getEntityManager();

        $this->assertTrue($db->ping());
    }

    /**
     * The identity map is per-request state: entities managed during one
     * request must not still be managed for the next one this worker serves.
     */
    public function testResetClearsTheIdentityMap(): void
    {
        $db = $this->sqliteDatabase();
        $em = $db->getEntityManager();
        $em->getConnection()->executeStatement('CREATE TABLE doctrine_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $user = new \Quiote\Test\Database\Entity\DoctrineUser();
        $user->name = 'quiote';
        $em->persist($user);
        $em->flush();
        $this->assertTrue($em->contains($user));

        $db->reset();

        $this->assertFalse($em->contains($user));
    }

    /**
     * reset() is Database's teardown to the pre-initialize() state, so it has
     * to leave nothing behind that a later use could run against -- the
     * parameters included. Recycling a worker's connection between requests
     * is ping()'s job, not this one's.
     */
    public function testResetReturnsTheDatabaseToItsPreInitializeState(): void
    {
        $db = $this->sqliteDatabase();
        $db->getEntityManager();

        $db->reset();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
        $this->assertFalse($db->hasParameter('connection'));
    }

    public function testShutdownRollsBackAnOpenTransaction(): void
    {
        $db = $this->sqliteDatabase();
        $connection = $db->getDbalConnection();
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo = $db->getPdo();

        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO t (name) VALUES ('uncommitted')");

        $db->shutdown();

        $this->assertFalse($pdo->inTransaction(), 'an open transaction would hold locks past shutdown');
    }

    public function testShutdownDropsTheEntityManagerSoTheNextUseRebuildsIt(): void
    {
        $db = $this->sqliteDatabase();
        $em = $db->getEntityManager();

        $db->shutdown();

        $this->assertNotSame($em, $db->getEntityManager());
    }

    public function testShutdownBeforeConnectingIsANoOp(): void
    {
        $db = $this->sqliteDatabase();

        $db->shutdown();

        $this->assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $db->getEntityManager());
    }

    // --- request boundary ---------------------------------------------------

    /**
     * The identity map is what keeps a worker serving a row it loaded in an earlier request:
     * find() answers from it without querying, so an update another worker made is never seen.
     * The boundary has to detach everything while keeping the entity manager for reuse.
     */
    public function testResetRequestStateMakesTheNextRequestSeeRowsAnotherProcessUpdated(): void
    {
        $db = $this->sqliteDatabaseWithUsersTable();
        $entityManager = $db->getEntityManager();

        $user = new \Quiote\Test\Database\Entity\DoctrineUser();
        $user->name = 'old-logo';
        $entityManager->persist($user);
        $entityManager->flush();
        $id = $user->id;
        $this->assertNotNull($id);

        // Another worker updates the row behind this entity manager's back.
        $db->getDbalConnection()->executeStatement(
            'UPDATE doctrine_users SET name = ? WHERE id = ?',
            ['new-logo', $id]
        );
        $stale = $entityManager->find(\Quiote\Test\Database\Entity\DoctrineUser::class, $id);
        $this->assertNotNull($stale);
        $this->assertSame('old-logo', $stale->name, 'precondition: within one request the identity map answers');

        $db->resetRequestState();

        $this->assertSame($entityManager, $db->getEntityManager(), 'the entity manager is reused, not rebuilt');
        $fresh = $db->getEntityManager()->find(\Quiote\Test\Database\Entity\DoctrineUser::class, $id);
        $this->assertNotNull($fresh);
        $this->assertSame('new-logo', $fresh->name);
    }

    public function testResetRequestStateRollsBackATransactionARequestLeftOpen(): void
    {
        $db = $this->sqliteDatabaseWithUsersTable();
        $connection = $db->getDbalConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO doctrine_users (name) VALUES ('uncommitted')");

        $db->resetRequestState();

        $this->assertFalse($connection->isTransactionActive(), 'every nesting level is rolled back');
        $this->assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_users'));
    }

    /**
     * A failed flush closes the entity manager for good; reused as-is, every later request in
     * the worker would fail with "the EntityManager is closed".
     */
    public function testAClosedEntityManagerIsDroppedSoTheNextRequestGetsAWorkingOne(): void
    {
        $db = $this->sqliteDatabaseWithUsersTable();
        $closed = $db->getEntityManager();
        $closed->close();

        $db->resetRequestState();

        $next = $db->getEntityManager();
        $this->assertNotSame($closed, $next);
        $this->assertTrue($next->isOpen());
    }

    public function testResetRequestStateBeforeConnectingDoesNothing(): void
    {
        $db = $this->sqliteDatabase();

        $db->resetRequestState();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db), 'resetting does not connect');
    }

    public function testResetRequestStateReportsAFailure(): void
    {
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->method('getConnection')->willThrowException(new \RuntimeException('server has gone away'));
        $db = new class ($entityManager) extends DoctrineDatabase {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $stub)
            {
                parent::__construct();
            }

            #[\Override]
            protected function connect()
            {
                $this->connection = $this->stub;
            }
        };
        $db->getConnection();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('server has gone away');
        $db->resetRequestState();
    }

    private function sqliteDatabaseWithUsersTable(): DoctrineDatabase
    {
        $db = $this->sqliteDatabase();
        $db->getDbalConnection()->executeStatement(
            'CREATE TABLE doctrine_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255) NOT NULL)'
        );

        return $db;
    }

    private function sqliteDatabase(): DoctrineDatabase
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new DoctrineDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection'   => ['driver' => 'pdo_sqlite', 'memory' => true],
            'entity_paths' => [__DIR__ . '/Entity'],
            'dev_mode'     => true,
        ]);

        return $db;
    }
}
