<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use PDO;
use PDOStatement;
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

    /** @param array<int, mixed>|null $options */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        ?EffectLedger $ledger = null,
        ?ClockInterface $clock = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->ledger = $ledger ?? new EffectLedger();
        $this->clock = $clock ?? new SystemClock();

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingPdoStatement::class, [$this->ledger, $this->clock]]);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $start = $this->clock->monotonic();
        $result = parent::exec($statement);

        if ($result !== false) {
            $this->ledger->record(
                EffectKind::Db,
                RecordingPdoStatement::fingerprintOf($statement),
                ['sql' => $statement, 'params' => []],
                $result,
                self::durationMicros($this->clock, $start),
            );
        }

        return $result;
    }

    /**
     * The `$fetchMode`/`$fetchModeArgs` shorthand PDO::query() normally
     * accepts is not supported here -- pass the mode to fetch()/fetchAll()
     * on the returned statement instead.
     */
    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode !== null) {
            throw new \RuntimeException('RecordingPdo::query() does not support the $fetchMode shorthand; pass the mode to fetch()/fetchAll() instead.');
        }

        // Deliberately NOT delegating to parent::query(): PDO::query()
        // executes through an internal driver path that never calls the
        // statement object's own execute() method, so RecordingPdoStatement's
        // override -- and therefore the ledger recording -- would silently
        // never run. Routing through prepare()+execute() instead uses the
        // normal method dispatch, which does hit the override. Verified
        // empirically against a real sqlite connection, not assumed.
        $statement = $this->prepare($query);
        if ($statement === false) {
            return false;
        }

        $statement->execute();

        return $statement;
    }

    /** @return non-negative-int */
    public static function durationMicros(ClockInterface $clock, float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
