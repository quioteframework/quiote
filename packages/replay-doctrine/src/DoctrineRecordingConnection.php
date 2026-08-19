<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdo;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Support\Clock\ClockInterface;

/**
 * Records `query()`/`exec()` called directly on the connection (bypassing a
 * prepared {@see Statement}), and hands back a
 * {@see DoctrineRecordingStatement} from `prepare()` so a prepared statement's
 * own `execute()` is recorded too. Mirrors the shape of
 * `Doctrine\DBAL\Logging\Connection`, DBAL's own reference middleware.
 *
 * Records into {@see ActiveEffectLedger}'s current ledger rather than a fixed
 * one -- see that class's own docblock -- so a query is simply not recorded
 * when nothing is currently active (e.g. a boot-time query run before any
 * request is being recorded), the same as every other recorder in this
 * package does for a call it declines to observe.
 */
final class DoctrineRecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($connection);
    }

    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new DoctrineRecordingStatement(parent::prepare($sql), $sql, $this->clock);
    }

    /**
     * Consults the ledger before touching the result set and hands the real `Result` straight
     * back when nothing is recording -- see {@see DoctrineRecordingStatement::execute()} for why
     * an unconditional snapshot here changed the behaviour of every query in the application.
     */
    #[\Override]
    public function query(string $sql): Result
    {
        $ledger = ActiveEffectLedger::get();
        $start = $this->clock->monotonic();
        $real = parent::query($sql);

        if ($ledger === null) {
            return $real;
        }

        $columnCount = $real->columnCount();
        $rows = $real->fetchAllAssociative();
        $affected = $real->rowCount();

        $ledger->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($sql),
            ['sql' => $sql, 'params' => []],
            $rows,
            RecordingPdo::durationMicros($this->clock, $start),
        );

        return new DoctrineSnapshotResult($rows, $affected, $columnCount);
    }

    /**
     * `exec()` has no result set to snapshot, so there is nothing to skip when the ledger is
     * absent -- the null-safe call is the whole guard.
     */
    #[\Override]
    public function exec(string $sql): int|string
    {
        $start = $this->clock->monotonic();
        $result = parent::exec($sql);

        ActiveEffectLedger::get()?->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($sql),
            ['sql' => $sql, 'params' => []],
            $result,
            RecordingPdo::durationMicros($this->clock, $start),
        );

        return $result;
    }
}
