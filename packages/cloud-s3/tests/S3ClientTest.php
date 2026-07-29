<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\S3\S3Client;

/**
 * The client is shared by the S3 session and filesystem backends, so its
 * request shaping is asserted here rather than in either consumer.
 */
final class S3ClientTest extends TestCase
{
    private RecordingS3HttpClient $http;

    private function client(int $status = 200, string $body = ''): S3Client
    {
        $http = new RecordingS3HttpClient($status, $body);
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
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingS3HttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(private int $status = 200, private string $body = '')
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return new \Nyholm\Psr7\Response($this->status, [], $this->body);
    }
}
