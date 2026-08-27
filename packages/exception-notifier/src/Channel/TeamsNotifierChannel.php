<?php

namespace Quiote\ExceptionNotifier\Channel;

use Quiote\ExceptionNotifier\ExceptionNotificationContext;
use Quiote\ExceptionNotifier\NotifierChannelFactoryInterface;
use Quiote\ExceptionNotifier\NotifierChannelInterface;
use Quiote\Http\Client\HttpClient;
use Quiote\Http\Client\HttpClientConfig;
use Quiote\Http\Client\HttpClientFactory;
use RuntimeException;
use Throwable;

/**
 * Posts an Adaptive Card (schema 1.4) to a Microsoft Teams incoming webhook
 * or Power Automate workflow URL — the format Microsoft currently recommends
 * for Teams; the older MessageCard/O365 connector card format is deprecated.
 */
final readonly class TeamsNotifierChannel implements NotifierChannelInterface, NotifierChannelFactoryInterface
{
    private const int MAX_TRACE_FRAMES = 5;

    public function __construct(private HttpClient $client)
    {
    }

    public static function fromChannelConfig(array $channelConfig, HttpClientFactory $httpClientFactory): NotifierChannelInterface
    {
        $webhookUrl = $channelConfig['webhook_url'] ?? null;
        if (!is_string($webhookUrl) || $webhookUrl === '') {
            throw new RuntimeException('An exception_notifier "teams" channel requires a "webhook_url".');
        }

        $clientName = 'exception_notifier.' . self::channelName($channelConfig);
        $httpClientFactory->configure($clientName, static function (HttpClientConfig $config) use ($webhookUrl): void {
            $config->baseUri($webhookUrl)
                ->header('Content-Type', 'application/json')
                ->retry(2);
        });

        return new self($httpClientFactory->client($clientName));
    }

    public function notify(Throwable $exception, ExceptionNotificationContext $context): void
    {
        $response = $this->client->post('', [
            'body' => json_encode($this->buildCard($exception, $context), JSON_THROW_ON_ERROR),
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException(sprintf('Teams webhook responded with HTTP %d.', $response->getStatusCode()));
        }
    }

    /** @return array<string, mixed> */
    private function buildCard(Throwable $exception, ExceptionNotificationContext $context): array
    {
        $facts = [
            ['title' => 'Status', 'value' => (string) $context->status],
            ['title' => 'Request', 'value' => $context->requestMethod . ' ' . $context->requestUri],
        ];
        if ($context->correlationId !== null) {
            $facts[] = ['title' => 'Correlation ID', 'value' => $context->correlationId];
        }
        $facts[] = ['title' => 'Time', 'value' => gmdate('Y-m-d H:i:s', (int) $context->timestamp) . ' UTC'];

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            [
                                'type' => 'TextBlock',
                                'text' => $exception::class,
                                'weight' => 'Bolder',
                                'size' => 'Medium',
                                'color' => 'Attention',
                                'wrap' => true,
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $exception->getMessage() !== '' ? $exception->getMessage() : '(no message)',
                                'wrap' => true,
                            ],
                            [
                                'type' => 'FactSet',
                                'facts' => $facts,
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $this->truncatedTrace($exception),
                                'wrap' => true,
                                'fontType' => 'Monospace',
                                'size' => 'Small',
                                'isSubtle' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function truncatedTrace(Throwable $exception): string
    {
        $lines = explode("\n", $exception->getTraceAsString());
        return implode("\n", array_slice($lines, 0, self::MAX_TRACE_FRAMES));
    }

    /** @param array<string, mixed> $channelConfig */
    private static function channelName(array $channelConfig): string
    {
        $name = $channelConfig['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : 'teams';
    }
}
