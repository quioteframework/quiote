<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The single-active-request invariant was asserted in a docblock rather than enforced, and dispatch
 * does re-enter -- `ReplayEngine` and `ReplayTestCase` both hand a request to the real handler,
 * potentially from inside one that is recording. A single slot meant the inner deactivate cleared
 * recording for the rest of the outer request with nothing to say so.
 */
final class ActiveEffectLedgerTest extends TestCase
{
    protected function tearDown(): void
    {
        ActiveEffectLedger::reset();
        parent::tearDown();
    }

    public function testNothingIsActiveByDefault(): void
    {
        $this->assertNull(ActiveEffectLedger::get());
        $this->assertSame(0, ActiveEffectLedger::depth());
    }

    public function testTheMostRecentlyActivatedLedgerIsTheActiveOne(): void
    {
        $outer = new EffectLedger();
        $inner = new EffectLedger();

        ActiveEffectLedger::set($outer);
        $this->assertSame($outer, ActiveEffectLedger::get());

        ActiveEffectLedger::set($inner);
        $this->assertSame($inner, ActiveEffectLedger::get());
        $this->assertSame(2, ActiveEffectLedger::depth());
    }

    public function testDeactivatingANestedRequestRestoresTheEnclosingOne(): void
    {
        $outer = new EffectLedger();
        $inner = new EffectLedger();
        ActiveEffectLedger::set($outer);
        ActiveEffectLedger::set($inner);

        ActiveEffectLedger::set(null);

        $this->assertSame($outer, ActiveEffectLedger::get(), 'The outer request is still recording.');
        $this->assertSame(1, ActiveEffectLedger::depth());
    }

    public function testAnEffectAfterANestedRequestStillLandsInTheOuterLedger(): void
    {
        // The behavioural consequence: a query the outer request makes after an internal
        // sub-request must still be recorded into the outer request's own cassette.
        $outer = new EffectLedger();
        $inner = new EffectLedger();

        ActiveEffectLedger::set($outer);
        ActiveEffectLedger::set($inner);
        ActiveEffectLedger::get()?->record(EffectKind::Db, 'inner query', [], null);
        ActiveEffectLedger::set(null);
        ActiveEffectLedger::get()?->record(EffectKind::Db, 'outer query after the sub-request', [], null);

        $this->assertCount(1, $inner->all());
        $this->assertCount(1, $outer->all());
        $this->assertSame('outer query after the sub-request', $outer->all()[0]->fingerprint);
    }

    public function testDeactivatingTheOutermostRequestLeavesNothingActive(): void
    {
        ActiveEffectLedger::set(new EffectLedger());

        ActiveEffectLedger::set(null);

        $this->assertNull(ActiveEffectLedger::get());
        $this->assertSame(0, ActiveEffectLedger::depth());
    }

    public function testAnUnbalancedDeactivateIsHarmless(): void
    {
        // A source whose activate() never ran -- registered mid-request, say -- must not corrupt
        // the stack for whoever is recording.
        ActiveEffectLedger::set(null);

        $this->assertNull(ActiveEffectLedger::get());
        $this->assertSame(0, ActiveEffectLedger::depth());
    }

    public function testResetClearsEveryNestedLevel(): void
    {
        ActiveEffectLedger::set(new EffectLedger());
        ActiveEffectLedger::set(new EffectLedger());

        ActiveEffectLedger::reset();

        $this->assertNull(ActiveEffectLedger::get());
        $this->assertSame(0, ActiveEffectLedger::depth());
    }
}
