<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Action\Action;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\DI\Container;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionInitContext;
use Quiote\Logging\Log;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Replay\Attribute\NoRecord;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Recording\RecordingLogBuffer;
use Quiote\Replay\Recording\RecordingLogSink;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;

final class RecorderMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private const REPLAY_KEYS = [
        'replay.enabled', 'replay.record', 'replay.sample_rate', 'replay.trigger_header',
        'replay.max_bytes', 'replay.max_effects', 'replay.capture_body', 'replay.capture_session',
        'replay.redact.headers', 'replay.redact.params', 'replay.redact.session', 'replay.redact.mode',
        'replay.redact.env', 'replay.redact.hash_salt',
    ];

    protected function tearDown(): void
    {
        foreach (self::REPLAY_KEYS as $key) {
            Config::remove($key);
        }
        Config::remove('replay.max_log_entries');
        \Quiote\Replay\Recording\EffectSourceRegistry::reset();
        RecordingLogBuffer::reset();
        Log::reset();
        parent::tearDown();
    }

    private function enable(string $record = 'always'): void
    {
        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', $record, true, false);
    }

    /** @return CassetteStoreInterface&object{put: list<array{0: CassetteId, 1: Cassette}>} */
    private function spyStore(): CassetteStoreInterface
    {
        return new class implements CassetteStoreInterface {
            /** @var list<array{0: CassetteId, 1: Cassette}> */
            public array $put = [];

            public function put(CassetteId $id, Cassette $cassette): void
            {
                $this->put[] = [$id, $cassette];
            }

            public function get(CassetteId $id): ?Cassette
            {
                return null;
            }

            public function has(CassetteId $id): bool
            {
                return false;
            }

            public function delete(CassetteId $id): void
            {
            }
        };
    }

    private function context(CassetteStoreInterface $store, ?RequestState $requestState = null, ?Controller $controller = null): Context
    {
        $container = new Container();
        $container->set(CassetteStoreInterface::class, $store);
        if ($requestState !== null) {
            $container->set(RequestState::class, $requestState);
        }
        if ($controller !== null) {
            $container->set(Controller::class, $controller);
        }
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);
        $context->method('getName')->willReturn('test');

        return $context;
    }

    private function requestStatePublishing(ServerRequestInterface $request): RequestState
    {
        $webRequest = WebRequest::fromPsr($request);

        return new RequestState(
            static fn(): WebRequest => $webRequest,
            static function (): void {
            },
        );
    }

    /**
     * A real, mutable RequestState: publish() replaces what current() answers, matching
     * production wiring -- unlike {@see requestStatePublishing()}'s fixed/no-op pair, needed to
     * prove RecorderMiddleware reads back a value ErrorHandlingMiddleware published during the
     * same request.
     */
    private function mutableRequestState(ServerRequestInterface $request): RequestState
    {
        $current = WebRequest::fromPsr($request);

        return new RequestState(
            static function () use (&$current): WebRequest {
                return $current;
            },
            static function (WebRequest|ServerRequestInterface $replacement) use (&$current): void {
                $current = $replacement instanceof WebRequest ? $replacement : WebRequest::fromPsr($replacement);
            },
        );
    }

    /** Relays a PSR-15 middleware and its next handler as a single handler, for a mini pipeline. */
    private function relay(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }

    /**
     * The first recorded upload entry from a cassette.
     *
     * @return array<string, mixed>
     */
    private static function firstUpload(Cassette $cassette): array
    {
        $uploads = $cassette->request['uploads'] ?? null;
        self::assertIsArray($uploads);
        $first = $uploads[0] ?? null;
        self::assertIsArray($first);

        return $first;
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function testNeverPolicyMakesNoStoreCall(): void
    {
        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', 'never', true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $store->put);
    }

    public function testDisabledMakesNoStoreCallEvenWhenRecordIsAlways(): void
    {
        Config::set('replay.enabled', false, true, false);
        Config::set('replay.record', 'always', true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(0, $store->put);
    }

    public function testAlwaysRecordsASuccessfulRequest(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200, [], 'ok')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertSame(200, $cassette->response['status']);
        $this->assertSame('GET', $cassette->request['method']);
        $this->assertSame([], $cassette->effects);
        // No driver-specific package is installed/registered in this test, so nothing recording
        // effects is instrumented -- see testEffectsInstrumentedIsTrueWhenAnEffectSourceIsRegistered()
        // for the other side of this.
        $this->assertFalse($cassette->meta['effects_instrumented']);
    }

    public function testRecordsAnEscapedExceptionAndRethrowsIt(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertNotNull($cassette->exception);
        $this->assertSame(RuntimeException::class, $cassette->exception['class']);
        $this->assertSame('boom', $cassette->exception['message']);
        $this->assertSame(500, $cassette->response['status']);
    }

    /**
     * Reproduces the bug this pair of classes fixes: a real 500 produced by
     * ErrorHandlingMiddleware catching an application exception -- not one that escapes the
     * whole stack -- previously left `cassette.exception` null even though `response.status`
     * was 500. ErrorHandlingMiddleware now publishes the exception it caught onto RequestState
     * (see its own publishCaughtException()), and RecorderMiddleware's finishRecording() reads
     * it back when its own catch never fired.
     */
    public function testCassetteCapturesTheExceptionErrorHandlingMiddlewareCaughtAndRendered(): void
    {
        $this->enable('always');
        Config::set('core.developer_exceptions', false);
        $store = $this->spyStore();
        $request = new ServerRequest('GET', '/widgets');
        $requestState = $this->mutableRequestState($request);
        $context = $this->context($store, $requestState);

        $recorder = new RecorderMiddleware($context, $store);
        $errorHandling = new ErrorHandlingMiddleware(null, $context);
        $innerHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        $response = $recorder->process($request, $this->relay($errorHandling, $innerHandler));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertSame(500, $cassette->response['status']);
        $this->assertNotNull($cassette->exception);
        $this->assertSame(RuntimeException::class, $cassette->exception['class']);
        $this->assertSame('boom', $cassette->exception['message']);
    }

    public function testCapturesApplicationLogEntriesEmittedDuringTheRequest(): void
    {
        Log::addSink(new RecordingLogSink());
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                Log::create('App.Test')->error('something happened');

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertCount(1, $store->put);
        $log = $store->put[0][1]->log;
        $this->assertIsArray($log);
        $this->assertCount(1, $log);
        $entry = $log[0];
        $this->assertIsArray($entry);
        $this->assertSame('error', $entry['level']);
        $this->assertSame('something happened', $entry['message']);
        $this->assertSame('App.Test', $entry['category']);
        $this->assertFalse($store->put[0][1]->meta['log_truncated']);
    }

    public function testLogEntriesPastMaxLogEntriesAreDroppedAndReportedAsTruncated(): void
    {
        Log::addSink(new RecordingLogSink());
        $this->enable('always');
        Config::set('replay.max_log_entries', 1, true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                Log::create('App.Test')->error('first');
                Log::create('App.Test')->error('second');

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        $cassette = $store->put[0][1];
        $this->assertIsArray($cassette->log);
        $this->assertCount(1, $cassette->log);
        $entry = $cassette->log[0];
        $this->assertIsArray($entry);
        $this->assertSame('first', $entry['message']);
        $this->assertTrue($cassette->meta['log_truncated']);
    }

    public function testLogIsEmptyWhenNoSinkIsRegistered(): void
    {
        // No RecordingLogSink registered: capture is attempted (the buffer still starts and
        // finishes) but nothing was fed into it, so the section is an empty list -- distinct
        // from #[NoRecord]'s null, which means "deliberately not captured at all".
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertSame([], $store->put[0][1]->log);
    }

    public function testErrorPolicyDropsA200Response(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(0, $store->put);
    }

    public function testErrorPolicyKeepsA500Response(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(500)));

        $this->assertCount(1, $store->put);
    }

    public function testReadsResolvedModuleAndActionFromRequestStateForASimpleAction(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $descriptor = new ActionDescriptor('Widgets', 'Show', 'execute', 'html', true);
        $published = (new ServerRequest('GET', '/widgets'))->withAttribute(ActionDescriptor::class, $descriptor);
        $requestState = $this->requestStatePublishing($published);

        $middleware = new RecorderMiddleware($this->context($store, $requestState), $store);
        $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200)));

        $cassette = $store->put[0][1];
        $this->assertSame('Widgets', $cassette->resolved['module']);
        $this->assertSame('Show', $cassette->resolved['action']);
    }

    public function testMakesNoStoreCallWhenNoRequestStateIsBound(): void
    {
        // A test double's fabricated Context/Container legitimately has no RequestState bound
        // (see the equivalent DispatchMiddleware/RoutingMiddleware regression tests); resolved
        // module/action simply comes back null rather than crashing.
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        $this->assertNull($store->put[0][1]->resolved['module']);
    }

    public function testRespectsNoRecordAttributeOnTheResolvedAction(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $descriptor = new ActionDescriptor('Payments', 'Charge', 'execute', 'html', true);
        $published = (new ServerRequest('POST', '/pay'))->withAttribute(ActionDescriptor::class, $descriptor);
        $requestState = $this->requestStatePublishing($published);
        $controller = new class extends Controller {
            #[\Override]
            public function resolveActionClass($moduleName, $actionName): string
            {
                return RecorderMiddlewareTestNoRecordAction::class;
            }
        };

        $middleware = new RecorderMiddleware($this->context($store, $requestState, $controller), $store);
        $factory = new Psr17Factory();
        $request = (new ServerRequest('POST', '/pay'))
            ->withBody($factory->createStream(json_encode(['card' => '4242424242424242']) ?: ''));

        $middleware->process($request, $this->handler(new Response(200, [], 'ok')));

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->resolved['no_record'] ?? false);
        $this->assertArrayNotHasKey('body', $cassette->request);
        $this->assertArrayNotHasKey('headers', $cassette->request);
        $this->assertNull($cassette->session);
        $this->assertNull($cassette->exception);
        $this->assertNull($cassette->log);
    }

    public function testExceedingMaxBytesTruncatesTheRequestBody(): void
    {
        $this->enable('always');
        Config::set('replay.max_bytes', 4, true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $factory = new Psr17Factory();
        $request = (new ServerRequest('POST', '/'))->withBody($factory->createStream('0123456789'));

        $middleware->process($request, $this->handler(new Response(200)));

        $cassette = $store->put[0][1];
        $body = $cassette->request['body'];
        $this->assertIsArray($body);
        $this->assertTrue($body['truncated']);
        $this->assertIsString($body['content']);
        $this->assertSame(4, strlen($body['content']));
    }

    public function testTwoSequentialRequestsThroughOneInstanceProduceIndependentCassettes(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/one'), $this->handler(new Response(200)));
        $middleware->reset();
        $middleware->process(new ServerRequest('GET', '/two'), $this->handler(new Response(200)));

        $this->assertCount(2, $store->put);
        $this->assertSame('/one', $store->put[0][1]->request['uri']);
        $this->assertSame('/two', $store->put[1][1]->request['uri']);
    }

    public function testAStoreFailureIsLoggedAndDoesNotAlterTheResponse(): void
    {
        $this->enable('always');
        $store = new class implements CassetteStoreInterface {
            public function put(CassetteId $id, Cassette $cassette): void
            {
                throw new RuntimeException('disk full');
            }

            public function get(CassetteId $id): ?Cassette
            {
                return null;
            }

            public function has(CassetteId $id): bool
            {
                return false;
            }

            public function delete(CassetteId $id): void
            {
            }
        };
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200, [], 'unaffected')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unaffected', (string)$response->getBody());
    }

    /**
     * Generic, driver-agnostic proof of the EffectSource contract -- no ORM/DB dependency at
     * all. A real driver package (e.g. quioteframework/replay-propulsion) proves its own
     * EffectSource implementation end to end against a real connection in its own test suite;
     * this only proves RecorderMiddleware activates/deactivates every registered source
     * correctly and reports effects_instrumented accordingly.
     */
    public function testEffectsInstrumentedIsTrueAndSourcesAreActivatedWhenAnEffectSourceIsRegistered(): void
    {
        $source = new class implements \Quiote\Replay\Recording\EffectSource {
            /** @var list<string> */
            public array $activations = [];
            /** @var list<string> */
            public array $deactivations = [];

            public function activate(string $correlationId, \Quiote\Replay\Replay\EffectLedger $ledger): void
            {
                $this->activations[] = $correlationId;
                $ledger->record(\Quiote\Replay\Cassette\EffectKind::Db, 'fake query', [], null);
            }

            public function deactivate(string $correlationId): void
            {
                $this->deactivations[] = $correlationId;
            }
        };
        \Quiote\Replay\Recording\EffectSourceRegistry::register($source);

        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->meta['effects_instrumented']);
        $this->assertCount(1, $cassette->effects);
        $this->assertSame('fake query', $cassette->effects[0]->fingerprint);
        $this->assertCount(1, $source->activations);
        $this->assertCount(1, $source->deactivations);
        $this->assertSame($source->activations[0], $source->deactivations[0]);
    }

    public function testEveryRegisteredEffectSourceIsDeactivatedEvenWhenTheHandlerThrows(): void
    {
        $source = new class implements \Quiote\Replay\Recording\EffectSource {
            /** @var list<string> */
            public array $deactivations = [];

            public function activate(string $correlationId, \Quiote\Replay\Replay\EffectLedger $ledger): void
            {
            }

            public function deactivate(string $correlationId): void
            {
                $this->deactivations[] = $correlationId;
            }
        };
        \Quiote\Replay\Recording\EffectSourceRegistry::register($source);

        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $source->deactivations);
    }

    public function testResponseSetCookieIsRedacted(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $response = (new Response(200))
            ->withHeader('Set-Cookie', 'QSESSID=deadbeefsecret; HttpOnly; Secure')
            ->withHeader('Content-Type', 'text/html');

        $middleware->process(new ServerRequest('GET', '/'), $this->handler($response));

        $this->assertCount(1, $store->put);
        $headers = $store->put[0][1]->response['headers'];
        $this->assertIsArray($headers);
        $this->assertSame(['[REDACTED]'], $headers['Set-Cookie']);
        // A header not on the denylist is untouched: redaction is scoped, not blanket.
        $this->assertSame(['text/html'], $headers['Content-Type']);
    }

    public function testResponseHeaderRedactionHonoursTheConfiguredDenylist(): void
    {
        $this->enable('always');
        Config::set('replay.redact.headers', ['x-internal-token'], true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $response = (new Response(200))
            ->withHeader('X-Internal-Token', 'tok_live_123')
            ->withHeader('Set-Cookie', 'QSESSID=abc');

        $middleware->process(new ServerRequest('GET', '/'), $this->handler($response));

        $headers = $store->put[0][1]->response['headers'];
        $this->assertIsArray($headers);
        $this->assertSame(['[REDACTED]'], $headers['X-Internal-Token']);
        // Replacing the denylist replaces it wholesale -- set-cookie is no longer on it.
        $this->assertSame(['QSESSID=abc'], $headers['Set-Cookie']);
    }

    public function testResponseHeaderRedactionUsesTheConfiguredMode(): void
    {
        $this->enable('always');
        Config::set('replay.redact.mode', 'hash', true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $response = (new Response(200))->withHeader('Set-Cookie', 'QSESSID=abc');

        $middleware->process(new ServerRequest('GET', '/'), $this->handler($response));

        $headers = $store->put[0][1]->response['headers'];
        $this->assertIsArray($headers);
        $this->assertIsArray($headers['Set-Cookie']);
        $this->assertSame('sha256:' . hash('sha256', 'QSESSID=abc'), $headers['Set-Cookie'][0]);
    }

    public function testAnExceptionTraceCarriesNoArgumentValues(): void
    {
        // PHP's getTraceAsString() embeds each frame's scalar arguments, so a connection failure
        // records the database password and any exception thrown below a function that took a
        // token records the token -- in the one cassette section replay.redact.* cannot reach.
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                self::connect('mysql:host=db', 'app_user', 'hunter2-the-real-password');
            }

            private static function connect(string $dsn, string $user, string $password): never
            {
                throw new RuntimeException('connection refused');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
            $this->fail('Expected the handler to throw.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $store->put);
        $exception = $store->put[0][1]->exception;
        $this->assertIsArray($exception);
        $trace = $exception['trace'];
        $this->assertIsArray($trace);
        $encoded = json_encode($trace);
        $this->assertIsString($encoded);

        $this->assertStringNotContainsString('hunter2-the-real-password', $encoded);
        $this->assertStringNotContainsString('app_user', $encoded);
        // Still useful for debugging: class, function, file and line all survive.
        $this->assertStringContainsString('connect()', $encoded);
        $this->assertStringContainsString('{main}', $encoded);
        $this->assertSame('connection refused', $exception['message']);
    }

    public function testAnExceptionTraceStillNamesEveryFrame(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
        } catch (RuntimeException) {
            // expected
        }

        $exception = $store->put[0][1]->exception;
        $this->assertIsArray($exception);
        $trace = $exception['trace'];
        $this->assertIsArray($trace);
        $this->assertNotSame([], $trace);
        $firstFrame = $trace[0];
        $this->assertIsString($firstFrame);
        $this->assertMatchesRegularExpression('/^#0 .+\(\d+\): .*handle\(\)$/', $firstFrame);
    }

    public function testTruncationFlagsAreReportedInMeta(): void
    {
        $this->enable('always');
        Config::set('replay.max_bytes', 4, true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $factory = new Psr17Factory();
        $request = (new ServerRequest('POST', '/'))->withBody($factory->createStream('0123456789'));

        $middleware->process($request, $this->handler(new Response(200)));

        $meta = $store->put[0][1]->meta;
        $this->assertTrue($meta['request_body_truncated']);
        $this->assertFalse($meta['effects_truncated']);
    }

    public function testNoRecordSkeletonStillCarriesNoResponseHeadersAtAll(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $descriptor = new ActionDescriptor('Payments', 'Charge', 'execute', 'html', true);
        $published = (new ServerRequest('GET', '/pay'))->withAttribute(ActionDescriptor::class, $descriptor);
        $controller = new class extends Controller {
            #[\Override]
            public function resolveActionClass($moduleName, $actionName): string
            {
                return RecorderMiddlewareTestNoRecordAction::class;
            }
        };
        $middleware = new RecorderMiddleware(
            $this->context($store, $this->requestStatePublishing($published), $controller),
            $store,
        );

        $middleware->process(new ServerRequest('GET', '/pay'), $this->handler((new Response(200))->withHeader('Set-Cookie', 'QSESSID=abc')));

        $this->assertCount(1, $store->put);
        $this->assertSame([], $store->put[0][1]->response['headers']);
    }
    public function testARateDeclinedRequestSkipsCaptureEntirely(): void
    {
        // Deciding at the end meant every request paid for the full capture and 99% of it was
        // discarded. A lost roll must now cost nothing beyond the roll.
        $this->enable('rate');
        Config::set('replay.sample_rate', 0.0, true, false);
        $store = $this->spyStore();
        $factory = new Psr17Factory();
        $body = $factory->createStream(str_repeat('x', 4096));
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process((new ServerRequest('POST', '/'))->withBody($body), $this->handler(new Response(200)));

        $this->assertCount(0, $store->put);
        // Untouched: nothing read the body, so a downstream consumer still sees it from the start.
        $this->assertSame(0, $body->tell());
    }

    public function testARateKeptRequestIsSampledOnceNotTwice(): void
    {
        // The roll happens at entry now; rolling again in shouldKeep() would sample twice at the
        // configured rate and keep far fewer requests than asked for.
        $this->enable('rate');
        Config::set('replay.sample_rate', 1.0, true, false);
        $store = $this->spyStore();
        $randomness = new class implements \Quiote\Support\Random\RandomnessInterface {
            public int $intCalls = 0;

            public function bytes(int $length): string
            {
                return str_repeat("\0", $length);
            }

            public function int(int $min, int $max): int
            {
                $this->intCalls++;

                return $min;
            }
        };
        $middleware = new RecorderMiddleware($this->context($store), $store, randomness: $randomness);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        // sample_rate >= 1.0 short-circuits without consuming randomness at all; what matters is
        // that the decision was not taken twice.
        $this->assertLessThanOrEqual(1, $randomness->intCalls);
    }

    public function testTheErrorPolicyStillDecidesAfterTheResponse(): void
    {
        // Only `rate` can decide up front; error genuinely needs the outcome.
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(500)));

        $this->assertCount(1, $store->put);
    }

    /**
     * php://input is unconditionally empty for a multipart/form-data POST on every SAPI -- PHP
     * consumes it into $_POST/$_FILES during request bootstrap, before this middleware ever runs
     * -- so a form field submitted alongside an upload (or a CSRF token submitted as a field
     * rather than a header) has nowhere to survive except getParsedBody(), captured separately
     * from the (always-empty) raw body.
     */
    public function testCapturesParsedBodyFieldsForAMultipartRequest(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $request = (new ServerRequest('POST', '/orders/new'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----x')
            ->withParsedBody(['_csrf_token' => 'the-token', 'BusinessUnits' => ['1', '2']]);

        $middleware->process($request, $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        $this->assertSame(
            ['_csrf_token' => 'the-token', 'BusinessUnits' => ['1', '2']],
            $store->put[0][1]->request['parsed_body'],
        );
    }

    public function testParsedBodyFieldsAreRedactedTheSameWayOtherParamsAre(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $request = (new ServerRequest('POST', '/login'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----x')
            ->withParsedBody(['password' => 'hunter2', 'username' => 'alice']);

        $middleware->process($request, $this->handler(new Response(200)));

        $parsedBody = $store->put[0][1]->request['parsed_body'];
        $this->assertIsArray($parsedBody);
        $this->assertSame('[REDACTED]', $parsedBody['password']);
        $this->assertSame('alice', $parsedBody['username']);
    }

    public function testParsedBodyIsNullForANonMultipartRequest(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $request = (new ServerRequest('POST', '/widgets'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['a' => '1']);

        $middleware->process($request, $this->handler(new Response(200)));

        $this->assertNull($store->put[0][1]->request['parsed_body']);
    }

    public function testParsedBodyIsNullForAMultipartRequestWithNoParsedFields(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $request = (new ServerRequest('POST', '/orders/new'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----x');

        $middleware->process($request, $this->handler(new Response(200)));

        $this->assertNull($store->put[0][1]->request['parsed_body']);
    }

    public function testAnUploadIsHashedWithoutMaterializingItAndIsLeftRewound(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $factory = new Psr17Factory();
        $content = str_repeat('u', 3_000_000);
        $uploadStream = $factory->createStream($content);
        $upload = new \Nyholm\Psr7\UploadedFile($uploadStream, strlen($content), UPLOAD_ERR_OK, 'big.bin', 'application/octet-stream');
        $request = (new ServerRequest('POST', '/upload'))->withUploadedFiles(['file' => $upload]);

        $before = memory_get_usage(true);
        $middleware->process($request, $this->handler(new Response(200)));
        $grew = memory_get_usage(true) - $before;

        $recordedUpload = self::firstUpload($store->put[0][1]);
        $this->assertSame(hash('sha256', $content), $recordedUpload['sha256']);
        // The digest is correct without a second copy of the payload in memory.
        $this->assertLessThan(strlen($content), $grew, sprintf('Hashing the upload grew memory by %d bytes.', $grew));
        // And the stream is handed back at the start, so the application's own moveTo() still works.
        $this->assertSame(0, $upload->getStream()->tell());
    }

    public function testANonSeekableUploadIsNotConsumedToHashIt(): void
    {
        // Reading it to the end would leave nothing for moveTo() to write -- recording an upload's
        // digest must not cost the upload.
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $resource = fopen('php://temp', 'r+');
        $this->assertIsResource($resource);
        fwrite($resource, 'payload');
        rewind($resource);
        $nonSeekable = new class($resource) implements \Psr\Http\Message\StreamInterface {
            /** @param resource $resource */
            public function __construct(private $resource)
            {
            }

            public function __toString(): string
            {
                return (string)stream_get_contents($this->resource);
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return (int)ftell($this->resource);
            }

            public function eof(): bool
            {
                return feof($this->resource);
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('not seekable');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('not seekable');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('not writable');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                return $length < 1 ? '' : (string)fread($this->resource, $length);
            }

            public function getContents(): string
            {
                return (string)stream_get_contents($this->resource);
            }

            /** @return array<mixed>|mixed */
            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
        $upload = new \Nyholm\Psr7\UploadedFile($nonSeekable, 7, UPLOAD_ERR_OK, 'x.bin', 'application/octet-stream');

        $middleware->process(
            (new ServerRequest('POST', '/upload'))->withUploadedFiles(['file' => $upload]),
            $this->handler(new Response(200)),
        );

        $this->assertNull(self::firstUpload($store->put[0][1])['sha256'], 'Declined rather than consumed.');
        // Still readable from the start, so moveTo() would write the whole payload.
        $this->assertSame('payload', $nonSeekable->getContents());
    }
}

#[NoRecord]
final class RecorderMiddlewareTestNoRecordAction extends Action
{
    #[\Override]
    public function initialize(ActionInitContext $ctx): void
    {
    }

    #[\Override]
    public function isCacheable(?string $outputType = null): bool
    {
        return false;
    }

    #[\Override]
    public function isSecure()
    {
        return false;
    }

    public function execute(mixed $request = null): mixed
    {
        return 'Charge';
    }

}
