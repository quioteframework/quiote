<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureMonitorQueryClient;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\AzureTokenProvider;

final class AzureMonitorQueryClientTest extends TestCase
{
    public function testQueryReturnsRowsKeyedByColumnName(): void
    {
        $http = new MonitorFakeHttpClient([new Response(200, [], json_encode([
            'tables' => [[
                'name' => 'PrimaryResult',
                'columns' => [['name' => 'cassette_id', 'type' => 'string'], ['name' => 'cassette_key', 'type' => 'string']],
                'rows' => [['CRX2050', 'prod/2026/08/18/09/CRX2050.qcast']],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $rows = $client->query('ContainerLogV2 | take 1');

        $this->assertSame([['cassette_id' => 'CRX2050', 'cassette_key' => 'prod/2026/08/18/09/CRX2050.qcast']], $rows);
    }

    public function testQuerySendsABearerTokenAndTheKqlBody(): void
    {
        $http = new MonitorFakeHttpClient([new Response(200, [], json_encode(['tables' => []], JSON_THROW_ON_ERROR))]);
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $client->query('ContainerLogV2 | take 1');

        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.loganalytics.io/v1/workspaces/workspace-1/query', (string) $request->getUri());
        $this->assertSame('Bearer aad-token', $request->getHeaderLine('Authorization'));
        $this->assertSame(['query' => 'ContainerLogV2 | take 1'], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testQueryReturnsAnEmptyListWhenThereAreNoTables(): void
    {
        $http = new MonitorFakeHttpClient([new Response(200, [], json_encode(['tables' => []], JSON_THROW_ON_ERROR))]);
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $this->assertSame([], $client->query('ContainerLogV2 | take 0'));
    }

    public function testQueryThrowsOnANonTransientErrorStatus(): void
    {
        $http = new MonitorFakeHttpClient([new Response(401, [], json_encode(['error' => ['message' => 'unauthorized']], JSON_THROW_ON_ERROR))]);
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $this->expectException(AzureStorageException::class);
        $client->query('ContainerLogV2 | take 1');
    }

    public function testQueryRetriesATransientStatusThenSucceeds(): void
    {
        $http = new MonitorFakeHttpClient([
            new Response(503, [], 'temporarily unavailable'),
            new Response(200, [], json_encode(['tables' => []], JSON_THROW_ON_ERROR)),
        ]);
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $this->assertSame([], $client->query('ContainerLogV2 | take 1'));
        $this->assertCount(2, $http->requests);
    }

    public function testQueryThrowsWhenTheTransportFailsOnEveryAttempt(): void
    {
        $http = new MonitorAlwaysFailingHttpClient();
        $client = new AzureMonitorQueryClient($http, new MonitorFixedTokenProvider('aad-token'), 'workspace-1');

        $this->expectException(AzureStorageException::class);
        $client->query('ContainerLogV2 | take 1');
    }

    public function testQueryThrowsWhenTheTokenProviderDeclines(): void
    {
        $http = new MonitorFakeHttpClient([]);
        $client = new AzureMonitorQueryClient($http, new MonitorFailingTokenProvider(), 'workspace-1');

        $this->expectException(AzureStorageException::class);
        $client->query('ContainerLogV2 | take 1');
    }
}

final class MonitorFixedTokenProvider implements AzureTokenProvider
{
    public function __construct(private readonly string $token)
    {
    }

    #[\Override]
    public function getToken(): string
    {
        return $this->token;
    }
}

final class MonitorFailingTokenProvider implements AzureTokenProvider
{
    #[\Override]
    public function getToken(): string
    {
        throw new AzureStorageException('no token available');
    }
}

final class MonitorFakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(500, [], 'no more fake responses queued');
    }
}

final class MonitorAlwaysFailingHttpClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class extends \RuntimeException implements ClientExceptionInterface {
        };
    }
}
