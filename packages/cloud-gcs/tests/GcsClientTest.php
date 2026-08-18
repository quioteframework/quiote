<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Gcs\GcsClient;
use Quiote\Storage\Gcs\GcsStorageException;

/**
 * Shared by the GCS session and filesystem backends, so its request shaping is
 * asserted here rather than in either consumer.
 */
final class GcsClientTest extends TestCase
{
    private RecordingGcsHttpClient $http;

    /** @param array<string, string> $headers */
    private function client(int $status = 200, string $body = '', array $headers = []): GcsClient
    {
        $http = new RecordingGcsHttpClient($status, $body, $headers);
        $this->http = $http;

        return new GcsClient($http, 'hmac-key', 'hmac-secret', 'my-bucket');
    }

    public function testGetSignsTheRequestForTheConfiguredBucket(): void
    {
        $this->assertSame('payload', $this->client(200, 'payload')->get('sessions/abc.json'));

        $request = $this->http->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/my-bucket/sessions/abc.json', $request->getUri()->getPath());
        $this->assertNotSame('', $request->getHeaderLine('Authorization'));
    }

    public function testGetTreatsA404AsAbsentRatherThanAnError(): void
    {
        $this->assertNull($this->client(404)->get('sessions/missing.json'));
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
        $this->expectException(GcsStorageException::class);
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

    public function testRequestAddressesTheBucketItselfAndAppendsTheQuery(): void
    {
        $client = $this->client(200, '<ListBucketResult/>');

        $response = $client->request('GET', '', ['prefix' => 'files/', 'delimiter' => '/']);

        $this->assertSame('<ListBucketResult/>', (string) $response->getBody());
        $request = $this->http->sent[0];
        $this->assertSame('/my-bucket', $request->getUri()->getPath());
        $this->assertSame('prefix=files%2F&delimiter=%2F', $request->getUri()->getQuery());
        $this->assertNotSame('', $request->getHeaderLine('Authorization'));
    }

    public function testRequestHandsBackErrorResponsesRatherThanThrowing(): void
    {
        $response = $this->client(403, 'AccessDenied')->request('GET', '', ['prefix' => 'files/']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListObjectsSendsPrefixDelimiterMaxKeysAndTheMarker(): void
    {
        $client = $this->client(200, '<ListBucketResult></ListBucketResult>');

        $client->listObjects('files/', '/', 'previous-key', 50);

        $request = $this->http->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/my-bucket', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'max-keys' => '50',
            'prefix' => 'files/',
            'delimiter' => '/',
            'marker' => 'previous-key',
        ], $query);
    }

    public function testListObjectsOmitsTheMarkerOnTheFirstPage(): void
    {
        $client = $this->client(200, '<ListBucketResult></ListBucketResult>');

        $client->listObjects();

        parse_str($this->http->sent[0]->getUri()->getQuery(), $query);
        $this->assertArrayNotHasKey('marker', $query);
    }

    public function testListObjectsParsesContentsCommonPrefixesAndTheNextMarker(): void
    {
        $xml = '<ListBucketResult>'
            . '<Contents><Key>files/a.csv</Key><LastModified>2015-10-21T07:28:00.000Z</LastModified><ETag>"abc123"</ETag><Size>42</Size></Contents>'
            . '<CommonPrefixes><Prefix>files/sub/</Prefix></CommonPrefixes>'
            . '<IsTruncated>true</IsTruncated>'
            . '<NextMarker>files/b.csv</NextMarker>'
            . '</ListBucketResult>';

        $listing = $this->client(200, $xml)->listObjects('files/', '/');

        $this->assertCount(1, $listing->objects);
        $this->assertSame('files/a.csv', $listing->objects[0]->key);
        $this->assertSame(42, $listing->objects[0]->size);
        $this->assertSame('2015-10-21T07:28:00+00:00', $listing->objects[0]->lastModified?->format(DATE_ATOM));
        $this->assertSame('abc123', $listing->objects[0]->etag);
        $this->assertSame(['files/sub/'], $listing->commonPrefixes);
        $this->assertSame('files/b.csv', $listing->nextContinuationToken);
        $this->assertTrue($listing->isTruncated());
    }

    public function testListObjectsFallsBackToTheLastKeyWhenTruncatedWithoutANextMarker(): void
    {
        $xml = '<ListBucketResult>'
            . '<Contents><Key>files/a.csv</Key><Size>1</Size></Contents>'
            . '<Contents><Key>files/b.csv</Key><Size>2</Size></Contents>'
            . '<IsTruncated>true</IsTruncated>'
            . '</ListBucketResult>';

        $listing = $this->client(200, $xml)->listObjects();

        $this->assertSame('files/b.csv', $listing->nextContinuationToken);
    }

    public function testListObjectsWithoutTruncationIsNotTruncated(): void
    {
        $listing = $this->client(200, '<ListBucketResult></ListBucketResult>')->listObjects();

        $this->assertSame([], $listing->objects);
        $this->assertNull($listing->nextContinuationToken);
        $this->assertFalse($listing->isTruncated());
    }

    public function testListObjectsThrowsOnAServerError(): void
    {
        $this->expectException(GcsStorageException::class);
        $this->client(500, 'boom')->listObjects();
    }

    public function testListObjectsThrowsOnMalformedXml(): void
    {
        $this->expectException(GcsStorageException::class);
        $this->client(200, 'not xml')->listObjects();
    }
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingGcsHttpClient implements ClientInterface
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
