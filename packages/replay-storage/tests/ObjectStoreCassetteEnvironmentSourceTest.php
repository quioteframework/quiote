<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteEnvironmentSource;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectSummary;

/**
 * A minimal in-memory {@see ListableObjectStoreClientInterface} that actually groups keys into
 * `$commonPrefixes` on a delimiter -- {@see ObjectStoreCassetteStoreFakeClient} (the fixture the
 * store's own test uses) always returns an empty `$commonPrefixes`, which is exactly the behaviour
 * this class needs to exercise.
 */
final class ObjectStoreCassetteEnvironmentSourceFakeClient implements ListableObjectStoreClientInterface
{
    /** @var list<string> */
    public array $keys = [];

    public int $pageSize = 100;

    /** @var list<array{prefix: string, delimiter: string}> */
    public array $listCalls = [];

    public function get(string $key): ?string
    {
        return null;
    }

    public function put(string $key, string $body): void
    {
    }

    public function delete(string $key): void
    {
    }

    public function head(string $key): ?ObjectMetadata
    {
        return null;
    }

    #[\Override]
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        $this->listCalls[] = ['prefix' => $prefix, 'delimiter' => $delimiter];

        $objects = [];
        $commonPrefixes = [];
        foreach ($this->keys as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $rest = substr($key, strlen($prefix));
            $delimiterPos = $delimiter !== '' ? strpos($rest, $delimiter) : false;
            if ($delimiterPos !== false) {
                $commonPrefixes[] = $prefix . substr($rest, 0, $delimiterPos + strlen($delimiter));
            } else {
                $objects[] = new ObjectSummary($key, 0, null, null);
            }
        }
        $commonPrefixes = array_values(array_unique($commonPrefixes));
        sort($commonPrefixes);

        $offset = $continuationToken !== null ? (int) $continuationToken : 0;
        $page = array_slice($commonPrefixes, $offset, $this->pageSize);
        $next = $offset + $this->pageSize < count($commonPrefixes) ? (string) ($offset + $this->pageSize) : null;

        return new ObjectListing($objects, $page, $next);
    }
}

final class ObjectStoreCassetteEnvironmentSourceTest extends TestCase
{
    public function testListEnvironmentsReturnsDistinctSortedNames(): void
    {
        $client = new ObjectStoreCassetteEnvironmentSourceFakeClient();
        $client->keys = [
            'quiote-cassettes/staging/2026/08/18/09/AAA.qcast',
            'quiote-cassettes/production/2026/08/18/09/BBB.qcast',
            'quiote-cassettes/staging/2026/08/17/10/CCC.qcast',
        ];
        $source = new ObjectStoreCassetteEnvironmentSource($client, new CassetteKeyScheme('quiote-cassettes', 'unused'), 'azure-blob');

        $this->assertSame(['production', 'staging'], $source->listEnvironments());
    }

    public function testListEnvironmentsIsEmptyWhenNothingHasEverBeenWritten(): void
    {
        $client = new ObjectStoreCassetteEnvironmentSourceFakeClient();
        $source = new ObjectStoreCassetteEnvironmentSource($client, new CassetteKeyScheme('quiote-cassettes', 'unused'), 'azure-blob');

        $this->assertSame([], $source->listEnvironments());
    }

    public function testListEnvironmentsQueriesOneLevelAboveTheDayPrefixWithADelimiter(): void
    {
        $client = new ObjectStoreCassetteEnvironmentSourceFakeClient();
        $source = new ObjectStoreCassetteEnvironmentSource($client, new CassetteKeyScheme('quiote-cassettes', 'unused'), 'azure-blob');

        $source->listEnvironments();

        $this->assertSame([['prefix' => 'quiote-cassettes/', 'delimiter' => '/']], $client->listCalls);
    }

    public function testListEnvironmentsFollowsContinuationTokensAcrossPages(): void
    {
        $client = new ObjectStoreCassetteEnvironmentSourceFakeClient();
        $client->pageSize = 1;
        $client->keys = [
            'quiote-cassettes/staging/2026/08/18/09/AAA.qcast',
            'quiote-cassettes/production/2026/08/18/09/BBB.qcast',
        ];
        $source = new ObjectStoreCassetteEnvironmentSource($client, new CassetteKeyScheme('quiote-cassettes', 'unused'), 'azure-blob');

        $this->assertSame(['production', 'staging'], $source->listEnvironments());
        $this->assertCount(2, $client->listCalls);
    }

    public function testLabelIncludesTheStoreAlias(): void
    {
        $client = new ObjectStoreCassetteEnvironmentSourceFakeClient();
        $source = new ObjectStoreCassetteEnvironmentSource($client, new CassetteKeyScheme('quiote-cassettes', 'unused'), 'azure-blob');

        $this->assertSame('replay cassettes (azure-blob)', $source->label());
    }
}
