<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Quiote\ExceptionNotifier\Channel\TeamsNotifierChannel;
use Quiote\ExceptionNotifier\ExceptionNotificationContext;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Test\Http\Client\RecordingTransport;

final class TeamsNotifierChannelTest extends TestCase
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
    private function decodedCard(RecordingTransport $transport): array
    {
        $body = json_decode((string) $this->recordedRequest($transport)->getBody(), true);
        self::assertIsArray($body);
        return $body;
    }

    /**
     * @param array<array-key, mixed> $card
     * @return array<array-key, mixed>
     */
    private function cardFacts(array $card): array
    {
        $attachments = $card['attachments'];
        self::assertIsArray($attachments);
        $attachment = $attachments[0];
        self::assertIsArray($attachment);
        $content = $attachment['content'];
        self::assertIsArray($content);
        $body = $content['body'];
        self::assertIsArray($body);
        $factSet = $body[2];
        self::assertIsArray($factSet);
        $facts = $factSet['facts'];
        self::assertIsArray($facts);
        return $facts;
    }

    public function testPostsAnAdaptiveCardToTheConfiguredWebhookUrl(): void
    {
        $transport = new RecordingTransport(200);
        $channel = TeamsNotifierChannel::fromChannelConfig(
            ['driver' => 'teams', 'name' => 'ops', 'webhook_url' => 'https://outlook.office.com/webhook/xyz'],
            $this->factory($transport),
        );

        $context = new ExceptionNotificationContext(
            status: 500,
            requestMethod: 'POST',
            requestUri: 'https://app.example/orders',
            correlationId: 'abc-123',
            timestamp: 1_700_000_000.0,
        );

        $channel->notify(new RuntimeException('database is on fire'), $context);

        $request = $this->recordedRequest($transport);
        $this->assertSame('POST', $request->getMethod());
        // HttpClient joins the base URI with a relative path via "/" + ltrim,
        // so posting to "" against this base URI yields a trailing slash.
        $this->assertSame('https://outlook.office.com/webhook/xyz/', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $card = $this->decodedCard($transport);
        $this->assertSame('message', $card['type']);

        $attachments = $card['attachments'];
        self::assertIsArray($attachments);
        $attachment = $attachments[0];
        self::assertIsArray($attachment);
        $content = $attachment['content'];
        self::assertIsArray($content);
        $this->assertSame('AdaptiveCard', $content['type']);
        $this->assertSame('1.4', $content['version']);

        $cardBody = $content['body'];
        self::assertIsArray($cardBody);
        $titleBlock = $cardBody[0];
        self::assertIsArray($titleBlock);
        $title = $titleBlock['text'];
        self::assertIsString($title);
        $this->assertStringContainsString('RuntimeException', $title);

        $messageBlock = $cardBody[1];
        self::assertIsArray($messageBlock);
        $this->assertSame('database is on fire', $messageBlock['text']);

        $facts = $this->cardFacts($card);
        $this->assertSame(['title' => 'Status', 'value' => '500'], $facts[0]);
        $this->assertSame(['title' => 'Correlation ID', 'value' => 'abc-123'], $facts[2]);
    }

    public function testOmitsTheCorrelationIdFactWhenNonePresent(): void
    {
        $transport = new RecordingTransport(200);
        $channel = TeamsNotifierChannel::fromChannelConfig(
            ['webhook_url' => 'https://outlook.office.com/webhook/xyz'],
            $this->factory($transport),
        );

        $channel->notify(new RuntimeException('boom'), new ExceptionNotificationContext(
            status: 500,
            requestMethod: 'GET',
            requestUri: 'https://app.example/x',
            correlationId: null,
            timestamp: 1_700_000_000.0,
        ));

        $facts = $this->cardFacts($this->decodedCard($transport));
        $titles = array_column($facts, 'title');
        $this->assertNotContains('Correlation ID', $titles);
    }

    public function testThrowsWhenTheWebhookRespondsWithAnErrorStatus(): void
    {
        // 400 is not a retryable status, so this is a single deterministic attempt.
        $transport = new RecordingTransport(400);
        $channel = TeamsNotifierChannel::fromChannelConfig(
            ['webhook_url' => 'https://outlook.office.com/webhook/xyz'],
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
        TeamsNotifierChannel::fromChannelConfig([], $this->factory(new RecordingTransport(200)));
    }
}
