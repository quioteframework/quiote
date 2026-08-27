<?php

namespace Quiote\ExceptionNotifier;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Event\Lifecycle\ExceptionCaughtEvent;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Logging\CategoryLogger;
use Quiote\Logging\Log;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\CorrelationId;
use Throwable;

/**
 * Listens for {@see ExceptionCaughtEvent} (emitted by
 * {@see \Quiote\Middleware\ErrorHandlingMiddleware}) and fans a notification
 * out to every enabled `exception_notifier.channels` entry, subject to the
 * `exception_notifier.min_status` severity filter, the
 * `exception_notifier.ignore` class list, and throttling.
 *
 * Registered once at plugin boot (see {@see ExceptionNotifierPlugin}), before
 * any {@see Context} exists, so {@see HttpClientFactory} and the throttle are
 * resolved lazily on each invocation rather than captured at construction —
 * the same pattern {@see \Quiote\Queue\Db\QueueDbPlugin} uses for its PDO
 * connection.
 */
final class ExceptionNotificationListener
{
    private readonly CategoryLogger $logger;

    public function __construct(
        private readonly ?HttpClientFactory $httpClientFactory = null,
        private readonly ?ExceptionNotificationThrottle $throttle = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->logger = Log::for($this);
    }

    public function __invoke(ExceptionCaughtEvent $event): void
    {
        if (!Config::getBool('exception_notifier.enabled', false)) {
            return;
        }

        $exception = $event->exception;
        $status = ExceptionStatusMapper::map($exception);

        if ($status < Config::getInt('exception_notifier.min_status', 500)) {
            return;
        }

        if ($this->isIgnored($exception)) {
            return;
        }

        $throttle = $this->throttle ?? $this->resolveThrottle();
        if ($throttle->shouldSuppress($exception)) {
            $this->logger->debugWith(fn(): string => sprintf(
                '[ExceptionNotifier] suppressed duplicate notification for %s within the throttle window',
                $exception::class,
            ));
            return;
        }

        $context = $this->buildContext($event, $status);
        $httpClientFactory = $this->httpClientFactory ?? $this->resolveHttpClientFactory();

        foreach ($this->enabledChannelConfigs() as $channelConfig) {
            $this->notifyChannel($channelConfig, $exception, $context, $httpClientFactory);
        }
    }

    /** @param array<string, mixed> $channelConfig */
    private function notifyChannel(array $channelConfig, Throwable $exception, ExceptionNotificationContext $context, HttpClientFactory $httpClientFactory): void
    {
        $driver = is_string($channelConfig['driver'] ?? null) ? $channelConfig['driver'] : '';
        $name = is_string($channelConfig['name'] ?? null) ? $channelConfig['name'] : $driver;

        try {
            $channel = ExceptionNotifierChannelRegistry::instantiate($driver, $channelConfig, $httpClientFactory);
            $channel->notify($exception, $context);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                '[ExceptionNotifier] channel "%s" (driver "%s") failed to deliver a notification for %s: %s: %s',
                $name,
                $driver,
                $exception::class,
                $e::class,
                $e->getMessage(),
            ));
        }
    }

    private function isIgnored(Throwable $exception): bool
    {
        foreach (Config::getArray('exception_notifier.ignore', []) as $ignoredClass) {
            if (is_string($ignoredClass) && $exception instanceof $ignoredClass) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    private function enabledChannelConfigs(): array
    {
        $configs = [];
        foreach (Config::getArray('exception_notifier.channels', []) as $channelConfig) {
            if (!is_array($channelConfig)) {
                continue;
            }
            if (($channelConfig['enabled'] ?? true) === false) {
                continue;
            }
            $configs[] = $channelConfig;
        }
        return $configs;
    }

    private function buildContext(ExceptionCaughtEvent $event, int $status): ExceptionNotificationContext
    {
        $request = $event->request;
        $correlationId = CorrelationId::fromRequest($request, 'Correlation-Id')
            ?? CorrelationId::fromRequest($request, 'X-Correlation-ID');

        return new ExceptionNotificationContext(
            status: $status,
            requestMethod: $request->getMethod(),
            requestUri: (string) $request->getUri(),
            correlationId: $correlationId,
            timestamp: ($this->clock ?? $this->resolveContainer()->get(ClockInterface::class))->microtime(),
        );
    }

    private function resolveHttpClientFactory(): HttpClientFactory
    {
        return $this->resolveContainer()->get(HttpClientFactory::class);
    }

    private function resolveThrottle(): ExceptionNotificationThrottle
    {
        return $this->resolveContainer()->get(ExceptionNotificationThrottle::class);
    }

    private function resolveContainer(): Container
    {
        return Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
    }
}
