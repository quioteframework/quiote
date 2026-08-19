<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;

/**
 * An EffectLedger is written to by recording (append-only, assigning
 * sequence numbers) and read from by replay (fingerprint-then-sequence
 * matching, with match/miss accounting).
 */
final class EffectLedgerTest extends TestCase
{
    public function testRecordAssignsSequentialSeqNumbers(): void
    {
        $ledger = new EffectLedger();

        $first = $ledger->record(EffectKind::Db, 'select 1', [], 'row-a');
        $second = $ledger->record(EffectKind::Db, 'select 2', [], 'row-b');

        $this->assertSame(0, $first->seq);
        $this->assertSame(1, $second->seq);
    }

    public function testRecordedEffectsAreReturnedByAll(): void
    {
        $ledger = new EffectLedger();
        $ledger->record(EffectKind::Http, 'GET /a', [], 'response-a');
        $ledger->record(EffectKind::Http, 'GET /b', [], 'response-b');

        $all = $ledger->all();

        $this->assertCount(2, $all);
        $this->assertSame('GET /a', $all[0]->fingerprint);
        $this->assertSame('GET /b', $all[1]->fingerprint);
    }

    public function testMatchReturnsTheEffectWithTheSameFingerprintAndKind(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select 1', [], 'row-a'),
            new Effect(1, EffectKind::Http, 'GET /x', [], 'response-x'),
        ];
        $ledger = new EffectLedger($effects);

        $matched = $ledger->match(EffectKind::Db, 'select 1');

        $this->assertNotNull($matched);
        $this->assertSame('row-a', $matched->result);
    }

    public function testMatchDoesNotCrossKindBoundaries(): void
    {
        $effects = [new Effect(0, EffectKind::Cache, 'same-key', [], 'cached-value')];
        $ledger = new EffectLedger($effects);

        $this->assertNull($ledger->match(EffectKind::Db, 'same-key'));
    }

    public function testMatchFallsBackToSequencePositionWhenFingerprintDoesNotMatch(): void
    {
        // Two db effects recorded in order; a replayed query with an
        // unrecognized fingerprint still lands on the next unconsumed one of
        // the same kind.
        $effects = [
            new Effect(0, EffectKind::Db, 'select a', [], 'row-a'),
            new Effect(1, EffectKind::Db, 'select b', [], 'row-b'),
        ];
        $ledger = new EffectLedger($effects);

        $matched = $ledger->match(EffectKind::Db, 'unrecognized-fingerprint');

        $this->assertNotNull($matched);
        $this->assertSame('row-a', $matched->result);
    }

    public function testTwoIdenticalCallsMatchInRecordedOrder(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select * from t', [], 'first-row'),
            new Effect(1, EffectKind::Db, 'select * from t', [], 'second-row'),
        ];
        $ledger = new EffectLedger($effects);

        $first = $ledger->match(EffectKind::Db, 'select * from t');
        $second = $ledger->match(EffectKind::Db, 'select * from t');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('first-row', $first->result);
        $this->assertSame('second-row', $second->result);
    }

    public function testAMatchedEffectIsNotMatchedAgain(): void
    {
        $effects = [new Effect(0, EffectKind::Db, 'select 1', [], 'row-a')];
        $ledger = new EffectLedger($effects);

        $first = $ledger->match(EffectKind::Db, 'select 1');
        $second = $ledger->match(EffectKind::Db, 'select 1');

        $this->assertNotNull($first);
        $this->assertNull($second, 'the only recorded effect of this kind was already consumed');
    }

    public function testMatchReturnsNullAndRecordsAMissWhenNothingOfThatKindRemains(): void
    {
        $ledger = new EffectLedger();

        $result = $ledger->match(EffectKind::Db, 'select 1');

        $this->assertNull($result);
        $this->assertSame([['kind' => EffectKind::Db, 'fingerprint' => 'select 1']], $ledger->misses());
    }

    public function testUnplayedReturnsEffectsNothingAsked(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select a', [], 'row-a'),
            new Effect(1, EffectKind::Db, 'select b', [], 'row-b'),
        ];
        $ledger = new EffectLedger($effects);

        $ledger->match(EffectKind::Db, 'select a');

        $unplayed = $ledger->unplayed();
        $this->assertCount(1, $unplayed);
        $this->assertSame('select b', $unplayed[0]->fingerprint);
    }

    public function testUnplayedIsEmptyWhenEveryEffectWasConsumed(): void
    {
        $effects = [new Effect(0, EffectKind::Db, 'select a', [], 'row-a')];
        $ledger = new EffectLedger($effects);

        $ledger->match(EffectKind::Db, 'select a');

        $this->assertSame([], $ledger->unplayed());
    }

    public function testMissesAccumulateAcrossMultipleFailedMatches(): void
    {
        $ledger = new EffectLedger();

        $ledger->match(EffectKind::Db, 'select a');
        $ledger->match(EffectKind::Http, 'GET /missing');

        $this->assertCount(2, $ledger->misses());
    }
}
