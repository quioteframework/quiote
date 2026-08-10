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
