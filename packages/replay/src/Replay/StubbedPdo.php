<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use PDO;
use PDOStatement;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;

/**
 * The isolated-replay counterpart to `Quiote\Replay\Db\RecordingPdo`: never
 * calls `parent::__construct()`, so no real connection is ever attempted, and
 * answers every `exec()`/`query()`/`prepare()->execute()` from an injected
 * {@see EffectLedger} via {@see StubbedPdoStatement}.
 *
 * Only the statement-producing surface (`exec()`, `query()`, `prepare()`) is
 * implemented -- transactions, `lastInsertId()` and the rest of `\PDO` are out
 * of scope for this iteration and are not called by anything that goes
 * through this class.
 */
final class StubbedPdo extends PDO
{
    public function __construct(private readonly EffectLedger $ledger)
    {
        // Deliberately does not call parent::__construct(): isolated replay
        // never opens a real connection.
    }

    /**
     * Narrows `\PDO::exec()`'s native `int|false` return type: this
     * implementation always either answers from the ledger or throws, so it
     * never has a `false` (driver-level failure) case to represent.
     */
    #[\Override]
    public function exec(string $statement): int
    {
        $fingerprint = RecordingPdoStatement::fingerprintOf($statement);
        $effect = $this->ledger->match(EffectKind::Db, $fingerprint);
        if ($effect === null) {
            throw new \RuntimeException(sprintf('StubbedPdo: no recorded database effect for SQL "%s".', $fingerprint));
        }
        if (!is_int($effect->result)) {
            throw new \RuntimeException(sprintf('StubbedPdo: recorded effect for SQL "%s" is not an exec() row count.', $fingerprint));
        }

        return $effect->result;
    }

    /** Narrows `\PDO::query()`'s native `PDOStatement|false` return type; see {@see exec()}. */
    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
    {
        $statement = $this->prepare($query);
        $statement->execute();

        return $statement;
    }

    /**
     * Narrows `\PDO::prepare()`'s native `PDOStatement|false` return type; see
     * {@see exec()}.
     *
     * @param array<int, mixed> $options Accepted for signature compatibility; unused.
     */
    #[\Override]
    public function prepare(string $query, array $options = []): PDOStatement
    {
        return new StubbedPdoStatement($this->ledger, $query);
    }
}
