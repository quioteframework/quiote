<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Context;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\ErrorResponseFactory;
use Quiote\Runtime\OutputCapture;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\RoadRunner\RoadRunnerRuntime;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Collects whatever the runtime reports back to the server. */
final class ErrorCollectingWorker implements WorkerInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function waitPayload(): ?\Spiral\RoadRunner\Payload
    {
        return null;
    }

    public function respond(\Spiral\RoadRunner\Payload $payload): void
    {
    }

    public function error(string $error): void
    {
        $this->errors[] = $error;
    }

    public function stop(): void
    {
    }

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?\Spiral\RoadRunner\Payload
    {
        return null;
    }
}

/**
 * Feeds the runtime a scripted sequence of requests. RoadRunner's own
 * PSR7WorkerInterface is what the loop talks to, so no server is needed.
 */
final class FakePsr7Worker implements PSR7WorkerInterface
{
    /** @var list<ServerRequestInterface|Throwable|null> */
    private array $script;

    /** @var list<int> */
    public array $responded = [];

    public ErrorCollectingWorker $worker;

    /** @param list<ServerRequestInterface|Throwable|null> $script */
    public function __construct(array $script)
    {
        $this->script = $script;
        $this->worker = new ErrorCollectingWorker();
    }

    /** @return list<string> */
    public function reportedErrors(): array
    {
        return $this->worker->errors;
    }

    public function waitRequest(): ?ServerRequestInterface
    {
        if ($this->script === []) {
            return null;
        }
        $next = array_shift($this->script);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }

    public function respond(ResponseInterface $response): void
    {
        $this->responded[] = $response->getStatusCode();
    }

    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }
}

/** Records what the runtime emitted, standing in for the relay-backed emitter. */
final class RecordingRoadRunnerEmitter implements ResponseEmitterInterface
{
    /** @var list<int> */
    public array $emitted = [];

    public ?Throwable $throws = null;

    public function emit(ResponseInterface $response): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }
        $this->emitted[] = $response->getStatusCode();
    }

    public function supportsStreaming(): bool
    {
        return true;
    }
}

final class RoadRunnerRuntimeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    private string $savedUseCookies = '1';

    #[Before]
    public function captureProcessState(): void
    {
        $this->savedServer = $_SERVER;
        // bootWorker() turns ext/session's own Set-Cookie emission off, since
        // off-SAPI it would go out through a dead header() call. That is a
        // process-global ini change, so it has to be put back or the rest of the
        // suite runs with sessions unable to set cookies.
        $current = ini_get('session.use_cookies');
        $this->savedUseCookies = is_string($current) ? $current : '1';
    }

    #[After]
    public function restoreProcessState(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];
        ini_set('session.use_cookies', $this->savedUseCookies);
    }

    /** @param (callable(): ResponseInterface)|null $handler */
    private function makeLoop(?callable $handler = null, int $maxRequests = 0): WorkerLoop
    {
        $handler ??= static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200);

        $context = $this->createStub(Context::class);
        $context->method('getName')->willReturn('web');
        // The context delegates to its request handler now, so that is what the stub answers with.
        $requestHandler = $this->createStub(RequestHandlerInterface::class);
        $requestHandler->method('handle')->willReturnCallback($handler);
        $context->method('getRequestHandler')->willReturn($requestHandler);

        return new WorkerLoop(
            context: $context,
            requestFactory: new WorkerRequestFactory(trustForwardedHeaders: false),
            superglobals: new SuperglobalBridge(),
            output: new OutputCapture(OutputCapture::POLICY_APPEND),
            errors: new ErrorResponseFactory(),
            capabilities: (new RoadRunnerRuntime(new FakePsr7Worker([])))->capabilities(),
            maxRequests: $maxRequests,
        );
    }

    private static function request(string $path = '/thing'): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path, [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
        ]);
    }

    public function testCapabilitiesDescribeACliHostedPersistentWorker(): void
    {
        $capabilities = (new RoadRunnerRuntime(new FakePsr7Worker([])))->capabilities();

        $this->assertTrue($capabilities->persistent);
        // The three that make RoadRunner different from FrankenPHP.
        $this->assertFalse($capabilities->populatesSuperglobals);
        $this->assertFalse($capabilities->sapiOutput);
        $this->assertFalse($capabilities->forksWorkers);
        $this->assertTrue($capabilities->streaming);
    }

    public function testAliasAndPriority(): void
    {
        $this->assertSame('roadrunner', RoadRunnerRuntime::alias());
        $this->assertSame(100, RoadRunnerRuntime::detectionPriority());
    }

    public function testIsSupportedFollowsTheModeTheServerSets(): void
    {
        $original = getenv('RR_MODE');
        try {
            putenv('RR_MODE=http');
            $this->assertTrue(RoadRunnerRuntime::isSupported());

            putenv('RR_MODE=jobs');
            $this->assertFalse(RoadRunnerRuntime::isSupported());

            putenv('RR_MODE');
            $this->assertFalse(RoadRunnerRuntime::isSupported());
        } finally {
            if (is_string($original)) {
                putenv('RR_MODE=' . $original);
            } else {
                putenv('RR_MODE');
            }
        }
    }

    public function testEveryRequestIsHandledAndEmittedUntilTheServerStops(): void
    {
        $worker = new FakePsr7Worker([self::request('/one'), self::request('/two'), null]);
        $emitter = new RecordingRoadRunnerEmitter();
        $loop = $this->makeLoop();

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertSame([200, 200], $emitter->emitted);
        $this->assertSame(2, $loop->requestsHandled());
        $this->assertSame([], $worker->reportedErrors());
    }

    public function testAMalformedPayloadIsReportedAndTheWorkerKeepsServing(): void
    {
        $worker = new FakePsr7Worker([
            new JsonException('bad payload'),
            self::request('/after'),
            null,
        ]);
        $emitter = new RecordingRoadRunnerEmitter();
        $loop = $this->makeLoop();

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertCount(1, $worker->reportedErrors());
        $this->assertStringContainsString('bad payload', $worker->reportedErrors()[0]);
        // One bad payload must not cost the pool a worker.
        $this->assertSame([200], $emitter->emitted);
    }

    public function testAFailingRequestBecomesAnErrorResponseRatherThanKillingTheLoop(): void
    {
        $worker = new FakePsr7Worker([self::request('/boom'), self::request('/ok'), null]);
        $emitter = new RecordingRoadRunnerEmitter();
        $attempt = 0;
        $loop = $this->makeLoop(static function () use (&$attempt): ResponseInterface {
            $attempt++;
            if ($attempt === 1) {
                throw new RuntimeException('action exploded');
            }
            return (new Psr17Factory())->createResponse(200);
        });

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertCount(2, $emitter->emitted);
        $this->assertGreaterThanOrEqual(500, $emitter->emitted[0]);
        $this->assertSame(200, $emitter->emitted[1]);
        // The pipeline handled it, so the server was never told about an error.
        $this->assertSame([], $worker->reportedErrors());
    }

    public function testAFailedEmissionIsReportedAndStillResetsRequestState(): void
    {
        $worker = new FakePsr7Worker([self::request(), null]);
        $emitter = new RecordingRoadRunnerEmitter();
        $emitter->throws = new RuntimeException('relay closed');
        $loop = $this->makeLoop();

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertCount(1, $worker->reportedErrors());
        $this->assertStringContainsString('relay closed', $worker->reportedErrors()[0]);
        // afterRequest() runs in a finally, so hydrated superglobals are cleared
        // even when emission blew up.
        $this->assertSame([], $_GET);
    }

    public function testNoRequestAtAllIsACleanShutdown(): void
    {
        $worker = new FakePsr7Worker([null]);
        $emitter = new RecordingRoadRunnerEmitter();
        $loop = $this->makeLoop();

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertSame([], $emitter->emitted);
        $this->assertSame(0, $loop->requestsHandled());
    }

    public function testAMaxRequestsBudgetStopsTheLoopEarly(): void
    {
        $worker = new FakePsr7Worker([self::request(), self::request(), self::request(), null]);
        $emitter = new RecordingRoadRunnerEmitter();
        $loop = $this->makeLoop(maxRequests: 2);

        (new RoadRunnerRuntime($worker, $emitter))->run($loop);

        $this->assertSame([200, 200], $emitter->emitted);
    }

    public function testSuperglobalsAreHydratedForTheLegacyCodeThatStillReadsThem(): void
    {
        $seenScriptName = null;
        $seenQuery = null;
        $worker = new FakePsr7Worker([self::request()->withQueryParams(['q' => 'hello']), null]);
        $loop = $this->makeLoop(static function () use (&$seenScriptName, &$seenQuery): ResponseInterface {
            // Routing reads $_SERVER['SCRIPT_NAME'] to build URLs, and RoadRunner
            // never sets it, so this is the assertion that matters most.
            $seenScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
            $seenQuery = $_GET;
            return (new Psr17Factory())->createResponse(200);
        });

        (new RoadRunnerRuntime($worker, new RecordingRoadRunnerEmitter()))->run($loop);

        $this->assertSame('/index.php', $seenScriptName);
        $this->assertSame(['q' => 'hello'], $seenQuery);
        $this->assertSame([], $_GET, 'afterRequest() must clear them again');
    }

    public function testACustomWorkerWithoutAMatchingEmitterIsRejectedWithAnActionableMessage(): void
    {
        $runtime = new RoadRunnerRuntime(new FakePsr7Worker([null]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a matching emitter/');
        $runtime->run($this->makeLoop());
    }
}
