<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Replay\EffectLedger;
use RuntimeException;

/**
 * Builds the {@see DoctrineSnapshotResult} a replaying statement answers with, from the matching
 * recorded effect.
 *
 * Kept out of the statement and connection decorators because both need it and neither should own
 * it, and because the failure cases are the interesting part: what a replay does when the cassette
 * has no counterpart for a query is the difference between a trustworthy replay and one that
 * fabricates a passing run.
 *
 * A miss raises. Answering with an empty result set would be inventing input -- the code would take
 * whichever branch "no rows" leads to and the replay would report a clean run for a query that was
 * never recorded. `EffectLedger::match()` also books the miss, so a caller that catches this still
 * sees it in {@see EffectLedger::misses()}.
 */
final class LedgerServedResult
{
    /**
     * @param array<int|string, mixed> $params
     * @throws RuntimeException if the cassette has no counterpart for this statement.
     */
    public static function forSql(EffectLedger $ledger, string $sql, array $params): DoctrineSnapshotResult
    {
        $result = self::matchedResult($ledger, $sql, $params);

        if ($result->rows === null && $result->affectedRows === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: the recorded effect for "%s" captured no rows, so there is nothing to serve '
                . 'this read from. The cassette was recorded through a seam that cannot see rows '
                . '(quioteframework/replay-{eloquent,cycle}); re-record it through replay-doctrine.',
                RecordingPdoStatement::fingerprintOf($sql),
            ));
        }

        return new DoctrineSnapshotResult(
            $result->rows ?? [],
            $result->affectedRows ?? count($result->rows ?? []),
            // A write has no columns, which is how a caller tells a result set from a write --
            // DoctrineSnapshotResult's own docblock covers why this cannot be derived from the rows.
            $result->rows === null ? 0 : count($result->rows === [] ? [] : array_keys($result->rows[0])),
        );
    }

    /**
     * The affected-row count for a replayed `exec()`.
     *
     * @throws RuntimeException if the cassette has no counterpart, or recorded rows where a count
     *         was expected.
     */
    public static function affectedRowsForSql(EffectLedger $ledger, string $sql): int
    {
        $result = self::matchedResult($ledger, $sql, []);
        if ($result->affectedRows === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: the recorded effect for "%s" carries no affected-row count, so it cannot '
                . 'answer an exec().',
                RecordingPdoStatement::fingerprintOf($sql),
            ));
        }

        return $result->affectedRows;
    }

    /**
     * @param array<int|string, mixed> $params
     * @throws RuntimeException on a miss, or on an effect whose result describes no database call.
     */
    private static function matchedResult(EffectLedger $ledger, string $sql, array $params): DbResult
    {
        $fingerprint = RecordingPdoStatement::fingerprintFor($sql, $params);
        $effect = $ledger->match(EffectKind::Db, $fingerprint);

        if ($effect === null) {
            throw new RuntimeException(sprintf(
                'Isolated replay: no recorded database effect for "%s"%s. The code ran a query the recording '
                . 'does not contain, so there is nothing to answer it with -- serving an empty result would '
                . 'invent the input and report a clean run.',
                RecordingPdoStatement::fingerprintOf($sql),
                $params === [] ? '' : ' with these bound parameters',
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
}
