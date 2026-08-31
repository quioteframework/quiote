<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Exception\StorageException;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\UnavailableCassetteStore;

/**
 * The store {@see \Quiote\Replay\ReplayPlugin} substitutes when the real cassette store fails to
 * build: reads must answer empty rather than error, and `put()` must report the original failure
 * rather than silently discard.
 */
final class UnavailableCassetteStoreTest extends TestCase
{
    private function cassette(): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'CRX2050'],
            request: ['method' => 'GET'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
            exception: null,
            log: null,
        );
    }

    public function testPutThrowsAStorageExceptionNamingTheOriginalCause(): void
    {
        $cause = new RuntimeException('missing credential');
        $store = new UnavailableCassetteStore($cause);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('missing credential');

        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette());
    }

    public function testPutExceptionCarriesTheOriginalCauseAsPrevious(): void
    {
        $cause = new RuntimeException('missing credential');
        $store = new UnavailableCassetteStore($cause);

        try {
            $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette());
            $this->fail('Expected a StorageException.');
        } catch (StorageException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testGetAlwaysReturnsNull(): void
    {
        $store = new UnavailableCassetteStore(new RuntimeException('boom'));

        $this->assertNull($store->get(CassetteId::fromRaw('CRX2050')));
    }

    public function testHasAlwaysReturnsFalse(): void
    {
        $store = new UnavailableCassetteStore(new RuntimeException('boom'));

        $this->assertFalse($store->has(CassetteId::fromRaw('CRX2050')));
    }

    public function testDeleteIsANoOp(): void
    {
        $store = new UnavailableCassetteStore(new RuntimeException('boom'));

        $store->delete(CassetteId::fromRaw('CRX2050'));
        $this->addToAssertionCount(1);
    }
}
