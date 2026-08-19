<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\LedgerMatcher;

final class LedgerMatcherTest extends TestCase
{
    public function testReturnsNullOnAnEmptyEffectList(): void
    {
        $this->assertNull(LedgerMatcher::match([], [], EffectKind::Db, 'select 1'));
    }

    public function testFingerprintMatchTakesPriorityOverSequenceOrder(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select b', [], 'row-b'),
            new Effect(1, EffectKind::Db, 'select a', [], 'row-a'),
        ];

        // Sequence order would pick seq 0 first; the fingerprint match for
        // "select a" must win instead.
        $matched = LedgerMatcher::match($effects, [], EffectKind::Db, 'select a');

        $this->assertNotNull($matched);
        $this->assertFalse($matched->fuzzy, 'An identical fingerprint is an exact match.');
        $this->assertSame('row-a', $matched->effect->result);
    }

    public function testSkipsAlreadyConsumedSeqsWhenFallingBackToSequence(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select a', [], 'row-a'),
            new Effect(1, EffectKind::Db, 'select b', [], 'row-b'),
        ];

        $matched = LedgerMatcher::match($effects, [0 => true], EffectKind::Db, 'no-such-fingerprint');

        $this->assertNotNull($matched);
        $this->assertSame('row-b', $matched->effect->result);
    }

    public function testASequenceFallbackIsReportedAsFuzzy(): void
    {
        // The matcher hands back a different call's recorded result here. Saying so is what lets
        // the ledger refuse it instead of passing it off as the right answer.
        $effects = [
            new Effect(0, EffectKind::Db, 'select a', [], 'row-a'),
            new Effect(1, EffectKind::Db, 'select b', [], 'row-b'),
        ];

        $matched = LedgerMatcher::match($effects, [], EffectKind::Db, 'select c');

        $this->assertNotNull($matched);
        $this->assertTrue($matched->fuzzy);
        $this->assertSame('select a', $matched->effect->fingerprint, 'The fallback takes the next unconsumed effect.');
    }

    public function testSkipsAlreadyConsumedSeqsEvenOnAFingerprintMatch(): void
    {
        $effects = [new Effect(0, EffectKind::Db, 'select a', [], 'row-a')];

        $this->assertNull(LedgerMatcher::match($effects, [0 => true], EffectKind::Db, 'select a'));
    }

    public function testIgnoresEffectsOfADifferentKind(): void
    {
        $effects = [new Effect(0, EffectKind::Http, 'select a', [], 'response-a')];

        $this->assertNull(LedgerMatcher::match($effects, [], EffectKind::Db, 'select a'));
    }

    public function testAFuzzyFallbackNeverCrossesKinds(): void
    {
        // The fallback is positional within a kind, so an unconsumed HTTP effect must not be
        // offered to a database call however few Db effects remain.
        $effects = [
            new Effect(0, EffectKind::Http, 'GET /x', [], 'response'),
            new Effect(1, EffectKind::Db, 'select a', [], 'row-a'),
        ];

        $matched = LedgerMatcher::match($effects, [1 => true], EffectKind::Db, 'select z');

        $this->assertNull($matched);
    }
}
