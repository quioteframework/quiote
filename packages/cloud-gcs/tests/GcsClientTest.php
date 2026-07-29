<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Gcs\GcsClient;

/**
 * Shared by the GCS session and filesystem backends, so its request shaping is
 * asserted here rather than in either consumer.
 */
final class GcsClientTest extends TestCase
{
    private RecordingGcsHttpClient $http;

    private function client(int $status = 200, string $body = ''): GcsClient
    {
        $http = new RecordingGcsHttpClient($status, $body);
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
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingGcsHttpClient implements ClientInterface
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
