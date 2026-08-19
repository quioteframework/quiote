<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
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
            RecordingPdoStatement::fingerprintOf($this->sql),
            ['sql' => $this->sql, 'params' => $this->params],
            $rows,
            RecordingPdo::durationMicros($this->clock, $start),
        );

        return new DoctrineSnapshotResult($rows, $affected, $columnCount);
    }
}
