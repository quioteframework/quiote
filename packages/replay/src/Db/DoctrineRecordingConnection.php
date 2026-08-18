<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;

/**
 * Records `query()`/`exec()` called directly on the connection (bypassing a
 * prepared {@see Statement}), and hands back a
 * {@see DoctrineRecordingStatement} from `prepare()` so a prepared statement's
 * own `execute()` is recorded too. Mirrors the shape of
 * `Doctrine\DBAL\Logging\Connection`, DBAL's own reference middleware.
 */
final class DoctrineRecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($connection);
    }

    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new DoctrineRecordingStatement(parent::prepare($sql), $this->ledger, $sql, $this->clock);
    }

    #[\Override]
    public function query(string $sql): Result
    {
        $start = $this->clock->monotonic();
        $real = parent::query($sql);
        $rows = $real->fetchAllAssociative();
        $affected = $real->rowCount();
        $duration = RecordingPdo::durationMicros($this->clock, $start);

        $this->ledger->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($sql),
            ['sql' => $sql, 'params' => []],
            $rows,
            $duration,
        );

        return new DoctrineSnapshotResult($rows, $affected);
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        $start = $this->clock->monotonic();
        $result = parent::exec($sql);
        $duration = RecordingPdo::durationMicros($this->clock, $start);

        $this->ledger->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($sql),
            ['sql' => $sql, 'params' => []],
            $result,
            $duration,
        );

        return $result;
    }
}
