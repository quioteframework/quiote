<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Session\ObjectStoreSessionPersistence;
use Quiote\Session\SessionCodec;

/**
 * The behaviour every object-store session backend shares, exercised once against a fake client.
 */
final class ObjectStoreSessionPersistenceTest extends TestCase
{
    public function testRoundTripsSessionData(): void
    {
        $store = new FakeObjectStore();
        $persistence = new ObjectStoreSessionPersistence($store);

        $persistence->save('abc123', ['user' => 7, 'locale' => 'fi']);

        $this->assertSame(['user' => 7, 'locale' => 'fi'], $persistence->load('abc123'));
    }

    public function testKeyIsPrefixedAndSuffixed(): void
    {
        $store = new FakeObjectStore();
        $persistence = new ObjectStoreSessionPersistence($store, 'sessions/', '.json');

        $persistence->save('abc123', ['a' => 1]);

        $this->assertArrayHasKey('sessions/abc123.json', $store->objects);
    }

    public function testAnAbsentSessionLoadsAsNull(): void
    {
        $this->assertNull((new ObjectStoreSessionPersistence(new FakeObjectStore()))->load('nope'));
    }

    public function testAnEmptyStoredPayloadLoadsAsNull(): void
    {
        $store = new FakeObjectStore();
        $store->objects['sessions/abc.json'] = '';

        $this->assertNull((new ObjectStoreSessionPersistence($store))->load('abc'));
    }

    public function testDeleteRemovesTheObject(): void
    {
        $store = new FakeObjectStore();
        $persistence = new ObjectStoreSessionPersistence($store);
        $persistence->save('abc', ['a' => 1]);

        $persistence->delete('abc');

        $this->assertSame([], $store->objects);
        $this->assertNull($persistence->load('abc'));
    }

    /**
     * The default is the portable codec, so what lands in the bucket is readable JSON rather than
     * an opaque binary blob.
     */
    public function testDefaultsToThePortableCodec(): void
    {
        $store = new FakeObjectStore();
        (new ObjectStoreSessionPersistence($store))->save('abc', ['k' => 'v']);

        $this->assertSame('{"k":"v"}', $store->objects['sessions/abc.json']);
    }

    public function testAnInjectedCodecIsUsed(): void
    {
        $store = new FakeObjectStore();
        $persistence = new ObjectStoreSessionPersistence(
            $store,
            'sessions/',
            '.bin',
            SessionCodec::binaryPreferred()
        );

        $persistence->save('abc', ['k' => 'v']);

        $this->assertSame(['k' => 'v'], $persistence->load('abc'), 'the same codec must read it back');
        if (function_exists('igbinary_serialize')) {
            $this->assertStringStartsNotWith('{', $store->objects['sessions/abc.bin']);
        }
    }
}
