<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use PDO;
use PDOStatement;
use Quiote\Logging\Log;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A drop-in replacement for `\PDO` (see `Quiote\Database\PdoDatabase::connect()`,
 * which builds `new PDO($dsn, $username, $password, $options)`): connects for
 * real, behaves exactly like a bare `\PDO` to the caller, and additionally
 * appends one {@see EffectKind::Db} entry per statement execution to an
 * injected {@see EffectLedger} -- `query()`/`prepare()->execute()` through
 * {@see RecordingPdoStatement} (installed via `PDO::ATTR_STATEMENT_CLASS`),
 * `exec()` directly, since it has no result set to snapshot.
 *
 * A statement that throws propagates the real exception and records nothing:
 * a failed call has no result to replay, and no ledger entry is a more
 * honest state than a fabricated one.
 */
final class RecordingPdo extends PDO
{
    private readonly EffectLedger $ledger;

    private readonly ClockInterface $clock;

    /**
     * @param array<int, mixed>|null $options
     * @param int $maxSnapshotRows Rows one statement's snapshot holds before it stops capturing;
     *        see {@see RecordingPdoStatement}'s own docblock.
     */
    public function __construct(
        string $dsn,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?array $options = null,
        ?EffectLedger $ledger = null,
        ?ClockInterface $clock = null,
        int $maxSnapshotRows = RecordingPdoStatement::DEFAULT_MAX_SNAPSHOT_ROWS,
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->ledger = $ledger ?? new EffectLedger();
        $this->clock = $clock ?? new SystemClock();

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingPdoStatement::class, [$this->ledger, $this->clock, $maxSnapshotRows]]);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $start = $this->clock->monotonic();
        $result = parent::exec($statement);

        if ($result !== false) {
            $this->ledger->record(
                EffectKind::Db,
                RecordingPdoStatement::fingerprintFor($statement),
                ['sql' => $statement, 'params' => []],
                DbResult::affected($result)->toArray(),
                self::durationMicros($this->clock, $start),
            );
        }

        return $result;
    }

    /**
     * The `$fetchMode`/`$fetchModeArgs` shorthand PDO::query() normally
     * accepts is not supported here -- pass the mode to fetch()/fetchAll()
     * on the returned statement instead.
     *
     * Routed through prepare()+execute() rather than delegating to
     * `parent::query()`: PDO::query() executes through an internal driver path
     * that never calls the statement object's own execute() method, so
     * {@see RecordingPdoStatement}'s override -- and therefore the ledger
     * recording -- would silently never run. Verified empirically against a
     * real sqlite connection, not assumed.
     *
     * `prepare()` is not a transparent substitute for `query()`, though, and
     * that is the reason for the fallback below. A statement some drivers
     * cannot prepare (certain DDL, a multi-statement string, a session `SET`)
     * fails there, and with emulation off a literal `?` or `:name` inside a
     * string literal is read as a placeholder, so `query("SELECT '?'")` errors
     * on a parameter count mismatch. Falling back to the real `parent::query()`
     * means installing this recorder can never turn a working query into a
     * broken one; the cost is that such a query is not recorded, which is
     * strictly better than the alternative and is stated in the effect ledger
     * by its absence rather than by a fabricated entry.
     */
    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode !== null) {
            throw new \RuntimeException('RecordingPdo::query() does not support the $fetchMode shorthand; pass the mode to fetch()/fetchAll() instead.');
        }

        try {
            $statement = $this->prepare($query);
            if ($statement !== false && $statement->execute()) {
                return $statement;
            }
        } catch (\PDOException $e) {
            Log::for($this)->debug(sprintf(
                '[RecordingPdo] query() could not be routed through prepare()/execute() and is running '
                . 'unrecorded via PDO::query(): %s',
                $e->getMessage(),
            ));
        }

        return parent::query($query);
    }

    /** @return non-negative-int */
    public static function durationMicros(ClockInterface $clock, float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
