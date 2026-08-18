<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\ListableFilesystemInterface;
use Quiote\Filesystem\ObjectStoreFilesystemAdapter;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreClientInterface;
use Quiote\Storage\ObjectStoreException;

/**
 * The behaviour every object-store filesystem driver shares, exercised once against a fake client
 * instead of once per provider.
 */
final class ObjectStoreFilesystemAdapterTest extends TestCase
{
    private function adapter(FakeObjectStore $store, string $prefix = ''): ObjectStoreFilesystemAdapter
    {
        return new ObjectStoreFilesystemAdapter($store, 'FakeProvider', $prefix);
    }

    public function testReadReturnsWhatWasWritten(): void
    {
        $store = new FakeObjectStore();
        $adapter = $this->adapter($store);

        $adapter->write('report.csv', 'a,b,c');

        $this->assertSame('a,b,c', $adapter->read('report.csv'));
    }

    public function testKeyPrefixIsAppliedToEveryOperation(): void
    {
        $store = new FakeObjectStore();
        $adapter = $this->adapter($store, 'tenant-7/');

        $adapter->write('report.csv', 'data');

        $this->assertArrayHasKey('tenant-7/report.csv', $store->objects);
        $this->assertTrue($adapter->exists('report.csv'));
        $this->assertSame('data', $adapter->read('report.csv'));

        $adapter->delete('report.csv');

        $this->assertSame([], $store->objects);
    }

    public function testReadingAnAbsentPathThrowsNotFound(): void
    {
        $adapter = $this->adapter(new FakeObjectStore());

        $this->expectException(FileNotFoundStorageException::class);
        $this->expectExceptionMessageMatches('/"missing.csv" does not exist/');
        $adapter->read('missing.csv');
    }

    public function testExistsIsFalseForAnAbsentPath(): void
    {
        $this->assertFalse($this->adapter(new FakeObjectStore())->exists('missing.csv'));
    }

    public function testDeletingAnAbsentPathIsHarmless(): void
    {
        $store = new FakeObjectStore();
        $store->objects['other.csv'] = 'kept';

        // Best-effort: a missing key is not an error, and nothing else is disturbed.
        $this->adapter($store)->delete('missing.csv');

        $this->assertSame(['other.csv' => 'kept'], $store->objects);
    }

    public function testSizeAndLastModifiedComeFromTheObjectMetadata(): void
    {
        $store = new FakeObjectStore();
        $adapter = $this->adapter($store);
        $adapter->write('report.csv', 'abcde');
        $store->metadata['report.csv'] = new ObjectMetadata(5, new DateTimeImmutable('2026-01-02 03:04:05'), 'etag');

        $this->assertSame(5, $adapter->size('report.csv'));
        $this->assertSame('2026-01-02 03:04:05', $adapter->lastModified('report.csv')->format('Y-m-d H:i:s'));
    }

    public function testSizeOfAnAbsentPathThrowsNotFound(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter(new FakeObjectStore())->size('missing.csv');
    }

    /**
     * A provider that answers HEAD without Content-Length gives no size to report, and the failure
     * names the provider so the reader knows where to look.
     */
    public function testMissingContentLengthNamesTheProvider(): void
    {
        $store = new FakeObjectStore();
        $adapter = $this->adapter($store);
        $adapter->write('report.csv', 'abcde');
        $store->metadata['report.csv'] = new ObjectMetadata(null, null, null);

        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/FakeProvider returned no Content-Length/');
        $adapter->size('report.csv');
    }

    public function testMissingLastModifiedNamesTheProvider(): void
    {
        $store = new FakeObjectStore();
        $adapter = $this->adapter($store);
        $adapter->write('report.csv', 'abcde');
        $store->metadata['report.csv'] = new ObjectMetadata(5, null, null);

        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/FakeProvider returned no usable Last-Modified/');
        $adapter->lastModified('report.csv');
    }

    /**
     * A store failure is translated into the filesystem's own exception type, with the original
     * chained so the provider's message survives.
     */
    public function testStoreFailuresBecomeFilesystemFailures(): void
    {
        $store = new FakeObjectStore();
        $store->failWith = new ObjectStoreException('the bucket is on fire');
        $adapter = $this->adapter($store);

        foreach ([
            fn() => $adapter->read('x'),
            fn() => $adapter->write('x', 'y'),
            fn() => $adapter->delete('x'),
            fn() => $adapter->exists('x'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('a store failure must surface as a FilesystemStorageException');
            } catch (FilesystemStorageException $e) {
                $this->assertStringContainsString('the bucket is on fire', $e->getMessage());
                $this->assertInstanceOf(ObjectStoreException::class, $e->getPrevious());
            }
        }
    }

    /**
     * An object store reached through single-object calls cannot enumerate, so the shared adapter
     * must not claim it can.
     */
    public function testAdapterIsNotListable(): void
    {
        $this->assertNotInstanceOf(ListableFilesystemInterface::class, $this->adapter(new FakeObjectStore()));
    }
}

final class FakeObjectStore implements ObjectStoreClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, ObjectMetadata> */
    public array $metadata = [];

    public ?ObjectStoreException $failWith = null;

    public function get(string $key): ?string
    {
        $this->maybeFail();

        return $this->objects[$key] ?? null;
    }

    public function put(string $key, string $body): void
    {
        $this->maybeFail();
        $this->objects[$key] = $body;
    }

    public function delete(string $key): void
    {
        $this->maybeFail();
        unset($this->objects[$key]);
    }

    public function head(string $key): ?ObjectMetadata
    {
        $this->maybeFail();
        if (!isset($this->objects[$key])) {
            return null;
        }

        return $this->metadata[$key] ?? new ObjectMetadata(strlen($this->objects[$key]), new DateTimeImmutable(), null);
    }

    private function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
