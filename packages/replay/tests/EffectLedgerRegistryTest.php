<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Replay\EffectLedger;

final class EffectLedgerRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        EffectLedgerRegistry::reset();
        parent::tearDown();
    }

    public function testGetReturnsNullForAnUnregisteredCorrelationId(): void
    {
        $this->assertNull(EffectLedgerRegistry::get('never-registered'));
    }

    public function testGetReturnsNullForANullCorrelationId(): void
    {
        $ledger = new EffectLedger();
        EffectLedgerRegistry::register('some-id', $ledger);

        $this->assertNull(EffectLedgerRegistry::get(null));
    }

    public function testRegisterThenGetReturnsTheSameLedgerInstance(): void
    {
        $ledger = new EffectLedger();
        EffectLedgerRegistry::register('req-1', $ledger);

        $this->assertSame($ledger, EffectLedgerRegistry::get('req-1'));
    }

    public function testForgetRemovesTheEntry(): void
    {
        EffectLedgerRegistry::register('req-1', new EffectLedger());
        EffectLedgerRegistry::forget('req-1');

        $this->assertNull(EffectLedgerRegistry::get('req-1'));
    }

    public function testForgetOnAnUnregisteredIdIsANoOp(): void
    {
        EffectLedgerRegistry::forget('never-registered');

        $this->assertNull(EffectLedgerRegistry::get('never-registered'));
    }

    public function testTwoDifferentCorrelationIdsRouteToTheirOwnLedgersIndependently(): void
    {
        $ledgerA = new EffectLedger();
        $ledgerB = new EffectLedger();
        EffectLedgerRegistry::register('req-a', $ledgerA);
        EffectLedgerRegistry::register('req-b', $ledgerB);

        $ledgerA->record(EffectKind::Db, 'select 1', [], null);

        $this->assertSame($ledgerA, EffectLedgerRegistry::get('req-a'));
        $this->assertSame($ledgerB, EffectLedgerRegistry::get('req-b'));
        $this->assertCount(1, EffectLedgerRegistry::get('req-a')->all());
        $this->assertCount(0, EffectLedgerRegistry::get('req-b')->all());
    }

    public function testResetClearsEveryRegisteredLedger(): void
    {
        EffectLedgerRegistry::register('req-1', new EffectLedger());
        EffectLedgerRegistry::register('req-2', new EffectLedger());

        EffectLedgerRegistry::reset();

        $this->assertNull(EffectLedgerRegistry::get('req-1'));
        $this->assertNull(EffectLedgerRegistry::get('req-2'));
    }
}
