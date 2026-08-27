<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Event\Lifecycle\ExceptionCaughtEvent;
use Quiote\ExceptionNotifier\ExceptionNotificationListener;
use Quiote\ExceptionNotifier\ExceptionNotificationThrottle;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Test\Http\Client\RecordingTransport;

final class ExceptionNotificationListenerTest extends TestCase
{
    private const array CONFIG_KEYS = [
        'exception_notifier.enabled',
        'exception_notifier.min_status',
        'exception_notifier.ignore',
        'exception_notifier.channels',
    ];

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfig[$key] = Config::has($key) ? Config::get($key) : null;
        }
        Config::set('exception_notifier.enabled', true);
        Config::set('exception_notifier.min_status', 500);
        Config::set('exception_notifier.ignore', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            if ($value === null) {
                Config::remove($key);
            } else {
                Config::set($key, $value);
            }
        }
        parent::tearDown();
    }

    private function httpClientFactory(RecordingTransport $transport): HttpClientFactory
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(static fn() => $transport);
        return $factory;
    }

    private function event(Throwable $exception): ExceptionCaughtEvent
    {
        return new ExceptionCaughtEvent($exception, new ServerRequest('GET', 'https://example.com/boom'));
    }

    public function testNotifiesAllEnabledChannelsOnAQualifyingException(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
            ['driver' => 'webhook', 'name' => 'b', 'webhook_url' => 'https://b.example/hook'],
        ]);

        $transport = new RecordingTransport(200, 200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(2, $transport->requests);
    }

    public function testDisabledChannelIsSkipped(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook', 'enabled' => false],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(0, $transport->requests);
    }

    public function testBelowMinStatusIsNotNotified(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        // InvalidArgumentException maps to 400, below the 500 default threshold.
        $listener($this->event(new InvalidArgumentException('bad input')));

        $this->assertCount(0, $transport->requests);
    }

    public function testIgnoredExceptionClassIsNotNotified(): void
    {
        Config::set('exception_notifier.ignore', [RuntimeException::class]);
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(0, $transport->requests);
    }

    public function testASubclassOfAnIgnoredExceptionIsAlsoNotNotified(): void
    {
        Config::set('exception_notifier.ignore', [LogicException::class]);
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        // DomainException extends LogicException and maps to 422, but the ignore
        // list check happens before the min_status filter here, so lower it first.
        Config::set('exception_notifier.min_status', 0);
        $listener($this->event(new DomainException('unprocessable')));

        $this->assertCount(0, $transport->requests);
    }

    public function testDisabledFeatureSkipsAllChannels(): void
    {
        Config::set('exception_notifier.enabled', false);
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $listener = new ExceptionNotificationListener($this->httpClientFactory($transport));

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(0, $transport->requests);
    }

    public function testThrottledExceptionIsNotNotifiedTwice(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200, 200);
        $clock = new FrozenClock(1000.0);
        $throttle = new ExceptionNotificationThrottle($clock, 60);
        $listener = new ExceptionNotificationListener($this->httpClientFactory($transport), $throttle, $clock);

        $listener($this->event(new RuntimeException('boom')));
        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(1, $transport->requests);
    }

    public function testAThrottledExceptionIsNotifiedAgainAfterTheWindowElapses(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'webhook', 'name' => 'a', 'webhook_url' => 'https://a.example/hook'],
        ]);

        $transport = new RecordingTransport(200, 200);
        $clock = new FrozenClock(1000.0);
        $throttle = new ExceptionNotificationThrottle($clock, 60);
        $listener = new ExceptionNotificationListener($this->httpClientFactory($transport), $throttle, $clock);

        $listener($this->event(new RuntimeException('boom')));
        $clock->advance(61.0);
        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(2, $transport->requests);
    }

    public function testAFailingChannelDoesNotPreventTheOthersFromBeingNotified(): void
    {
        Config::set('exception_notifier.channels', [
            // Missing webhook_url: fails inside fromChannelConfig(), before any HTTP call.
            ['driver' => 'webhook', 'name' => 'broken'],
            ['driver' => 'webhook', 'name' => 'b', 'webhook_url' => 'https://b.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(1, $transport->requests);
    }

    public function testAnUnknownChannelDriverDoesNotThrowOrPreventOtherChannels(): void
    {
        Config::set('exception_notifier.channels', [
            ['driver' => 'does-not-exist', 'name' => 'a'],
            ['driver' => 'webhook', 'name' => 'b', 'webhook_url' => 'https://b.example/hook'],
        ]);

        $transport = new RecordingTransport(200);
        $clock = new FrozenClock(1000.0);
        $listener = new ExceptionNotificationListener(
            $this->httpClientFactory($transport),
            new ExceptionNotificationThrottle($clock, 60),
            $clock,
        );

        $listener($this->event(new RuntimeException('boom')));

        $this->assertCount(1, $transport->requests);
    }
}
