<?php

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Client\HttpClient;
use Quiote\Http\Client\HttpClientConfig;
use Quiote\Http\Client\Exception\NetworkException;
use Quiote\Test\Http\Client\RecordingTransport;

/**
 * HttpClient logic — base URI, default headers, retry policy, verb helpers —
 * against an in-memory recording transport (no sockets, deterministic). The
 * real curl transport is exercised separately in CurlTransportTest.
 */
class HttpClientTest extends TestCase
{
    private function client(RecordingTransport $transport, ?callable $configure = null): HttpClient
    {
        $config = new HttpClientConfig();
        $config->transport($transport);
        if ($configure) {
            $configure($config);
        }
        return HttpClient::fromConfig($config);
    }

    public function testGetSendsRequestThroughTransport(): void
    {
        $transport = new RecordingTransport(new Response(200));
        $response = $this->client($transport)->get('https://example.com/thing');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', $transport->lastRequest()->getMethod());
        $this->assertSame('https://example.com/thing', (string) $transport->lastRequest()->getUri());
    }

    public function testBaseUriIsPrependedToRelativePaths(): void
    {
        $transport = new RecordingTransport(new Response(200));
        $this->client($transport, fn(HttpClientConfig $c) => $c->baseUri('https://api.example.com'))
            ->get('/users/1');

        $this->assertSame('https://api.example.com/users/1', (string) $transport->lastRequest()->getUri());
    }

    public function testAbsoluteUriIgnoresBaseUri(): void
    {
        $transport = new RecordingTransport(new Response(200));
        $this->client($transport, fn(HttpClientConfig $c) => $c->baseUri('https://api.example.com'))
            ->get('https://other.example.org/x');

        $this->assertSame('https://other.example.org/x', (string) $transport->lastRequest()->getUri());
    }

    public function testDefaultHeadersAreApplied(): void
    {
        $transport = new RecordingTransport(new Response(200));
        $this->client($transport, fn(HttpClientConfig $c) => $c->header('Authorization', 'Bearer t'))
            ->get('https://example.com/');

        $this->assertSame('Bearer t', $transport->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testPerRequestHeaderOverridesDefaultHeader(): void
    {
        $transport = new RecordingTransport(new Response(200));
        $this->client($transport, fn(HttpClientConfig $c) => $c->header('Accept', 'application/xml'))
            ->get('https://example.com/', ['headers' => ['Accept' => 'application/json']]);

        $this->assertSame('application/json', $transport->lastRequest()->getHeaderLine('Accept'));
    }

    public function testPostSendsBody(): void
    {
        $transport = new RecordingTransport(new Response(201));
        $this->client($transport)->post('https://example.com/', ['body' => '{"a":1}']);

        $this->assertSame('POST', $transport->lastRequest()->getMethod());
        $this->assertSame('{"a":1}', (string) $transport->lastRequest()->getBody());
    }

    public function testRetriesOnServerErrorThenSucceeds(): void
    {
        $transport = new RecordingTransport(new Response(503), new Response(503), new Response(200));
        $response = $this->client(
            $transport,
            fn(HttpClientConfig $c) => $c->retry(3, 0), // 0ms backoff keeps the test fast
        )->get('https://example.com/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, $transport->requests);
    }

    public function testRetriesOn429(): void
    {
        $transport = new RecordingTransport(new Response(429), new Response(200));
        $response = $this->client($transport, fn(HttpClientConfig $c) => $c->retry(2, 0))->get('https://example.com/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $transport->requests);
    }

    public function testRetriesOnNetworkExceptionThenSucceeds(): void
    {
        $transport = new RecordingTransport(null, new Response(200));
        $response = $this->client($transport, fn(HttpClientConfig $c) => $c->retry(2, 0))->get('https://example.com/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $transport->requests);
    }

    public function testExhaustedRetriesRethrowsNetworkException(): void
    {
        $transport = new RecordingTransport(null, null, null);
        $this->expectException(NetworkException::class);
        $this->client($transport, fn(HttpClientConfig $c) => $c->retry(2, 0))->get('https://example.com/');
    }

    public function testReturnsServerErrorWhenRetriesDisabled(): void
    {
        $transport = new RecordingTransport(new Response(500));
        $response = $this->client($transport)->get('https://example.com/');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $transport->requests);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $transport = new RecordingTransport(new Response(404), new Response(200));
        $response = $this->client($transport, fn(HttpClientConfig $c) => $c->retry(3, 0))->get('https://example.com/');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(1, $transport->requests);
    }
}
