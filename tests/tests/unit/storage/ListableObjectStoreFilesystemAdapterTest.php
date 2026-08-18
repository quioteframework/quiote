<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\ListableFilesystemInterface;
use Quiote\Filesystem\ListableObjectStoreFilesystemAdapter;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreException;
use Quiote\Storage\ObjectSummary;

/**
 * {@see ListableObjectStoreFilesystemAdapter} is what turns any {@see ListableObjectStoreClientInterface}
 * into a {@see ListableFilesystemInterface}, exercised once here instead of once per provider.
 */
final class ListableObjectStoreFilesystemAdapterTest extends TestCase
{
    private function adapter(FakeListableObjectStore $store, string $prefix = ''): ListableObjectStoreFilesystemAdapter
    {
        return new ListableObjectStoreFilesystemAdapter($store, 'FakeProvider', $prefix);
    }

    public function testIsListable(): void
    {
        $this->assertInstanceOf(ListableFilesystemInterface::class, $this->adapter(new FakeListableObjectStore()));
    }

    public function testListContentsReturnsFilesUnderThePrefix(): void
    {
        $store = new FakeListableObjectStore();
        $store->objects = ['reports/q1.csv' => 'a', 'reports/q2.csv' => 'bb', 'other.csv' => 'c'];

        $this->assertSame(['q1.csv', 'q2.csv'], $this->adapter($store)->listContents('reports'));
    }

    public function testListContentsGroupsDeeperKeysAsASingleEntry(): void
    {
        $store = new FakeListableObjectStore();
        $store->objects = ['reports/2024/q1.csv' => 'a', 'reports/summary.csv' => 'b'];

        $this->assertSame(['2024', 'summary.csv'], $this->adapter($store)->listContents('reports'));
    }

    public function testListContentsOfAnEmptyPrefixIsEmpty(): void
    {
        $this->assertSame([], $this->adapter(new FakeListableObjectStore())->listContents('missing'));
    }

    public function testKeyPrefixIsAppliedBeforeListing(): void
    {
        $store = new FakeListableObjectStore();
        $store->objects = ['tenant-7/reports/q1.csv' => 'a', 'tenant-9/reports/q1.csv' => 'b'];

        $this->assertSame(['q1.csv'], $this->adapter($store, 'tenant-7/')->listContents('reports'));
    }

    public function testListContentsFollowsPaginationAcrossMultiplePages(): void
    {
        $store = new FakeListableObjectStore();
        $store->objects = ['a.csv' => '1', 'b.csv' => '2', 'c.csv' => '3'];
        $store->pageSize = 1;

        $this->assertSame(['a.csv', 'b.csv', 'c.csv'], $this->adapter($store)->listContents());
        $this->assertGreaterThanOrEqual(3, count($store->calls));
    }

    /**
     * A store failure is translated into the filesystem's own exception type, with the original
     * chained so the provider's message survives.
     */
    public function testStoreFailureBecomesAFilesystemFailure(): void
    {
        $store = new FakeListableObjectStore();
        $store->failWith = new ObjectStoreException('the bucket is on fire');

        try {
            $this->adapter($store)->listContents();
            $this->fail('a store failure must surface as a FilesystemStorageException');
        } catch (FilesystemStorageException $e) {
            $this->assertStringContainsString('the bucket is on fire', $e->getMessage());
            $this->assertInstanceOf(ObjectStoreException::class, $e->getPrevious());
        }
    }
}

final class FakeListableObjectStore implements ListableObjectStoreClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    public ?ObjectStoreException $failWith = null;

    public int $pageSize = PHP_INT_MAX;

    /** @var list<array{prefix: string, delimiter: string, continuationToken: ?string, maxKeys: int}> */
    public array $calls = [];

    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    public function put(string $key, string $body): void
    {
        $this->objects[$key] = $body;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function head(string $key): ?ObjectMetadata
    {
        return isset($this->objects[$key]) ? new ObjectMetadata(strlen($this->objects[$key]), null, null) : null;
    }

    #[\Override]
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->calls[] = ['prefix' => $prefix, 'delimiter' => $delimiter, 'continuationToken' => $continuationToken, 'maxKeys' => $maxKeys];

        $matching = array_values(array_filter(array_keys($this->objects), static fn (string $k): bool => str_starts_with($k, $prefix)));
        sort($matching);

        $entries = [];
        foreach ($matching as $key) {
            $rest = substr($key, strlen($prefix));
            $delimiterPos = $delimiter === '' ? false : strpos($rest, $delimiter);
            $value = $delimiterPos !== false ? $prefix . substr($rest, 0, $delimiterPos + 1) : $key;
            $entries[$value] = $delimiterPos !== false ? 'prefix' : 'object';
        }
        ksort($entries);

        $values = array_keys($entries);
        $startIndex = 0;
        if ($continuationToken !== null) {
            $found = array_search($continuationToken, $values, true);
            $startIndex = $found === false ? 0 : $found + 1;
        }
        $page = array_slice($values, $startIndex, min($maxKeys, $this->pageSize));

        $objects = [];
        $commonPrefixes = [];
        foreach ($page as $value) {
            if ($entries[$value] === 'prefix') {
                $commonPrefixes[] = $value;
            } else {
                $objects[] = new ObjectSummary($value, strlen($this->objects[$value]), null, null);
            }
        }

        $nextToken = $startIndex + count($page) < count($values) ? $page[count($page) - 1] : null;

        return new ObjectListing($objects, $commonPrefixes, $nextToken);
    }
}
