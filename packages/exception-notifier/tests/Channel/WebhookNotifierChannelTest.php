<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Quiote\ExceptionNotifier\Channel\WebhookNotifierChannel;
use Quiote\ExceptionNotifier\ExceptionNotificationContext;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Test\Http\Client\RecordingTransport;

final class WebhookNotifierChannelTest extends TestCase
{
    private function factory(RecordingTransport $transport): HttpClientFactory
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(static fn() => $transport);
        return $factory;
    }

    private function recordedRequest(RecordingTransport $transport): RequestInterface
    {
        $request = $transport->lastRequest();
        if ($request === null) {
            throw new RuntimeException('Expected the transport to have recorded a request.');
        }
        return $request;
    }

    /** @return array<array-key, mixed> */
    private function decodedPayload(RecordingTransport $transport): array
    {
        $body = json_decode((string) $this->recordedRequest($transport)->getBody(), true);
        self::assertIsArray($body);
        return $body;
    }

    public function testPostsAJsonPayloadToTheConfiguredWebhookUrl(): void
    {
        $transport = new RecordingTransport(200);
        $channel = WebhookNotifierChannel::fromChannelConfig(
            [
                'name' => 'ops',
                'webhook_url' => 'https://hooks.example/notify',
                'headers' => ['X-Api-Key' => 'secret'],
            ],
            $this->factory($transport),
        );

        $context = new ExceptionNotificationContext(
            status: 500,
            requestMethod: 'POST',
            requestUri: 'https://app.example/orders',
            correlationId: 'abc-123',
            timestamp: 1_700_000_000.5,
        );

        $channel->notify(new RuntimeException('database is on fire'), $context);

        $request = $this->recordedRequest($transport);
        $this->assertSame('POST', $request->getMethod());
        // Posting to "" against a base URI that is the whole webhook URL must hit that URL
        // unchanged -- appending anything, even a trailing slash, would corrupt a signed query
        // string (e.g. a Power Automate trigger URL's HMAC-covered `sig=`).
        $this->assertSame('https://hooks.example/notify', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('secret', $request->getHeaderLine('X-Api-Key'));

        $body = $this->decodedPayload($transport);
        $this->assertSame(RuntimeException::class, $body['exception_class']);
        $this->assertSame('database is on fire', $body['message']);
        $this->assertSame(500, $body['status']);
        $this->assertSame('abc-123', $body['correlation_id']);
        $this->assertSame('POST', $body['request_method']);
        $this->assertSame('https://app.example/orders', $body['request_uri']);
        $this->assertIsArray($body['trace']);
    }

    public function testThrowsWhenTheWebhookRespondsWithAnErrorStatus(): void
    {
        // 500 is a retryable status; the channel's built-in retry(2) means HttpClient
        // makes three attempts (initial + 2 retries) before giving up and returning the
        // last response, which the channel then treats as a failure.
        $transport = new RecordingTransport(500, 500, 500);
        $channel = WebhookNotifierChannel::fromChannelConfig(
            ['webhook_url' => 'https://hooks.example/notify'],
            $this->factory($transport),
        );

        $this->expectException(RuntimeException::class);
        $channel->notify(new RuntimeException('boom'), new ExceptionNotificationContext(
            status: 500,
            requestMethod: 'GET',
            requestUri: 'https://app.example/x',
            correlationId: null,
            timestamp: 1_700_000_000.0,
        ));
    }

    public function testRequiresAWebhookUrl(): void
    {
        $this->expectException(RuntimeException::class);
        WebhookNotifierChannel::fromChannelConfig([], $this->factory(new RecordingTransport(200)));
    }

    public function testNonStringHeaderValuesAreIgnored(): void
    {
        $transport = new RecordingTransport(200);
        $channel = WebhookNotifierChannel::fromChannelConfig(
            [
                'webhook_url' => 'https://hooks.example/notify',
                'headers' => ['X-Api-Key' => 'secret', 'X-Bad' => 123],
            ],
            $this->factory($transport),
        );

        $channel->notify(new RuntimeException('boom'), new ExceptionNotificationContext(
            status: 500,
            requestMethod: 'GET',
            requestUri: 'https://app.example/x',
            correlationId: null,
            timestamp: 1_700_000_000.0,
        ));

        $request = $this->recordedRequest($transport);
        $this->assertSame('secret', $request->getHeaderLine('X-Api-Key'));
        $this->assertFalse($request->hasHeader('X-Bad'));
    }
}
