<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Propulsion;

use PDO;
use PDOStatement;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Psr\Log\LoggerInterface;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\StubbedPdoStatement;
use RuntimeException;

/**
 * A Propulsion connection that answers from a replaying {@see EffectLedger} and never opens a
 * database.
 *
 * Substituting the connection is the only way to isolate Propulsion, and it turns out to be the
 * better seam anyway. Propulsion's observers cannot intercept: `QueryObserver`'s own contract says
 * "an observer must not throw ... telemetry breaking the query it is measuring is a strictly worse
 * outcome than losing the telemetry", and `queryStarted()` has no return channel. Giving that
 * interface the power to replace a result would turn every existing observer -- `SlowQueryObserver`,
 * `QueryStatsObserver`, `OpenTelemetryQueryObserver` -- into something that could lie about a query.
 * `Propulsion::setConnection()` replaces what the observers observe instead, which keeps observation
 * and control apart.
 *
 * Extends `\PDO` as well as implementing `PropulsionPDO`, deliberately and necessarily: the
 * interface alone is not enough, because `Propulsion\Util\BasePeer` guards its sequence/autoincrement
 * paths with `if (!$con instanceof \PDO) throw` (twice), and `Propulsion::getOpenConnections()`
 * filters on the same check. `parent::__construct()` is never called, so nothing is opened -- the
 * shape `GenericPropulsionPDO` has minus the connecting, and the same trick
 * {@see \Quiote\Replay\Replay\StubbedPdo} already relies on.
 *
 * Liveness is answered rather than refused, because `Propulsion::checkOutPooled()` will *replace* a
 * pooled connection that fails its check -- and replacing this one would reopen a real connection
 * mid-replay, quietly undoing the isolation. Reporting zero idle seconds means the check is normally
 * skipped outright; `ping()` returning true covers the case where it is not.
 */
class LedgerBackedPropulsionPdo extends PDO implements PropulsionPDO
{
    /** Nested transaction depth, tracked because Propulsion asks and nothing else would answer. */
    private int $nestedTransactionCount = 0;

    private bool $isUncommitable = false;

    private ?PropulsionConfiguration $configuration = null;

    private ?LoggerInterface $logger = null;

    private string $lastExecutedQuery = '';

    private int $queryCount = 0;

    /**
     * Deliberately does not call `parent::__construct()`: an isolated replay opens nothing. Every
     * method below either answers from the ledger or from local state, so no driver handle is ever
     * needed.
     */
    public function __construct(private readonly EffectLedger $ledger)
    {
    }

    // ---- the part that actually serves the replay ----------------------------------------

    /**
     * @param array<int, mixed> $driver_options
     */
    public function prepare($sql, $driver_options = []): false|PDOStatement
    {
        // StubbedPdoStatement collects bound values and matches on the same
        // normalized-SQL-plus-parameter digest the recorder wrote, which is what tells two
        // executions of one prepared statement with different values apart.
        //
        // A plain PDOStatement rather than a PropulsionStatement: the one place Propulsion wants
        // that specific class, `DBAdapter`'s setPendingColumn() call, is guarded by an
        // `instanceof PropulsionStatement` check, so it is skipped rather than fatal -- and what it
        // attaches is table/column identity for redaction, which a replay has no use for since the
        // values it serves were already redacted when they were recorded.
        return new StubbedPdoStatement($this->ledger, (string) $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $statement = new StubbedPdoStatement($this->ledger, $query);
        $statement->execute();

        return $statement;
    }

    public function exec($sql): false|int
    {
        $result = $this->matchedResult((string) $sql);
        if ($result->affectedRows === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: the recorded effect for "%s" carries no affected-row count, so it cannot '
                . 'answer an exec().',
                RecordingPdoStatement::fingerprintOf((string) $sql),
            ));
        }

        return $result->affectedRows;
    }

    /**
     * The generated id an auto-increment insert would have produced.
     *
     * Nothing records generated ids, so there is nothing to replay here -- but unlike Doctrine,
     * where `lastInsertId()` is only called when the application asks, Propulsion's
     * `BasePeer::doInsert()` calls it for *every* auto-increment insert whether the caller wants the
     * id or not. Throwing would therefore make any cassette containing an insert unreplayable, which
     * is too blunt.
     *
     * So it asks the ledger first -- if a future recorder does capture generated ids, this already
     * reads them -- and the ask books a miss when nothing answers. That miss lands in the drift
     * report as `REPLAY_EFFECT_MISS`, so a replay whose code went on to *use* the id is reported as
     * diverging rather than quietly trusted. The placeholder returned meanwhile is deliberately not
     * a plausible id: a replay that treats it as real should look wrong immediately.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        $effect = $this->ledger->match(EffectKind::Db, 'lastInsertId:' . ($name ?? ''));
        if ($effect !== null && (is_string($effect->result) || is_int($effect->result))) {
            return (string) $effect->result;
        }

        return '0';
    }

    private function matchedResult(string $sql): DbResult
    {
        $fingerprint = RecordingPdoStatement::fingerprintFor($sql);
        $effect = $this->ledger->match(EffectKind::Db, $fingerprint);
        if ($effect === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: no recorded database effect for "%s". The code ran a query the recording '
                . 'does not contain, so there is nothing to answer it with -- serving an empty result would '
                . 'invent the input and report a clean run.',
                RecordingPdoStatement::fingerprintOf($sql),
            ));
        }

        $result = DbResult::fromResult($effect->result);
        if ($result === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: the recorded effect for "%s" carries a %s result, which does not describe a '
                . 'database call at all. The cassette has most likely been edited.',
                RecordingPdoStatement::fingerprintOf($sql),
                get_debug_type($effect->result),
            ));
        }

        return $result;
    }

    // ---- transactions: accepted, and nothing to do ---------------------------------------

    /**
     * Accepted rather than refused. A replayed request may well wrap writes that are themselves
     * being served from the ledger in a transaction, and failing the `BEGIN` would break the replay
     * over bookkeeping with nothing to answer for -- there is no state to commit or roll back when
     * nothing was performed. The nesting count is still tracked, because Propulsion reads it.
     */
    public function beginTransaction(): bool
    {
        $this->nestedTransactionCount++;

        return true;
    }

    public function commit(): bool
    {
        $this->nestedTransactionCount = max(0, $this->nestedTransactionCount - 1);

        return true;
    }

    public function rollBack(): bool
    {
        $this->nestedTransactionCount = max(0, $this->nestedTransactionCount - 1);

        return true;
    }

    public function forceRollBack(): bool
    {
        $this->nestedTransactionCount = 0;
        $this->isUncommitable = false;

        return true;
    }

    public function getNestedTransactionCount(): int
    {
        return $this->nestedTransactionCount;
    }

    public function isInTransaction(): bool
    {
        return $this->nestedTransactionCount > 0;
    }

    public function isCommitable(): bool
    {
        return !$this->isUncommitable;
    }

    // ---- pooling and liveness: never let this be swapped for a real connection -----------

    /** Zero, so `Propulsion::checkOutPooled()` skips the liveness check entirely. */
    public function getIdleSeconds(): float
    {
        return 0.0;
    }

    /** True, so a liveness check that does run cannot cause a real connection to replace this one. */
    public function ping(): bool
    {
        return true;
    }

    public function touchActivity(): void
    {
    }

    /**
     * A dropped connection is not a thing that can happen here, and the real implementation's job --
     * evicting the pooled connection so a replacement is opened -- is exactly what must not happen
     * mid-replay.
     */
    public function handleDroppedConnection(\PDOException $e, string $methodName = ''): void
    {
    }

    // ---- attributes, quoting, errors -----------------------------------------------------

    public function setAttribute($attribute, $value): bool
    {
        return true;
    }

    public function getAttribute($attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'isolated-replay',
            PDO::ATTR_SERVER_VERSION, PDO::ATTR_CLIENT_VERSION => '0.0.0-isolated-replay',
            default => null,
        };
    }

    /**
     * ANSI quoting, doubling embedded quotes.
     *
     * No driver is present to ask, and the result never reaches a database -- it can only end up
     * inside SQL this same object then answers from the ledger by fingerprint. Correct escaping still
     * matters for that fingerprint to match what the recorder saw.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    public function errorCode(): ?string
    {
        return '00000';
    }

    // ---- configuration, logging and debug bookkeeping ------------------------------------

    /** @param PropulsionConfiguration $configuration */
    public function setConfiguration($configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * An empty configuration when none was injected, because the interface promises a
     * `PropulsionConfiguration` rather than a nullable one, and a caller that reads a setting off it
     * should get the default rather than a null dereference.
     */
    public function getConfiguration(): PropulsionConfiguration
    {
        return $this->configuration ??= new PropulsionConfiguration();
    }

    public function clearStatementCache(): void
    {
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function incrementQueryCount(): int
    {
        return ++$this->queryCount;
    }

    public function getLastExecutedQuery(): string
    {
        return $this->lastExecutedQuery;
    }

    public function setLastExecutedQuery($query): void
    {
        $this->lastExecutedQuery = (string) $query;
    }

    public function resetDebugCounters(): void
    {
        $this->queryCount = 0;
        $this->lastExecutedQuery = '';
    }

    public function useDebug($value = true): void
    {
    }

    public function setLogLevel($level): void
    {
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param string $msg
     * @param array<string, float|int>|null $debugSnapshot
     */
    public function log($msg, $level = null, $methodName = null, ?array $debugSnapshot = null): void
    {
        $this->logger?->debug($msg);
    }

    /**
     * The same timing and memory keys a real connection reports, because that is what the interface
     * promises and what a caller subtracts one snapshot from another to get.
     *
     * Unlike the real implementation this does not throw when debugging is off: a stub has no
     * configuration to consult, and failing here would break a replay over telemetry.
     *
     * @return array<string, float|int>
     */
    public function getDebugSnapshot(): array
    {
        return [
            'microtime' => microtime(true),
            'memory_get_usage' => memory_get_usage(),
            'memory_get_peak_usage' => memory_get_peak_usage(),
        ];
    }
}
