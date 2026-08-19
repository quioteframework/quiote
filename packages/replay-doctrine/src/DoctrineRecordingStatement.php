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

    #[\Override]
    public function execute(): Result
    {
        $start = $this->clock->monotonic();
        $real = parent::execute();
        $rows = $real->fetchAllAssociative();
        $affected = $real->rowCount();

        ActiveEffectLedger::get()?->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($this->sql),
            ['sql' => $this->sql, 'params' => $this->params],
            $rows,
            RecordingPdo::durationMicros($this->clock, $start),
        );

        return new DoctrineSnapshotResult($rows, $affected);
    }
}
