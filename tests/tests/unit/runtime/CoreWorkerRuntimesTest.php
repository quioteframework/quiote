<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\ErrorResponseFactory;
use Quiote\Runtime\OutputCapture;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\Session\NativeSessionCookieBridge;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Runtime\Worker\FrankenPhpRuntime;
use Quiote\Runtime\Worker\SapiRuntime;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;

/** Records what a runtime handed it, so the loop's shape can be asserted. */
final class RecordingEmitter implements ResponseEmitterInterface
{
    /** @var list<int> */
    public array $emitted = [];

    public function __construct(private readonly bool $streaming = true)
    {
    }

    public function emit(ResponseInterface $response): void
    {
        $this->emitted[] = $response->getStatusCode();
    }

    public function supportsStreaming(): bool
    {
        return $this->streaming;
    }
}

/**
 * The FrankenPHP loop was previously untestable: it called the extension
 * function directly, so nothing could drive it on a machine without FrankenPHP.
 * FrankenPhpRuntime takes the handle-request callable as a constructor argument
 * precisely so this test can exist.
 */
final class CoreWorkerRuntimesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    #[Before]
    public function captureServer(): void
    {
        $this->savedServer = $_SERVER;
    }

    #[After]
    public function restoreServer(): void
    {
        $_SERVER = $this->savedServer;
    }

    /** @param (callable(): ResponseInterface)|null $handler */
    private function makeLoop(
        WorkerRuntimeCapabilities $capabilities,
        ?callable $handler = null,
        int $maxRequests = 0,
    ): WorkerLoop {
        $handler ??= static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200);

        $context = $this->createStub(Context::class);
        $context->method('getName')->willReturn('web');
        $context->method('handle')->willReturnCallback($handler);

        return new WorkerLoop(
            context: $context,
            requestFactory: new WorkerRequestFactory(trustForwardedHeaders: false),
            superglobals: new SuperglobalBridge(),
            output: new OutputCapture(OutputCapture::POLICY_APPEND),
            errors: new ErrorResponseFactory(),
            sessionCookies: new NativeSessionCookieBridge(),
            capabilities: $capabilities,
            maxRequests: $maxRequests,
        );
    }

    public function testSapiRuntimeIsAlwaysAvailableAtTheLowestPriority(): void
    {
        $this->assertTrue(SapiRuntime::isSupported());
        $this->assertSame('sapi', SapiRuntime::alias());
        $this->assertSame(PHP_INT_MIN, SapiRuntime::detectionPriority());

        $capabilities = (new SapiRuntime())->capabilities();
        $this->assertFalse($capabilities->persistent);
        $this->assertTrue($capabilities->populatesSuperglobals);
        $this->assertTrue($capabilities->sapiOutput);
    }

    public function testSapiRuntimeHandlesExactlyOneRequest(): void
    {
        $emitter = new RecordingEmitter();
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi());

        (new SapiRuntime($emitter))->run($loop);

        $this->assertSame([200], $emitter->emitted);
        $this->assertSame(1, $loop->requestsHandled());
    }

    public function testFrankenPhpRuntimeReportsItselfAsAPersistentSapi(): void
    {
        $capabilities = (new FrankenPhpRuntime(static fn(): bool => false))->capabilities();

        $this->assertTrue($capabilities->persistent);
        // FrankenPHP *is* a SAPI, which is why it needs none of the off-SAPI
        // compensations the CLI-hosted runtimes do.
        $this->assertTrue($capabilities->populatesSuperglobals);
        $this->assertTrue($capabilities->sapiOutput);
        $this->assertTrue($capabilities->streaming);
        $this->assertFalse($capabilities->forksWorkers);

        $this->assertSame('frankenphp', FrankenPhpRuntime::alias());
        $this->assertSame(100, FrankenPhpRuntime::detectionPriority());
    }

    public function testFrankenPhpRuntimeIsUnsupportedWithoutTheExtensionFunction(): void
    {
        $this->assertSame(function_exists('frankenphp_handle_request'), FrankenPhpRuntime::isSupported());
    }

    public function testFrankenPhpRuntimeLoopsUntilTheHostSaysToStop(): void
    {
        $emitter = new RecordingEmitter();
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true));

        $answers = [true, true, false];
        $calls = 0;
        $handleRequest = static function (callable $handler) use (&$answers, &$calls): bool {
            $calls++;
            $handler();
            return array_shift($answers) ?? false;
        };

        (new FrankenPhpRuntime($handleRequest, $emitter))->run($loop);

        $this->assertSame(3, $calls);
        $this->assertSame([200, 200, 200], $emitter->emitted);
        $this->assertSame(3, $loop->requestsHandled());
    }

    public function testFrankenPhpRuntimeStopsOnceTheMaxRequestsBudgetIsSpent(): void
    {
        $emitter = new RecordingEmitter();
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true), maxRequests: 2);

        $handleRequest = static function (callable $handler): bool {
            $handler();
            return true; // the host would happily keep going
        };

        (new FrankenPhpRuntime($handleRequest, $emitter))->run($loop);

        $this->assertSame(2, $loop->requestsHandled());
    }

    public function testFrankenPhpRuntimeKeepsServingAfterAFailedRequest(): void
    {
        $emitter = new RecordingEmitter();
        $attempt = 0;
        $loop = $this->makeLoop(
            WorkerRuntimeCapabilities::sapi(persistent: true),
            static function () use (&$attempt): ResponseInterface {
                $attempt++;
                if ($attempt === 1) {
                    throw new RuntimeException('first request exploded');
                }
                return (new Psr17Factory())->createResponse(200);
            },
        );

        $answers = [true, false];
        $handleRequest = static function (callable $handler) use (&$answers): bool {
            $handler();
            return array_shift($answers) ?? false;
        };

        (new FrankenPhpRuntime($handleRequest, $emitter))->run($loop);

        // A broken request must not take the worker (and its share of the pool)
        // down with it.
        $this->assertCount(2, $emitter->emitted);
        $this->assertGreaterThanOrEqual(500, $emitter->emitted[0]);
        $this->assertSame(200, $emitter->emitted[1]);
    }
}
