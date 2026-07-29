<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\ErrorResponseFactory;
use Quiote\Runtime\OutputCapture;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;

final class WorkerLoopTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    private string $savedUseCookies = '1';

    #[Before]
    public function captureProcessState(): void
    {
        $this->savedServer = $_SERVER;
        // bootWorker() disables ext/session's own Set-Cookie emission for an
        // off-SAPI runtime, which is a process-global ini change.
        $current = ini_get('session.use_cookies');
        $this->savedUseCookies = is_string($current) ? $current : '1';
    }

    #[After]
    public function restoreProcessState(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];
        ini_set('session.use_cookies', $this->savedUseCookies);
        Config::remove('core.worker.stray_output');
    }

    /**
     * @param (callable(ServerRequestInterface): ResponseInterface)|null $handler
     */
    private function makeLoop(
        WorkerRuntimeCapabilities $capabilities,
        ?callable $handler = null,
        int $maxRequests = 0,
        ?OutputCapture $output = null,
    ): WorkerLoop {
        $handler ??= static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200);

        $context = $this->createStub(Context::class);
        $context->method('getName')->willReturn('web');
        $context->method('handle')->willReturnCallback($handler);

        return new WorkerLoop(
            context: $context,
            requestFactory: new WorkerRequestFactory(trustForwardedHeaders: false),
            superglobals: new SuperglobalBridge(),
            output: $output ?? new OutputCapture(OutputCapture::POLICY_APPEND),
            errors: new ErrorResponseFactory(),
            capabilities: $capabilities,
            maxRequests: $maxRequests,
        );
    }

    private static function request(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'http://localhost/thing', [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
        ]);
    }

    public function testHandleReturnsThePipelinesResponse(): void
    {
        $psr17 = new Psr17Factory();
        $loop = $this->makeLoop(
            WorkerRuntimeCapabilities::sapi(),
            static fn(): ResponseInterface => $psr17->createResponse(201)->withBody($psr17->createStream('ok')),
        );

        $response = $loop->handle(self::request());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    public function testHandleTurnsAThrowableIntoAResponseSoAWorkerSurvivesIt(): void
    {
        $loop = $this->makeLoop(
            WorkerRuntimeCapabilities::sapi(persistent: true),
            static fn(): ResponseInterface => throw new RuntimeException('exploded'),
        );

        $response = $loop->handle(self::request());

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    public function testHandleCountsRequests(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true));

        $this->assertSame(0, $loop->requestsHandled());
        $loop->handle(self::request());
        $loop->handle(self::request());
        $this->assertSame(2, $loop->requestsHandled());
    }

    public function testShouldContinueIsUnboundedWhenNoBudgetIsSet(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true), maxRequests: 0);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($loop->shouldContinue());
            $loop->handle(self::request());
        }
        $this->assertTrue($loop->shouldContinue());
    }

    public function testShouldContinueStopsOnceTheBudgetIsSpent(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true), maxRequests: 2);

        $this->assertTrue($loop->shouldContinue());
        $loop->handle(self::request());
        $this->assertTrue($loop->shouldContinue());
        $loop->handle(self::request());
        $this->assertFalse($loop->shouldContinue());
    }

    public function testASapiShapedRuntimeGetsNoneOfTheOffSapiCompensations(): void
    {
        $seen = null;
        $loop = $this->makeLoop(
            WorkerRuntimeCapabilities::sapi(),
            function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request;
                return (new Psr17Factory())->createResponse(200);
            },
        );
        $_GET = ['untouched' => 'yes'];

        $loop->handle(self::request());

        $this->assertInstanceOf(ServerRequestInterface::class, $seen);
        // unset_input is left at its default so WebRequest::startup() still
        // clears the SAPI's own input arrays, exactly as before.
        $this->assertNull($seen->getAttribute('unset_input'));
        $this->assertSame(['untouched' => 'yes'], $_GET);
    }

    public function testAnOffSapiRuntimeGetsSuperglobalsHydratedAndUnsetInputDisabled(): void
    {
        $seen = null;
        $loop = $this->makeLoop(
            new WorkerRuntimeCapabilities(
                persistent: true,
                populatesSuperglobals: false,
                sapiOutput: false,
                streaming: false,
                forksWorkers: false,
            ),
            function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request;
                return (new Psr17Factory())->createResponse(200);
            },
        );

        $loop->handle(self::request()->withQueryParams(['q' => 'hello']));

        $this->assertInstanceOf(ServerRequestInterface::class, $seen);
        // Otherwise WebRequest::startup() would wipe the arrays we just hydrated.
        $this->assertFalse($seen->getAttribute('unset_input'));
        $this->assertSame(['q' => 'hello'], $_GET);
        $this->assertSame('/index.php', $_SERVER['SCRIPT_NAME']);
    }

    public function testStrayOutputIsFoldedOntoTheBodyOffSapi(): void
    {
        $psr17 = new Psr17Factory();
        $loop = $this->makeLoop(
            new WorkerRuntimeCapabilities(
                persistent: true,
                populatesSuperglobals: false,
                sapiOutput: false,
                streaming: false,
                forksWorkers: false,
            ),
            static function () use ($psr17): ResponseInterface {
                echo 'leaked';
                return $psr17->createResponse(200)->withBody($psr17->createStream('body'));
            },
        );

        $response = $loop->handle(self::request());

        $this->assertSame('bodyleaked', (string) $response->getBody());
    }

    public function testStrayOutputIsNotCapturedWhenTheSapiCanEmitItItself(): void
    {
        $psr17 = new Psr17Factory();
        $loop = $this->makeLoop(
            WorkerRuntimeCapabilities::sapi(),
            static function () use ($psr17): ResponseInterface {
                echo 'leaked';
                return $psr17->createResponse(200)->withBody($psr17->createStream('body'));
            },
        );

        ob_start();
        $response = $loop->handle(self::request());
        $emitted = ob_get_clean();

        $this->assertSame('body', (string) $response->getBody());
        $this->assertSame('leaked', $emitted);
    }

    public function testStrayOutputAroundAStreamingResponseIsDroppedRatherThanCorruptingTheFraming(): void
    {
        $psr17 = new Psr17Factory();
        $stream = new SseStream(['tick']);
        $loop = $this->makeLoop(
            new WorkerRuntimeCapabilities(
                persistent: true,
                populatesSuperglobals: false,
                sapiOutput: false,
                streaming: true,
                forksWorkers: false,
            ),
            static function () use ($psr17, $stream): ResponseInterface {
                echo 'leaked';
                return $psr17->createResponse(200)->withBody($stream);
            },
        );

        $response = $loop->handle(self::request());

        $this->assertSame($stream, $response->getBody());
        $this->assertSame("data: tick\n\n", $stream->getContents());
    }

    public function testAThrowingStrayOutputPolicyBecomesAnErrorResponseNotAnEscapedThrowable(): void
    {
        $psr17 = new Psr17Factory();
        $loop = $this->makeLoop(
            new WorkerRuntimeCapabilities(
                persistent: true,
                populatesSuperglobals: false,
                sapiOutput: false,
                streaming: false,
                forksWorkers: false,
            ),
            static function () use ($psr17): ResponseInterface {
                echo 'leaked';
                return $psr17->createResponse(200);
            },
            output: new OutputCapture(OutputCapture::POLICY_THROW),
        );

        $response = $loop->handle(self::request());

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    public function testAfterRequestClearsHydratedSuperglobals(): void
    {
        $loop = $this->makeLoop(new WorkerRuntimeCapabilities(
            persistent: true,
            populatesSuperglobals: false,
            sapiOutput: false,
            streaming: false,
            forksWorkers: false,
        ));

        $loop->handle(self::request()->withQueryParams(['q' => 'hello']));
        $this->assertSame(['q' => 'hello'], $_GET);

        $loop->afterRequest();

        $this->assertSame([], $_GET);
    }

    public function testAfterRequestDoesNothingForANonPersistentRuntime(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: false));
        $loop->handle(self::request());
        $_GET = ['still' => 'here'];

        $loop->afterRequest();

        // The process is about to exit; paying for a reset would only slow the
        // response down.
        $this->assertSame(['still' => 'here'], $_GET);
    }

    public function testBootWorkerIsIdempotent(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi(persistent: true));

        $loop->bootWorker();
        $loop->bootWorker();

        $this->assertSame(0, $loop->requestsHandled());
    }

    public function testRenderErrorExposesTheErrorPathToARuntimeThatCaughtSomethingItself(): void
    {
        $loop = $this->makeLoop(WorkerRuntimeCapabilities::sapi());

        $response = $loop->renderError(new RuntimeException('relay died'));

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    public function testCapabilitiesAreExposedToTheRuntimeDrivingTheLoop(): void
    {
        $capabilities = WorkerRuntimeCapabilities::sapi(persistent: true);

        $this->assertSame($capabilities, $this->makeLoop($capabilities)->capabilities());
    }
}
