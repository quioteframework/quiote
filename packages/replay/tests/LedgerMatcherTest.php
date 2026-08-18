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
        $this->assertSame('row-a', $matched->result);
    }

    public function testSkipsAlreadyConsumedSeqsWhenFallingBackToSequence(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'select a', [], 'row-a'),
            new Effect(1, EffectKind::Db, 'select b', [], 'row-b'),
        ];

        $matched = LedgerMatcher::match($effects, [0 => true], EffectKind::Db, 'no-such-fingerprint');

        $this->assertNotNull($matched);
        $this->assertSame('row-b', $matched->result);
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
}
