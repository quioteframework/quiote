<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Quiote\Replay\Adapter\Propulsion\LedgerBackedPropulsionPdo;
use Quiote\Replay\Adapter\Propulsion\PropulsionEffectSource;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\IsolatesFromLedger;

/**
 * Propulsion isolation, which works by substituting the connection rather than by intercepting a
 * query -- because Propulsion's observers are observation-only by contract and cannot answer one.
 *
 * No datasource is configured in these tests on purpose. `Propulsion::getConnection()` would have to
 * open one from configuration, and there is none, so any test that gets a usable connection back has
 * necessarily got it from the substitution rather than from a database.
 */
final class PropulsionIsolatedReplayTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Propulsion::class)) {
            $this->markTestSkipped('quioteframework/propulsion is not installed.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (Propulsion::getOpenConnections() as $connection) {
            Propulsion::discardConnection($connection);
        }
        parent::tearDown();
    }

    /** @param array<int|string, mixed> $params */
    private function dbEffect(int $seq, string $sql, array $params, mixed $result): Effect
    {
        return new Effect(
            max(0, $seq),
            EffectKind::Db,
            RecordingPdoStatement::fingerprintFor($sql, $params),
            ['sql' => $sql, 'params' => $params],
            $result,
        );
    }

    public function testTheSourceDeclaresItCanServeFromTheLedger(): void
    {
        // What IsolatedReplay checks before substituting anything. Previously this source was not an
        // IsolatesFromLedger, so an isolated replay of any Propulsion app refused outright.
        $this->assertInstanceOf(IsolatesFromLedger::class, new PropulsionEffectSource());
    }

    public function testBeginIsolationInstallsAConnectionForBothModes(): void
    {
        $source = new PropulsionEffectSource();
        $ledger = EffectLedger::forReplay([]);

        $source->beginIsolation($ledger);

        try {
            $name = Propulsion::getDefaultDB();
            $this->assertInstanceOf(LedgerBackedPropulsionPdo::class, Propulsion::getConnection($name, Propulsion::CONNECTION_WRITE));
            $this->assertInstanceOf(LedgerBackedPropulsionPdo::class, Propulsion::getConnection($name, Propulsion::CONNECTION_READ));
        } finally {
            $source->endIsolation();
        }
    }

    public function testEndIsolationRemovesTheSubstitutedConnection(): void
    {
        $source = new PropulsionEffectSource();
        $source->beginIsolation(EffectLedger::forReplay([]));
        $this->assertNotSame([], $this->pooledStubs(), 'nothing was installed, so removal proves nothing');

        $source->endIsolation();

        // Nothing left in the pool that answers from a finished ledger -- a stub left behind would
        // make every later request in this process silently replay-shaped.
        $this->assertSame([], $this->pooledStubs());
    }

    /** @return list<LedgerBackedPropulsionPdo> Every substituted connection Propulsion still holds. */
    private function pooledStubs(): array
    {
        return array_values(array_filter(
            Propulsion::getOpenConnections(),
            static fn(mixed $c): bool => $c instanceof LedgerBackedPropulsionPdo,
        ));
    }

    public function testEndIsolationIsSafeWithoutABeginAndDoesNotThrow(): void
    {
        // It runs in IsolatedReplay's finally, where a throw would replace whatever the replay was
        // reporting.
        $source = new PropulsionEffectSource();

        $source->endIsolation();
        $source->endIsolation();

        $this->addToAssertionCount(1);
    }

    public function testAPooledSubstitutionSurvivesPropulsionsLivenessCheck(): void
    {
        // checkOutPooled() *replaces* a pooled connection that fails its liveness check, which
        // mid-replay would reopen a real connection and quietly undo the isolation.
        $connection = new LedgerBackedPropulsionPdo(EffectLedger::forReplay([]));

        $this->assertSame(0.0, $connection->getIdleSeconds(), 'so the check is skipped outright');
        $this->assertTrue($connection->ping(), 'and passes it when it does run');
    }

    public function testAPreparedSelectIsServedFromTheCassette(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT id, name FROM t WHERE id = :id', [':id' => 7], DbResult::rows([['id' => 7, 'name' => 'recorded']])->toArray()),
        ]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        $statement = $connection->prepare('SELECT id, name FROM t WHERE id = :id');
        $this->assertNotFalse($statement);
        $statement->bindValue(':id', 7);
        $statement->execute();

        $this->assertSame([['id' => 7, 'name' => 'recorded']], $statement->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([], $ledger->misses());
    }

    public function testADirectQueryIsServedFromTheCassette(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'SELECT count(*) AS c FROM t', [], DbResult::rows([['c' => 3]])->toArray()),
        ]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        $statement = $connection->query('SELECT count(*) AS c FROM t');
        $this->assertNotFalse($statement);

        $this->assertSame([['c' => 3]], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAnExecIsServedAsItsRecordedAffectedCount(): void
    {
        $ledger = EffectLedger::forReplay([
            $this->dbEffect(0, 'DELETE FROM t WHERE id = 7', [], DbResult::affected(1)->toArray()),
        ]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        $this->assertSame(1, $connection->exec('DELETE FROM t WHERE id = 7'));
    }

    public function testAQueryTheCassetteDoesNotContainRaisesAndBooksAMiss(): void
    {
        $ledger = EffectLedger::forReplay([]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        try {
            $connection->exec('DELETE FROM t');
            $this->fail('Expected the miss to raise.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no recorded database effect', $e->getMessage());
        }

        $this->assertCount(1, $ledger->misses());
    }

    public function testAGeneratedIdIsReportedAsAMissRatherThanBreakingEveryInsert(): void
    {
        // Propulsion's BasePeer::doInsert() calls lastInsertId() for every auto-increment insert,
        // whether the caller wants the id or not -- so throwing would make any cassette containing
        // an insert unreplayable. It books a miss instead, which lands in the drift report, so a
        // replay whose code went on to *use* the id is reported as diverging.
        $ledger = EffectLedger::forReplay([]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        $this->assertSame('0', $connection->lastInsertId());
        $this->assertCount(1, $ledger->misses());
        $this->assertSame('lastInsertId:', $ledger->misses()[0]['fingerprint']);
    }

    public function testARecordedGeneratedIdIsUsedWhenOneExists(): void
    {
        // Nothing records generated ids today; this is the read side already in place for when a
        // recorder does, rather than a shape that would need retrofitting later.
        $ledger = EffectLedger::forReplay([
            new Effect(0, EffectKind::Db, 'lastInsertId:', ['op' => 'lastInsertId'], 4242),
        ]);
        $connection = new LedgerBackedPropulsionPdo($ledger);

        $this->assertSame('4242', $connection->lastInsertId());
        $this->assertSame([], $ledger->misses());
    }

    public function testTransactionsAreAcceptedAndTracked(): void
    {
        // A replayed request may wrap ledger-served writes in a transaction; failing the BEGIN would
        // break the replay over bookkeeping with nothing to answer for.
        $connection = new LedgerBackedPropulsionPdo(EffectLedger::forReplay([]));

        $this->assertFalse($connection->isInTransaction());
        $this->assertTrue($connection->beginTransaction());
        $this->assertTrue($connection->beginTransaction());
        $this->assertSame(2, $connection->getNestedTransactionCount());
        $this->assertTrue($connection->isInTransaction());

        $this->assertTrue($connection->commit());
        $this->assertSame(1, $connection->getNestedTransactionCount());
        $this->assertTrue($connection->forceRollBack());
        $this->assertSame(0, $connection->getNestedTransactionCount());
        $this->assertFalse($connection->isInTransaction());
    }

    public function testItIsAPdoInstanceBecauseBasePeerRequiresOne(): void
    {
        // Propulsion\Util\BasePeer guards its sequence and autoincrement paths with
        // `if (!$con instanceof \PDO) throw`, twice, and getOpenConnections() filters on the same
        // check -- so implementing the interface alone would not have been enough.
        $connection = new LedgerBackedPropulsionPdo(EffectLedger::forReplay([]));

        $this->assertInstanceOf(PDO::class, $connection);
        $this->assertInstanceOf(\Propulsion\Connection\PropulsionPDO::class, $connection);
    }

    public function testQuotingDoublesEmbeddedQuotesSoFingerprintsStillMatch(): void
    {
        $connection = new LedgerBackedPropulsionPdo(EffectLedger::forReplay([]));

        $this->assertSame("'O''Brien'", $connection->quote("O'Brien"));
    }
}
