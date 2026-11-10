<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Database;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Quiote\Exception\DatabaseException;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;

final class DatabaseRequestStateResetTestCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $events = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->events[] = $event;
    }

    public function flush(): void
    {
    }
}

/** A database that records each request-boundary reset, or fails it on demand. */
final class DatabaseRequestStateResetTestRecordingDatabase extends Database
{
    public int $resets = 0;

    public function __construct(private readonly ?\Throwable $failWith = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function connect()
    {
        $this->connection = $this->resource = new stdClass();
    }

    #[\Override]
    public function shutdown()
    {
        $this->connection = $this->resource = null;
    }

    #[\Override]
    public function resetRequestState(): void
    {
        $this->resets++;
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}

/**
 * The request-boundary reset of database state in a persistent worker: what
 * {@see DatabaseManager::resetRequestState()} fans out to, and what the plain
 * PDO adapter discards there.
 */
final class DatabaseRequestStateResetTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testTheBaseDatabaseHoldsNoRequestStateAndKeepsItsConnection(): void
    {
        $db = new class extends Database {
            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = new stdClass();
            }

            #[\Override]
            public function shutdown()
            {
                $this->connection = $this->resource = null;
            }
        };
        $db->initialize(new DatabaseManager(), ['marker' => 'kept']);
        $connection = $db->getConnection();

        $db->resetRequestState();

        $this->assertSame($connection, $db->getConnection());
        $this->assertSame('kept', $db->getParameter('marker'), 'the configuration survives the request boundary');
    }

    public function testTheManagerResetsEveryDatabase(): void
    {
        $first = new DatabaseRequestStateResetTestRecordingDatabase();
        $second = new DatabaseRequestStateResetTestRecordingDatabase();
        $manager = $this->managerWith(['first' => $first, 'second' => $second]);

        $manager->resetRequestState();

        $this->assertSame(1, $first->resets);
        $this->assertSame(1, $second->resets);
    }

    /**
     * One database failing must not leave the others holding the previous
     * request's state, and the failure is reported at error because the next
     * request in this worker may see what could not be discarded.
     */
    public function testADatabaseThatFailsIsReportedAndTheOthersAreStillReset(): void
    {
        $sink = new DatabaseRequestStateResetTestCapturingSink();
        Log::addSink($sink);

        $failing = new DatabaseRequestStateResetTestRecordingDatabase(new DatabaseException('rollback refused'));
        $after = new DatabaseRequestStateResetTestRecordingDatabase();
        $manager = $this->managerWith(['failing' => $failing, 'after' => $after]);

        $manager->resetRequestState();

        $this->assertSame(1, $failing->resets);
        $this->assertSame(1, $after->resets, 'a database after the failing one is still reset');

        $errors = array_values(array_filter(
            $sink->events,
            static fn(LogEvent $event): bool => $event->level === Level::Error,
        ));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"failing"', $errors[0]->renderMessage());
        $this->assertStringContainsString('rollback refused', $errors[0]->renderMessage());
    }

    public function testAManagerWithNoDatabasesHasNothingToReset(): void
    {
        $manager = new DatabaseManager();

        $manager->resetRequestState();

        $this->assertSame([], (new ReflectionProperty($manager, 'databases'))->getValue($manager));
    }

    // --- PdoDatabase -------------------------------------------------------

    /**
     * A request that failed half-way through a transaction must not hand its
     * uncommitted writes, or its locks, to the next request on the same
     * connection.
     */
    public function testPdoDatabaseRollsBackATransactionARequestLeftOpenAndKeepsTheConnection(): void
    {
        $db = $this->sqlitePdoDatabase();
        $pdo = $db->getPdo();
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO t (name) VALUES ('uncommitted')");

        $db->resetRequestState();

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($pdo, $db->getPdo(), 'the connection is reused, not reopened');
        $count = $pdo->query('SELECT COUNT(*) FROM t');
        $this->assertNotFalse($count);
        $this->assertSame(0, (int) $count->fetchColumn(), 'the uncommitted row was rolled back');
    }

    public function testPdoDatabaseLeavesCommittedWorkAlone(): void
    {
        $db = $this->sqlitePdoDatabase();
        $pdo = $db->getPdo();
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO t (name) VALUES ('committed')");

        $db->resetRequestState();

        $count = $pdo->query('SELECT COUNT(*) FROM t');
        $this->assertNotFalse($count);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testPdoDatabaseBeforeConnectingHasNothingToReset(): void
    {
        $db = new PdoDatabase();
        $db->initialize(new DatabaseManager(), ['dsn' => 'sqlite::memory:']);

        $db->resetRequestState();

        $this->assertNull((new ReflectionProperty(Database::class, 'connection'))->getValue($db), 'resetting does not connect');
    }

    public function testPdoDatabaseReportsARollbackThatFails(): void
    {
        $db = $this->sqlitePdoDatabase();
        $broken = new class ('sqlite::memory:') extends PDO {
            #[\Override]
            public function inTransaction(): bool
            {
                return true;
            }

            #[\Override]
            public function rollBack(): bool
            {
                throw new PDOException('server has gone away');
            }
        };
        (new ReflectionProperty(Database::class, 'connection'))->setValue($db, $broken);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('server has gone away');
        $db->resetRequestState();
    }

    private function sqlitePdoDatabase(): PdoDatabase
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        $db = new PdoDatabase();
        $db->initialize(new DatabaseManager(), ['dsn' => 'sqlite::memory:']);

        return $db;
    }

    /** @param array<string, Database> $databases */
    private function managerWith(array $databases): DatabaseManager
    {
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, $databases);

        return $manager;
    }
}
