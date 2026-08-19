<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Support\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Wraps the real driver so every connection it builds records -- or, during an isolated replay,
 * never opens at all.
 *
 * See {@see LedgerBackedConnection} for why both seams are needed: refusing to execute keeps a
 * replay's queries away from production, and refusing to *connect* is what lets one run where no
 * database is reachable.
 */
final class DoctrineRecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($driver);
    }

    #[\Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $ledger = ActiveEffectLedger::get();
        if ($ledger !== null && $ledger->isReplaying()) {
            // Deliberately not calling parent::connect(): a replay has nothing to connect for, and
            // requiring a reachable database to serve recorded rows would defeat the point.
            return new LedgerBackedConnection($ledger);
        }

        return new DoctrineRecordingConnection(parent::connect($params), $this->clock);
    }
}
