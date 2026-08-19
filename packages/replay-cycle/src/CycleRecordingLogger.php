<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Cycle;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\ActiveEffectLedger;

/**
 * Records one {@see EffectKind::Db} entry per successful query on a Cycle
 * (`cycle/database`) connection, via Cycle's own PSR-3 logger seam --
 * `Cycle\Database\Driver\Driver::statement()` logs every query through
 * whatever `Psr\Log\LoggerInterface` was installed on it, at `info` on
 * success and `error`+`alert` on failure (read directly from
 * `vendor/cycle/database/src/Driver/Driver.php`, not assumed). Wiring this
 * logger onto a connection is `Cycle\Database\DatabaseManager::setLogger()`,
 * called before any driver is resolved so the manager's
 * `getLoggerForDriver()` fallback picks it up for every driver it creates.
 *
 * Only the `info` level is recorded -- a failed query logs at `error`, which
 * this class deliberately ignores, matching every other recorder in this
 * package's rule that a failed call is never given a fabricated ledger
 * entry. The real exception still propagates from `Driver::statement()`
 * regardless of what this logger does; PSR-3 logging is a side channel, not
 * part of the call's control flow.
 *
 * `$context['rowCount']`/`$context['elapsed']` are always present (set by
 * `Driver::defineLoggerContext()`); `$context['parameters']` is only present
 * when the driver's `logQueryParameters` option is enabled (default `false`)
 * -- absent otherwise, recorded here as an empty list in that case.
 *
 * Records into {@see ActiveEffectLedger}'s current ledger rather than a
 * fixed one taken at construction: this logger is installed once, at
 * `CycleDatabase::connect()`, and per that adapter's own docblock Cycle is
 * "the data-mapper built for long-running (RoadRunner/FrankenPHP) processes"
 * -- its `DatabaseManager` is recycled (not rebuilt) across every later
 * request in a worker. See {@see ActiveEffectLedger}'s own docblock for why
 * a fixed ledger would be wrong past the connection's first use. A query
 * that runs with nothing currently active (e.g. before any request is being
 * recorded) is simply not recorded.
 */
final class CycleRecordingLogger extends AbstractLogger
{
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level !== LogLevel::INFO) {
            return;
        }

        $sql = (string) $message;
        $elapsedSeconds = $context['elapsed'] ?? null;
        $durationMicros = is_numeric($elapsedSeconds) ? max(0, (int) round(((float) $elapsedSeconds) * 1_000_000)) : null;
        $rowCount = $context['rowCount'] ?? null;
        $parameters = is_array($context['parameters'] ?? null) ? $context['parameters'] : [];

        ActiveEffectLedger::get()?->record(
            EffectKind::Db,
            self::fingerprintOf($sql),
            ['sql' => $sql, 'parameters' => $parameters],
            is_int($rowCount) ? $rowCount : null,
            $durationMicros,
        );
    }

    /** Trim + collapse internal whitespace runs; deliberately not full SQL normalization. */
    public static function fingerprintOf(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
