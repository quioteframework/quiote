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
 * Posts a plain JSON payload describing the caught exception to any webhook
 * URL. Reference implementation for a custom {@see NotifierChannelInterface}:
 * everything channel-specific lives in {@see fromChannelConfig()} and
 * {@see notify()}.
 */
final readonly class WebhookNotifierChannel implements NotifierChannelInterface, NotifierChannelFactoryInterface
{
    public function __construct(private HttpClient $client)
    {
    }

    public static function fromChannelConfig(array $channelConfig, HttpClientFactory $httpClientFactory): NotifierChannelInterface
    {
        $webhookUrl = $channelConfig['webhook_url'] ?? null;
        if (!is_string($webhookUrl) || $webhookUrl === '') {
            throw new RuntimeException('An exception_notifier "webhook" channel requires a "webhook_url".');
        }

        $headers = self::stringHeaders($channelConfig);
        $clientName = 'exception_notifier.' . self::channelName($channelConfig);

        $httpClientFactory->configure($clientName, static function (HttpClientConfig $config) use ($webhookUrl, $headers): void {
            $config->baseUri($webhookUrl)
                ->header('Content-Type', 'application/json')
                ->headers($headers)
                ->retry(2);
        });

        return new self($httpClientFactory->client($clientName));
    }

    public function notify(Throwable $exception, ExceptionNotificationContext $context): void
    {
        $payload = [
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'status' => $context->status,
            'correlation_id' => $context->correlationId,
            'request_method' => $context->requestMethod,
            'request_uri' => $context->requestUri,
            'timestamp' => $context->timestamp,
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];

        $response = $this->client->post('', ['body' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException(sprintf('Webhook responded with HTTP %d.', $response->getStatusCode()));
        }
    }

    /**
     * @param array<string, mixed> $channelConfig
     * @return array<string, string>
     */
    private static function stringHeaders(array $channelConfig): array
    {
        $headers = [];
        $configured = $channelConfig['headers'] ?? [];
        if (!is_array($configured)) {
            return $headers;
        }
        foreach ($configured as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /** @param array<string, mixed> $channelConfig */
    private static function channelName(array $channelConfig): string
    {
        $name = $channelConfig['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : 'webhook';
    }
}
