<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\S3\S3Client;
use Quiote\Storage\S3\S3StorageException;

/**
 * The client is shared by the S3 session and filesystem backends, so its
 * request shaping is asserted here rather than in either consumer.
 */
final class S3ClientTest extends TestCase
{
    private RecordingS3HttpClient $http;

    /** @param array<string, string> $headers */
    private function client(int $status = 200, string $body = '', array $headers = []): S3Client
    {
        $http = new RecordingS3HttpClient($status, $body, $headers);
        $this->http = $http;

        return new S3Client($http, 'eu-west-1', 'AKIAEXAMPLE', 'secret', 'my-bucket');
    }

    public function testGetSignsThePathStyleRequestForTheConfiguredBucket(): void
    {
        $client = $this->client(200, 'payload');

        $this->assertSame('payload', $client->get('sessions/abc.json'));

        $request = $this->http->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/my-bucket/sessions/abc.json', $request->getUri()->getPath());
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 ', $request->getHeaderLine('Authorization'));
    }

    public function testGetTreatsA404AsAbsentRatherThanAnError(): void
    {
        $this->assertNull($this->client(404)->get('sessions/missing.json'));
    }

    public function testPutSendsTheBody(): void
    {
        $client = $this->client(200);

        $client->put('sessions/abc.json', 'contents');

        $request = $this->http->sent[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('contents', (string) $request->getBody());
    }

    public function testHeadReturnsMetadataFromTheResponseHeaders(): void
    {
        $client = $this->client(200, '', [
            'Content-Length' => '1234',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'ETag' => '"d41d8cd98f00b204e9800998ecf8427e"',
        ]);

        $metadata = $client->head('files/report.csv');

        $this->assertNotNull($metadata);
        $this->assertSame('HEAD', $this->http->sent[0]->getMethod());
        $this->assertSame(1234, $metadata->contentLength);
        $this->assertSame('2015-10-21T07:28:00+00:00', $metadata->lastModified?->format(DATE_ATOM));
        $this->assertSame('d41d8cd98f00b204e9800998ecf8427e', $metadata->etag);
    }

    public function testHeadTreatsA404AsAbsentRatherThanAnError(): void
    {
        $this->assertNull($this->client(404)->head('files/missing.csv'));
    }

    public function testHeadThrowsOnAServerError(): void
    {
        $this->expectException(S3StorageException::class);
        $this->client(500)->head('files/report.csv');
    }

    public function testHeadLeavesUnparsableMetadataNullRatherThanGuessing(): void
    {
        $metadata = $this->client(200, '', [
            'Content-Length' => 'not-a-number',
            'Last-Modified' => 'yesterday-ish',
        ])->head('files/report.csv');

        $this->assertNotNull($metadata);
        $this->assertNull($metadata->contentLength);
        $this->assertNull($metadata->lastModified);
        $this->assertNull($metadata->etag);
    }

    public function testRequestAddressesTheBucketItselfAndSignsASortedQuery(): void
    {
        $client = $this->client(200, '<ListBucketResult/>');

        $response = $client->request('GET', '', ['prefix' => 'files/', 'list-type' => '2', 'delimiter' => '/']);

        $this->assertSame('<ListBucketResult/>', (string) $response->getBody());
        $request = $this->http->sent[0];
        $this->assertSame('/my-bucket', $request->getUri()->getPath());
        $this->assertSame('delimiter=%2F&list-type=2&prefix=files%2F', $request->getUri()->getQuery());
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 ', $request->getHeaderLine('Authorization'));
    }

    public function testRequestHandsBackErrorResponsesRatherThanThrowing(): void
    {
        $response = $this->client(403, 'AccessDenied')->request('GET', '', ['list-type' => '2']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testQuerylessRequestsAreUnaffectedByQuerySigning(): void
    {
        $this->client(200, 'payload')->get('sessions/abc.json');

        $this->assertSame('', $this->http->sent[0]->getUri()->getQuery());
    }
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingS3HttpClient implements ClientInterface
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
