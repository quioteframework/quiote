<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The statement {@see LedgerBackedConnection} prepares: collects bound values, then answers from the
 * ledger by the same fingerprint the recorder wrote.
 *
 * Bound values are collected rather than ignored because they are half the fingerprint. Without them
 * two executions of one prepared statement in a loop would be indistinguishable and could only be
 * matched by position -- see `Quiote\Replay\Db\RecordingPdoStatement::fingerprintFor()`.
 */
final class LedgerBackedStatement implements Statement
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    public function __construct(
        private readonly EffectLedger $ledger,
        private readonly string $sql,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;
    }

    public function execute(): Result
    {
        return LedgerServedResult::forSql($this->ledger, $this->sql, $this->params);
    }
}
