<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A `Doctrine\DBAL\Driver\Middleware` (DBAL 4's own extension seam, installed
 * via `Doctrine\DBAL\Configuration::setMiddlewares([$middleware])` passed to
 * `Doctrine\DBAL\DriverManager::getConnection($params, $config)`) that
 * appends one {@see \Quiote\Replay\Cassette\EffectKind::Db} entry per query
 * to whichever {@see \Quiote\Replay\Replay\EffectLedger}
 * {@see ActiveEffectLedger} currently holds.
 *
 * Structured after DBAL's own `Doctrine\DBAL\Logging\Middleware` (a
 * Driver/Connection/Statement decorator chain built on the abstract
 * middleware base classes DBAL ships for exactly this purpose), not a raw
 * reimplementation of the `Driver`/`Connection`/`Statement` interfaces.
 *
 * Reads {@see ActiveEffectLedger} rather than taking a fixed `EffectLedger`
 * at construction: a DBAL connection wrapped by this middleware is built
 * once, at `DoctrineDatabase`/`DoctrineDbalDatabase::connect()`, and then
 * recycled (not rebuilt) across every later request in a worker process --
 * see {@see ActiveEffectLedger}'s own docblock for why a fixed ledger would
 * be wrong past the connection's first use.
 *
 * A failing query (bad SQL, a constraint violation, ...) is never recorded:
 * the real exception propagates unchanged and no ledger entry is written for
 * it, matching every other recorder in this package -- a failed call has no
 * result to replay, and no entry is a more honest state than a fabricated
 * one.
 *
 * Wiring this into an application's own Doctrine connection (deciding WHEN to
 * record, redaction, sampling, ...) is `RecorderMiddleware`/plugin territory
 * and is out of scope here; this class only has to work correctly when
 * installed.
 */
final class DoctrineRecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new DoctrineRecordingDriver($driver, $this->clock);
    }
}
