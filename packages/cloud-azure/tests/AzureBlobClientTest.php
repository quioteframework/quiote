<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\SharedKeyCredential;

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

        return new AzureBlobClient($http, 'myaccount', new SharedKeyCredential(base64_encode('key')));
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

    public function testAuthorizationComesFromWhicheverCredentialWasGiven(): void
    {
        $http = new RecordingAzureHttpClient(200, 'payload');
        $credential = new class implements \Quiote\Storage\Azure\AzureCredential {
            #[\Override]
            public function authorizationHeader(string $accountName, string $method, string $path, array $query, array $headers): string
            {
                return 'Bearer test-token';
            }
        };
        $client = new AzureBlobClient($http, 'myaccount', $credential);

        $client->get('sessions', 'abc.json');

        $this->assertSame('Bearer test-token', $http->sent[0]->getHeaderLine('Authorization'));
    }

    public function testListObjectsSendsRestypeCompPrefixDelimiterAndTheMarker(): void
    {
        $client = $this->client(200, '<EnumerationResults></EnumerationResults>');

        $client->listObjects('files', 'reports/', '/', 'previous-marker', 50);

        $request = $this->http->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/files', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'restype' => 'container',
            'comp' => 'list',
            'maxresults' => '50',
            'prefix' => 'reports/',
            'delimiter' => '/',
            'marker' => 'previous-marker',
        ], $query);
    }

    public function testListObjectsOmitsTheMarkerOnTheFirstPage(): void
    {
        $client = $this->client(200, '<EnumerationResults></EnumerationResults>');

        $client->listObjects('files');

        parse_str($this->http->sent[0]->getUri()->getQuery(), $query);
        $this->assertArrayNotHasKey('marker', $query);
    }

    public function testListObjectsParsesBlobsBlobPrefixesAndTheNextMarker(): void
    {
        $xml = '<EnumerationResults><Blobs>'
            . '<Blob><Name>reports/a.csv</Name><Properties>'
            . '<Last-Modified>Wed, 21 Oct 2015 07:28:00 GMT</Last-Modified>'
            . '<Etag>"0x8D1234567890ABC"</Etag><Content-Length>42</Content-Length>'
            . '</Properties></Blob>'
            . '<BlobPrefix><Name>reports/sub/</Name></BlobPrefix>'
            . '</Blobs><NextMarker>next-marker</NextMarker></EnumerationResults>';

        $listing = $this->client(200, $xml)->listObjects('files', 'reports/', '/');

        $this->assertCount(1, $listing->objects);
        $this->assertSame('reports/a.csv', $listing->objects[0]->key);
        $this->assertSame(42, $listing->objects[0]->size);
        $this->assertSame('2015-10-21T07:28:00+00:00', $listing->objects[0]->lastModified?->format(DATE_ATOM));
        $this->assertSame('0x8D1234567890ABC', $listing->objects[0]->etag);
        $this->assertSame(['reports/sub/'], $listing->commonPrefixes);
        $this->assertSame('next-marker', $listing->nextContinuationToken);
        $this->assertTrue($listing->isTruncated());
    }

    public function testListObjectsWithAnEmptyNextMarkerIsNotTruncated(): void
    {
        $xml = '<EnumerationResults><Blobs></Blobs><NextMarker/></EnumerationResults>';

        $listing = $this->client(200, $xml)->listObjects('files');

        $this->assertSame([], $listing->objects);
        $this->assertSame([], $listing->commonPrefixes);
        $this->assertNull($listing->nextContinuationToken);
        $this->assertFalse($listing->isTruncated());
    }

    public function testListObjectsThrowsOnAServerError(): void
    {
        $this->expectException(AzureStorageException::class);
        $this->client(500, 'boom')->listObjects('files');
    }

    public function testListObjectsThrowsOnMalformedXml(): void
    {
        $this->expectException(AzureStorageException::class);
        $this->client(200, 'not xml')->listObjects('files');
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
