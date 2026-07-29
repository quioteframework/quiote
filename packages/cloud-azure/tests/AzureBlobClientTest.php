<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureStorageException;

/**
 * Shared by the Azure session and filesystem backends, so its request shaping
 * is asserted here rather than in either consumer.
 */
final class AzureBlobClientTest extends TestCase
{
    private RecordingAzureHttpClient $http;

    /** @param array<string, string> $headers */
    private function client(int $status = 200, string $body = '', array $headers = []): AzureBlobClient
    {
        $http = new RecordingAzureHttpClient($status, $body, $headers);
        $this->http = $http;

        return new AzureBlobClient($http, 'myaccount', base64_encode('key'));
    }

    public function testGetSignsWithSharedKeyAgainstTheAccountHost(): void
    {
        $this->assertSame('payload', $this->client(200, 'payload')->get('sessions', 'abc.json'));

        $request = $this->http->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('myaccount', $request->getUri()->getHost());
        $this->assertStringStartsWith('SharedKey ', $request->getHeaderLine('Authorization'));
    }

    public function testGetTreatsA404AsAbsentRatherThanAnError(): void
    {
        $this->assertNull($this->client(404)->get('sessions', 'missing.json'));
    }

    public function testHeadReturnsPropertiesFromTheResponseHeaders(): void
    {
        $client = $this->client(200, '', [
            'Content-Length' => '1234',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'ETag' => '"0x8D1234567890ABC"',
        ]);

        $metadata = $client->head('files', 'report.csv');

        $this->assertNotNull($metadata);
        $this->assertSame('HEAD', $this->http->sent[0]->getMethod());
        $this->assertSame('/files/report.csv', $this->http->sent[0]->getUri()->getPath());
        $this->assertSame(1234, $metadata->contentLength);
        $this->assertSame('2015-10-21T07:28:00+00:00', $metadata->lastModified?->format(DATE_ATOM));
        $this->assertSame('0x8D1234567890ABC', $metadata->etag);
    }

    public function testHeadTreatsA404AsAbsentRatherThanAnError(): void
    {
        $this->assertNull($this->client(404)->head('files', 'missing.csv'));
    }

    public function testHeadThrowsOnAServerError(): void
    {
        $this->expectException(AzureStorageException::class);
        $this->client(500)->head('files', 'report.csv');
    }

    public function testHeadLeavesUnparsableMetadataNullRatherThanGuessing(): void
    {
        $metadata = $this->client(200, '', [
            'Content-Length' => 'not-a-number',
            'Last-Modified' => 'yesterday-ish',
        ])->head('files', 'report.csv');

        $this->assertNotNull($metadata);
        $this->assertNull($metadata->contentLength);
        $this->assertNull($metadata->lastModified);
        $this->assertNull($metadata->etag);
    }

    public function testRequestSignsAnArbitraryContainerLevelCall(): void
    {
        $client = $this->client(200, '<EnumerationResults/>');

        $response = $client->request('GET', '/files', ['restype' => 'container', 'comp' => 'list']);

        $this->assertSame('<EnumerationResults/>', (string) $response->getBody());
        $request = $this->http->sent[0];
        $this->assertSame('/files', $request->getUri()->getPath());
        $this->assertSame('restype=container&comp=list', $request->getUri()->getQuery());
        $this->assertStringStartsWith('SharedKey ', $request->getHeaderLine('Authorization'));
    }

    public function testRequestHandsBackErrorResponsesRatherThanThrowing(): void
    {
        $response = $this->client(403, 'AuthenticationFailed')->request('GET', '/files', ['comp' => 'list']);

        $this->assertSame(403, $response->getStatusCode());
    }
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingAzureHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @param array<string, string> $headers */
    public function __construct(private int $status = 200, private string $body = '', private array $headers = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return new \Nyholm\Psr7\Response($this->status, $this->headers, $this->body);
    }
}
