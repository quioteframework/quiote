<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Quiote\Replay\Replay\EffectLedger;
use RuntimeException;

/**
 * A DBAL driver connection that answers entirely from a replaying {@see EffectLedger} and never
 * opens anything.
 *
 * {@see DoctrineRecordingStatement} and {@see DoctrineRecordingConnection} already keep a replay's
 * queries away from the real database by refusing to execute them, which is enough to isolate a
 * replay from *production*. It is not enough to run one where there is no database at all: DBAL
 * connects lazily on first use, so the connect itself still had to succeed, and a cassette replayed
 * on a laptop with no server reachable failed before any statement ran.
 *
 * This is what {@see DoctrineRecordingDriver::connect()} returns instead when a replay is already
 * active. It only helps for a connection first used *during* the replay -- a connection the worker
 * built and recycled earlier is already open, and the statement-level refusal is what covers that
 * one. Both seams together mean a replay neither reads, writes, nor requires a database.
 *
 * Transaction control is accepted and does nothing. A replayed request may well open a transaction
 * around writes that are themselves being served from the ledger, and refusing the `BEGIN` would
 * fail the replay over bookkeeping that has nothing to answer for -- there is no state to commit or
 * roll back when nothing was performed.
 */
final class LedgerBackedConnection implements DriverConnection
{
    public function __construct(private readonly EffectLedger $ledger)
    {
    }

    public function prepare(string $sql): Statement
    {
        return new LedgerBackedStatement($this->ledger, $sql);
    }

    public function query(string $sql): Result
    {
        return LedgerServedResult::forSql($this->ledger, $sql, []);
    }

    /**
     * Narrows the interface's `int|string` to `int`: a recorded affected-row count is always an int
     * by the time `DbResult` has read it back, so there is no numeric-string case to represent.
     */
    public function exec(string $sql): int
    {
        return LedgerServedResult::affectedRowsForSql($this->ledger, $sql);
    }

    /**
     * Quotes with the ANSI rule, doubling embedded quotes.
     *
     * No driver is present to ask, and the result never reaches a database -- it can only end up
     * inside SQL that this same class then answers from the ledger by fingerprint. Correct escaping
     * still matters for that fingerprint to match what the recorder saw.
     */
    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @throws RuntimeException always: nothing was inserted, so there is no id, and inventing one
     *         would let a replay proceed on a value the recording never contained.
     */
    public function lastInsertId(): int|string
    {
        throw new RuntimeException(
            'Isolated replay: lastInsertId() has no answer, because no row was inserted -- the write was '
            . 'served from the cassette. Nothing records generated ids, so there is nothing to replay here; '
            . 'use --live if the code under test depends on one.',
        );
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    /**
     * A version string, because DBAL asks the connection for one while building its platform -- and
     * that happens before any query, so throwing here would stop a replay dead.
     *
     * Reported as the sqlite-shaped placeholder the platform detection accepts rather than a real
     * version: there is no server to ask, and a replay's SQL is answered by fingerprint rather than
     * dialect, so nothing downstream depends on it being accurate.
     */
    public function getServerVersion(): string
    {
        return '0.0.0-isolated-replay';
    }

    /** No native handle exists, and callers are documented to expect one only for a real driver. */
    public function getNativeConnection(): never
    {
        throw new RuntimeException(
            'Isolated replay: there is no native database connection to hand out -- nothing was opened.',
        );
    }
}
