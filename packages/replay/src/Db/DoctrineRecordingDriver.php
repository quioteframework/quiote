<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use SensitiveParameter;

final class DoctrineRecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($driver);
    }

    #[\Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        return new DoctrineRecordingConnection(parent::connect($params), $this->ledger, $this->clock);
    }
}
