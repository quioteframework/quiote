<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Runtime\Swoole\NativeSwooleServerFactory;
use Quiote\Runtime\Swoole\SwooleRuntime;
use Quiote\Runtime\Swoole\SwooleServerFactory;
use Quiote\Runtime\Swoole\SwooleServerInterface;
use Quiote\Runtime\Worker\WorkerLoop;

/** Captures the wiring without binding a port or needing ext-swoole. */
final class RecordingSwooleServer implements SwooleServerInterface
{
    public bool $started = false;

    /** @var callable(): void|null */
    public $workerStart = null;

    public ?object $requestListener = null;

    public function onWorkerStart(callable $listener): void
    {
        $this->workerStart = $listener;
    }

    public function onRequest(callable $listener): void
    {
        $this->requestListener = \Closure::fromCallable($listener);
    }

    public function start(): void
    {
        $this->started = true;
    }
}

final class RecordingSwooleServerFactory implements SwooleServerFactory
{
    public ?string $host = null;
    public ?int $port = null;
    /** @var array<string, mixed> */
    public array $settings = [];

    public RecordingSwooleServer $server;

    public function __construct()
    {
        $this->server = new RecordingSwooleServer();
    }

    public function create(string $host, int $port, array $settings): SwooleServerInterface
    {
        $this->host = $host;
        $this->port = $port;
        $this->settings = $settings;

        return $this->server;
    }
}

final class SwooleRuntimeTest extends TestCase
{
    #[Before]
    #[After]
    public function clearSettings(): void
    {
        foreach ([
            'worker.swoole.host',
            'worker.swoole.port',
            'worker.swoole.worker_num',
            'worker.swoole.enable_coroutine',
            'worker.swoole.allow_coroutine_unsafe',
            'worker.swoole.max_request',
            'worker.swoole.package_max_length',
            'worker.swoole.script_name',
            'worker.swoole.ssl',
        ] as $key) {
            Config::remove($key);
        }
    }

    public function testAliasAndPriority(): void
    {
        $this->assertSame('swoole', SwooleRuntime::alias());
        $this->assertSame(100, SwooleRuntime::detectionPriority());
    }

    public function testCapabilitiesDescribeAForkingCliHostedWorker(): void
    {
        $capabilities = (new SwooleRuntime())->capabilities();

        $this->assertTrue($capabilities->persistent);
        $this->assertFalse($capabilities->populatesSuperglobals);
        $this->assertFalse($capabilities->sapiOutput);
        $this->assertTrue($capabilities->streaming);
        // The one thing that sets Swoole apart from RoadRunner: workers are
        // forked after bootstrap, so inherited DB sockets have to be dropped.
        $this->assertTrue($capabilities->forksWorkers);
    }

    public function testIsSupportedNeedsTheExplicitOptInNotJustTheExtension(): void
    {
        $original = getenv('QUIOTE_WORKER_RUNTIME');
        try {
            putenv('QUIOTE_WORKER_RUNTIME');
            // The extension is routinely loaded under php-fpm, so claiming the
            // process on extension_loaded() alone would hijack FPM requests.
            $this->assertFalse(SwooleRuntime::isSupported());

            putenv('QUIOTE_WORKER_RUNTIME=swoole');
            $this->assertSame(
                extension_loaded('swoole') && PHP_SAPI === 'cli',
                SwooleRuntime::isSupported(),
            );
        } finally {
            if (is_string($original)) {
                putenv('QUIOTE_WORKER_RUNTIME=' . $original);
            } else {
                putenv('QUIOTE_WORKER_RUNTIME');
            }
        }
    }

    public function testDefaultSettingsPinOneRequestAtATimePerProcess(): void
    {
        $settings = SwooleRuntime::settings();

        $this->assertFalse($settings['enable_coroutine']);
        $this->assertSame(1, $settings['worker_num']);
        $this->assertSame(0, $settings['max_request']);
        $this->assertSame(8 * 1024 * 1024, $settings['package_max_length']);
    }

    public function testSettingsFollowConfig(): void
    {
        Config::set('worker.swoole.worker_num', 8);
        Config::set('worker.swoole.max_request', 5000);
        Config::set('worker.swoole.package_max_length', 32 * 1024 * 1024);

        $settings = SwooleRuntime::settings();

        $this->assertSame(8, $settings['worker_num']);
        $this->assertSame(5000, $settings['max_request']);
        $this->assertSame(32 * 1024 * 1024, $settings['package_max_length']);
    }

    public function testNonsenseWorkerCountsAreClamped(): void
    {
        Config::set('worker.swoole.worker_num', 0);
        $this->assertSame(1, SwooleRuntime::settings()['worker_num']);

        Config::set('worker.swoole.worker_num', -4);
        $this->assertSame(1, SwooleRuntime::settings()['worker_num']);
    }

    public function testATinyPackageLimitIsClampedRatherThanBreakingEveryRequest(): void
    {
        Config::set('worker.swoole.package_max_length', 1);

        $this->assertSame(1024, SwooleRuntime::settings()['package_max_length']);
    }

    public function testEnablingCoroutinesIsRefusedAndTheMessageNamesTheUnsafeState(): void
    {
        Config::set('worker.swoole.enable_coroutine', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not coroutine-safe/');
        $this->expectExceptionMessageMatches('/worker\.swoole\.worker_num/');
        SwooleRuntime::settings();
    }

    public function testCoroutinesCanBeForcedOnBySomeoneWhoAcceptsTheConsequences(): void
    {
        Config::set('worker.swoole.enable_coroutine', true);
        Config::set('worker.swoole.allow_coroutine_unsafe', true);

        $this->assertTrue(SwooleRuntime::settings()['enable_coroutine']);
    }

    public function testRunWiresTheServerFromConfigAndStartsIt(): void
    {
        Config::set('worker.swoole.host', '127.0.0.1');
        Config::set('worker.swoole.port', 9501);
        $factory = new RecordingSwooleServerFactory();

        (new SwooleRuntime($factory))->run($this->makeLoop());

        $this->assertSame('127.0.0.1', $factory->host);
        $this->assertSame(9501, $factory->port);
        $this->assertTrue($factory->server->started);
        $this->assertFalse($factory->settings['enable_coroutine']);
    }

    public function testThePostForkHookIsRegisteredOnWorkerStart(): void
    {
        $factory = new RecordingSwooleServerFactory();
        $loop = $this->makeLoop();

        (new SwooleRuntime($factory))->run($loop);

        // Registered rather than called: Swoole invokes it inside each forked
        // child, which is the only point at which inherited sockets can be
        // dropped.
        $this->assertIsCallable($factory->server->workerStart);
        $this->assertNotNull($factory->server->requestListener);
    }

    public function testTheNativeFactoryFailsWithAnActionableMessageWithoutTheExtension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ext-swoole is not installed/');
        $this->expectExceptionMessageMatches('/pecl install swoole/');
        (new NativeSwooleServerFactory(extensionAvailable: false))->create('127.0.0.1', 9501, []);
    }

    public function testTheNativeFactoryDetectsTheExtensionFromTheEnvironmentByDefault(): void
    {
        // Constructing the factory must not itself need the extension -- the
        // runtime is instantiated during auto-detection, before anything has
        // decided to serve with it.
        $factory = new NativeSwooleServerFactory();

        $this->assertInstanceOf(NativeSwooleServerFactory::class, $factory);
    }

    private function makeLoop(): WorkerLoop
    {
        $context = $this->createStub(Quiote\Context::class);
        $context->method('getName')->willReturn('web');

        return new WorkerLoop(
            context: $context,
            requestFactory: new Quiote\Runtime\Request\WorkerRequestFactory(trustForwardedHeaders: false),
            superglobals: new Quiote\Runtime\Superglobals\SuperglobalBridge(),
            output: new Quiote\Runtime\OutputCapture(),
            errors: new Quiote\Runtime\ErrorResponseFactory(),
            capabilities: (new SwooleRuntime())->capabilities(),
        );
    }
}
