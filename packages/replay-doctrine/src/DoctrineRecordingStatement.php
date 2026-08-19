<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Quiote\Replay\Adapter\Doctrine\LedgerServedResult;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdo;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Support\Clock\ClockInterface;

/**
 * Records one {@see EffectKind::Db} entry per `execute()`, following the same
 * shape as {@see \Quiote\Replay\Db\RecordingPdoStatement}: bound parameters
 * are captured via `bindValue()` (mirroring `Doctrine\DBAL\Logging\Statement`,
 * DBAL's own reference middleware for observing a statement), and the real
 * `Result` is snapshotted once into a {@see DoctrineSnapshotResult} so the
 * caller's own fetch calls keep working after the row set has been read once
 * for the ledger.
 *
 * Records into {@see ActiveEffectLedger}'s current ledger -- see that
 * class's own docblock for why a statement built once around a recycled
 * connection cannot take a fixed ledger at construction.
 */
final class DoctrineRecordingStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    public function __construct(
        Statement $statement,
        private readonly string $sql,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($statement);
    }

    #[\Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;

        parent::bindValue($param, $value, $type);
    }

    /**
     * The ledger is consulted *before* the result set is touched, and the real `Result` is
     * handed straight back when nothing is recording.
     *
     * This middleware is installed on the connection permanently, by
     * `ReplayDoctrinePlugin`'s driver-alias registration -- it is not gated on
     * `replay.enabled`, because a connection is built once and recycled for the rest of the
     * worker's life, long before any request has said whether it wants recording. Snapshotting
     * unconditionally therefore meant every query in the application was fully materialized
     * into PHP memory for the entire life of the process: unbuffered and cursor-based reads
     * became impossible, and a caller streaming a large result set paid for the whole of it.
     * Measured on a real DBAL connection streaming 20 000 rows with no ledger active, the
     * snapshot cost 12 MiB of peak memory against 0 for the undecorated connection.
     *
     * Passing the real `Result` through is also the more correct answer, not merely the cheaper
     * one: {@see DoctrineSnapshotResult} is a faithful stand-in only for what a recorded query
     * needs, and a caller that is not being recorded has no reason to receive it.
     */
    #[\Override]
    public function execute(): Result
    {
        $ledger = ActiveEffectLedger::get();

        // Before parent::execute(), and that ordering is the whole of isolated replay: a replaying
        // ledger means this statement must not reach the connection at all, so the recorded rows are
        // served instead. DBAL's driver middleware is a decorator called *instead of* the real
        // statement, which is what makes this possible here and impossible through an
        // after-the-fact seam like Eloquent's QueryExecuted event -- see IsolatesFromLedger.
        if ($ledger !== null && $ledger->isReplaying()) {
            return LedgerServedResult::forSql($ledger, $this->sql, $this->params);
        }

        $start = $this->clock->monotonic();
        $real = parent::execute();

        if ($ledger === null) {
            return $real;
        }

        $columnCount = $real->columnCount();
        $rows = $real->fetchAllAssociative();
        $affected = $real->rowCount();

        $ledger->record(
            EffectKind::Db,
            // Parameter-aware, matching the PDO recorder: SQL alone cannot tell two executions of
            // one prepared statement with different bound values apart.
            RecordingPdoStatement::fingerprintFor($this->sql, $this->params),
            ['sql' => $this->sql, 'params' => $this->params],
            // A statement with no columns is a write, and its affected count is the only result it
            // has. Recording the empty row list for it lost that count outright, so a replayed
            // write reported zero rows affected regardless of what really happened.
            ($columnCount > 0 ? DbResult::rows($rows) : DbResult::affected(self::asInt($affected)))->toArray(),
            RecordingPdo::durationMicros($this->clock, $start),
        );

        return new DoctrineSnapshotResult($rows, $affected, $columnCount);
    }

    /** DBAL's `rowCount()` is `int|numeric-string`; the ledger records a plain int. */
    private static function asInt(int|string $affected): int
    {
        return is_int($affected) ? $affected : (int)$affected;
    }
}
