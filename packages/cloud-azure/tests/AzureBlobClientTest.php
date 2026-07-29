<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureBlobClient;

/**
 * Shared by the Azure session and filesystem backends, so its request shaping
 * is asserted here rather than in either consumer.
 */
final class AzureBlobClientTest extends TestCase
{
    private RecordingAzureHttpClient $http;

    private function client(int $status = 200, string $body = ''): AzureBlobClient
    {
        $http = new RecordingAzureHttpClient($status, $body);
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
}

/**
 * Records the requests it is handed and answers a canned response.
 */
final class RecordingAzureHttpClient implements ClientInterface
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
