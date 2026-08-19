<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Adapter\Doctrine\DoctrineEffectSource;
use Quiote\Replay\Adapter\Doctrine\DoctrineRecordingMiddleware;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\IsolatesFromLedger;
use Quiote\Support\Clock\FrozenClock;

/**
 * Database isolation, which is the half of isolated replay that can only work where an ORM lets a
 * decorator sit *instead of* the real statement.
 *
 * The connection here points at a database that does not exist. That is the assertion: a replaying
 * ledger means the recorded rows are served and the connection is never reached, so a query against
 * a missing database has to succeed. Any test that passed against a real sqlite file would prove
 * nothing about isolation.
 */
final class DoctrineIsolatedReplayTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
    }

    protected function tearDown(): void
    {
        ActiveEffectLedger::reset();
    }

    /** A connection to an unwritable, non-existent database: reaching it at all must fail. */
    private function unreachableConnection(): Connection
    {
        $config = new Configuration();
        $config->setMiddlewares([new DoctrineRecordingMiddleware(new FrozenClock(1_755_500_000))]);

        return DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => '/nonexistent-directory/there-is-no-database-here.sqlite'],
            $config,
        );
    }

    /**
     * @param non-negative-int $seq
     * @param array<int|string, mixed> $params
     */
    private function dbEffect(int $seq, string $sql, array $params, mixed $result): Effect
    {
        return new Effect(
            $seq,
            EffectKind::Db,
            RecordingPdoStatement::fingerprintFor($sql, $params),
            ['sql' => $sql, 'params' => $params],
            $result,
        );
    }

    public function testTheDoctrineSourceDeclaresItCanServeFromTheLedger(): void
    {
        // The capability check IsolatedReplay makes before substituting anything. Of the four ORM
        // adapters, only this one's seam is a decorator rather than an after-the-fact observer.
        $this->assertInstanceOf(IsolatesFromLedger::class, new DoctrineEffectSource());
    }

    public function testAPreparedSelectIsServedFromTheCassetteWithoutTouchingTheDatabase(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT id, name FROM t WHERE id = ?', [1 => 7], DbResult::rows([['id' => 7, 'name' => 'recorded']])->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        $stmt = $conn->prepare('SELECT id, name FROM t WHERE id = ?');
        $stmt->bindValue(1, 7);
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        $this->assertSame([['id' => 7, 'name' => 'recorded']], $rows);
        $this->assertSame([], $ledger->misses());
    }

    public function testTheSameQueryReachesTheDatabaseWhenNotReplaying(): void
    {
        // The control: without a replaying ledger the decorator does what it always did, so the
        // unreachable database is reached and fails. Proves the test above is not passing by accident.
        $conn = $this->unreachableConnection();

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $conn->executeQuery('SELECT 1');
    }

    public function testADirectQueryIsServedFromTheCassette(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT count(*) AS c FROM t', [], DbResult::rows([['c' => 3]])->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        $this->assertSame([['c' => 3]], $conn->executeQuery('SELECT count(*) AS c FROM t')->fetchAllAssociative());
    }

    public function testAWriteIsServedAsItsRecordedAffectedCountAndPerformsNothing(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'UPDATE t SET name = ? WHERE id = ?', [1 => 'x', 2 => 7], DbResult::affected(1)->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        $stmt = $conn->prepare('UPDATE t SET name = ? WHERE id = ?');
        $stmt->bindValue(1, 'x');
        $stmt->bindValue(2, 7);

        $this->assertSame(1, $stmt->executeStatement());
    }

    public function testAQueryTheCassetteDoesNotContainRaisesRatherThanReturningNothing(): void
    {
        // Serving an empty result set would invent the input: the code would take whichever branch
        // "no rows" leads to and the replay would report a clean run for a query never recorded.
        $ledger = EffectLedger::forReplay([]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        try {
            $conn->executeQuery('SELECT * FROM never_recorded');
            $this->fail('Expected the miss to raise.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no recorded database effect', $e->getMessage());
        }

        $this->assertCount(1, $ledger->misses(), 'and the miss is booked for the drift report');
    }

    public function testTwoExecutionsOfOneStatementWithDifferentParametersGetTheirOwnRows(): void
    {
        // What the parameter-aware fingerprint buys: asked for in the opposite order to the
        // recording, each execution still gets the rows that belong to it.
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT n FROM t WHERE id = ?', [1 => 1], DbResult::rows([['n' => 'one']])->toArray()),
            $this->dbEffect(1, 'SELECT n FROM t WHERE id = ?', [1 => 2], DbResult::rows([['n' => 'two']])->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        $second = $conn->prepare('SELECT n FROM t WHERE id = ?');
        $second->bindValue(1, 2);
        $this->assertSame([['n' => 'two']], $second->executeQuery()->fetchAllAssociative());

        $first = $conn->prepare('SELECT n FROM t WHERE id = ?');
        $first->bindValue(1, 1);
        $this->assertSame([['n' => 'one']], $first->executeQuery()->fetchAllAssociative());
    }

    public function testAnUnobservableRecordedReadSaysSoRatherThanServingEmpty(): void
    {
        // The shape replay-eloquent and replay-cycle produce: a query happened and its rows were
        // never visible to the recorder. Nothing can be served from that.
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT id FROM t', [], DbResult::unobservedRows()->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        try {
            $conn->executeQuery('SELECT id FROM t');
            $this->fail('Expected the unobservable read to raise.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('captured no rows', $e->getMessage());
        }
    }

    public function testAnEmptyRecordedResultSetServesEmptyRatherThanRaising(): void
    {
        // Distinct from the case above, and the reason DbResult separates the two: a query that
        // genuinely returned nothing is a recorded fact and replays as one.
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT id FROM t WHERE id = 999', [], DbResult::rows([])->toArray()),
        ]);
        ActiveEffectLedger::set($ledger);
        $conn = $this->unreachableConnection();

        $this->assertSame([], $conn->executeQuery('SELECT id FROM t WHERE id = 999')->fetchAllAssociative());
        $this->assertSame([], $ledger->misses());
    }
}
