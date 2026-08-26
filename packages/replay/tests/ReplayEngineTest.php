<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Replay\ReplayEngine;
use Quiote\Replay\Replay\ReplayMode;
use Quiote\Replay\Replay\ReplayException;

final class ReplayEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::remove('replay.allow_live');
        Config::remove('core.csrf.enabled');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    private function cassette(array $request, array $response, ?string $id = 'CASSETTE1'): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $id],
            request: $request,
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: $response,
            exception: null,
            log: null,
        );
    }

    /** @return array{0: Context, 1: RecordingRequestHandler} */
    private function contextReturning(ResponseInterface $response): array
    {
        $handler = new RecordingRequestHandler($response);
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);

        return [$context, $handler];
    }

    /**
     * A context whose container binds a real {@see \Quiote\Session\SessionManager}, so
     * {@see \Quiote\Replay\Replay\ReplayEngine::applySessionOverride()}'s
     * `tryGet(SessionManager::class)` finds one and can read its configured cookie name back off
     * it.
     *
     * @return array{0: Context, 1: RecordingRequestHandler}
     */
    private function contextWithSessionManager(ResponseInterface $response, string $cookieName = 'QSID'): array
    {
        $handler = new RecordingRequestHandler($response);
        $container = new \Quiote\DI\Container();
        $sessionManager = new \Quiote\Session\SessionManager(
            new class implements \Quiote\Session\SessionPersistenceInterface {
                public function load(string $sid): ?array
                {
                    return null;
                }

                public function save(string $sid, array $data): void
                {
                }

                public function delete(string $sid): void
                {
                }
            },
            ['cookie_name' => $cookieName],
        );
        $container->set(\Quiote\Session\SessionManager::class, $sessionManager);
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $context->method('getContainer')->willReturn($container);

        return [$context, $handler];
    }

    public function testCleanReplayReportsNoDrift(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200, ['Content-Type' => ['text/plain']], 'hello'));
        $cassette = $this->cassette(
            ['method' => 'GET', 'uri' => '/widgets'],
            ['status' => 200, 'headers' => ['Content-Type' => ['text/plain']], 'body' => ['encoding' => 'utf8', 'content' => 'hello', 'truncated' => false]],
        );

        $result = (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);

        $this->assertTrue($result->drift->isClean());
        $this->assertSame(200, $result->response->getStatusCode());
    }

    public function testDispatchesTheReconstructedRequest(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/widgets/42'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette);

        $this->assertNotNull($handler->lastRequest);
        $this->assertSame('GET', $handler->lastRequest->getMethod());
        $this->assertSame('/widgets/42', $handler->lastRequest->getUri()->getPath());
    }

    public function testDriftIsReportedForAMismatchedResponse(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(500));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/widgets'], ['status' => 200, 'headers' => [], 'body' => []]);

        $result = (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);

        $this->assertFalse($result->drift->isClean());
        $this->assertTrue($result->drift->hasErrors());
    }

    public function testRefusesToRunWhenAllowLiveIsFalse(): void
    {
        Config::set('replay.allow_live', false, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/allow_live/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);
    }

    public function testRefusesANonSafeMethodWithoutForce(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'POST', 'uri' => '/orders'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/--force/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);
    }

    public function testAllowsANonSafeMethodWithForce(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'POST', 'uri' => '/orders'], ['status' => 200, 'headers' => [], 'body' => []]);

        $result = (new ReplayEngine())->replay($context, $cassette, force: true, mode: ReplayMode::Live);

        $this->assertSame(200, $result->response->getStatusCode());
    }

    /** @return iterable<string, array{0: string}> */
    public static function unsafeMethods(): iterable
    {
        yield 'post' => ['POST'];
        yield 'patch' => ['PATCH'];
        // Idempotent, but not safe: doing it twice leaves the same state as doing it once, which
        // says nothing about whether doing it at all is harmless. A recorded DELETE /accounts/42
        // replayed against a live application deletes account 42.
        yield 'put' => ['PUT'];
        yield 'delete' => ['DELETE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeMethods')]
    public function testAnIdempotentButUnsafeMethodStillNeedsForce(string $method): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => $method, 'uri' => '/accounts/42'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/not a safe method/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);
    }

    /** @return iterable<string, array{0: string}> */
    public static function safeMethods(): iterable
    {
        yield 'get' => ['GET'];
        yield 'head' => ['HEAD'];
        yield 'options' => ['OPTIONS'];
        yield 'trace' => ['TRACE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('safeMethods')]
    public function testASafeMethodReplaysWithoutForce(string $method): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => $method, 'uri' => '/things'], ['status' => 200, 'headers' => [], 'body' => []]);

        $result = (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);

        $this->assertSame(200, $result->response->getStatusCode());
    }

    public function testTheMethodGateIsCaseInsensitive(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'get', 'uri' => '/things'], ['status' => 200, 'headers' => [], 'body' => []]);

        $this->assertSame(200, (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live)->response->getStatusCode());
        $this->assertTrue(ReplayEngine::isSafeMethod('Get'));
        $this->assertFalse(ReplayEngine::isSafeMethod('delete'));
    }

    public function testAnUnrecordedRequestRefusesBeforeCheckingAllowLive(): void
    {
        // The request-shape check runs first; a #[NoRecord] skeleton can never be replayed
        // regardless of allow_live/force.
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette([], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/no replayable request/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);
    }

    /**
     * The concrete motivating case: a recorded path segment (an order id) that does not exist in
     * this environment. --uri points replay at one that does, and the app re-routes against it
     * exactly as it would a real request -- the recorded route_params are never consulted.
     */
    public function testUriOverrideReplaysAgainstTheGivenUriInsteadOfTheRecordedOne(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(
            ['method' => 'GET', 'uri' => '/orders/23940239'],
            ['status' => 200, 'headers' => [], 'body' => []],
        );

        $result = (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, uriOverride: '/orders/1?debug=1');

        $this->assertSame(200, $result->response->getStatusCode());
        $this->assertNotNull($handler->lastRequest);
        $this->assertSame('/orders/1', $handler->lastRequest->getUri()->getPath());
        $this->assertSame('debug=1', $handler->lastRequest->getUri()->getQuery());
    }

    public function testUriOverrideRejectsAUriPsr7WillNotAccept(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/orders/1'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/--uri/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, uriOverride: 'http:///a b');
    }

    public function testQueryOverrideMergesOntoTheRecordedQueryString(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/widgets?page=1&sort=name'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, queryOverrides: ['page=2']);

        $this->assertNotNull($handler->lastRequest);
        parse_str($handler->lastRequest->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '2', 'sort' => 'name'], $query);
    }

    public function testQueryOverrideSupportsBracketArraySyntaxAcrossRepeatedFlags(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/orders'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, queryOverrides: [
            'BusinessUnits[]=162345',
            'BusinessUnits[]=2415134',
        ]);

        $this->assertNotNull($handler->lastRequest);
        parse_str($handler->lastRequest->getUri()->getQuery(), $query);
        $this->assertSame(['BusinessUnits' => ['162345', '2415134']], $query);
    }

    /**
     * The concrete motivating case: a recorded form-urlencoded POST body carrying ids
     * (BusinessUnits[]=162345&BusinessUnits[]=2415134) this environment does not have.
     */
    public function testBodyOverrideMergesOntoAFormUrlencodedBody(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(
            [
                'method' => 'POST',
                'uri' => '/orders',
                'headers' => ['Content-Type' => ['application/x-www-form-urlencoded']],
                'body' => ['encoding' => 'utf8', 'content' => 'BusinessUnits%5B%5D=1&name=old', 'truncated' => false],
            ],
            ['status' => 200, 'headers' => [], 'body' => []],
        );

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, force: true, bodyOverrides: [
            'BusinessUnits[]=162345',
            'BusinessUnits[]=2415134',
            'name=new',
        ]);

        $this->assertNotNull($handler->lastRequest);
        parse_str((string)$handler->lastRequest->getBody(), $body);
        $this->assertSame(['BusinessUnits' => ['162345', '2415134'], 'name' => 'new'], $body);
    }

    public function testBodyOverrideMergesOntoAJsonBodyWithScalarCoercion(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(
            [
                'method' => 'POST',
                'uri' => '/orders',
                'headers' => ['Content-Type' => ['application/json']],
                'body' => ['encoding' => 'utf8', 'content' => '{"businessUnitIds":[1],"active":false,"name":"old"}', 'truncated' => false],
            ],
            ['status' => 200, 'headers' => [], 'body' => []],
        );

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, force: true, bodyOverrides: [
            'businessUnitIds[]=162345',
            'businessUnitIds[]=2415134',
            'active=true',
            'name=new',
        ]);

        $this->assertNotNull($handler->lastRequest);
        $decoded = json_decode((string)$handler->lastRequest->getBody(), true);
        $this->assertSame(
            ['businessUnitIds' => [162345, 2415134], 'active' => true, 'name' => 'new'],
            $decoded,
        );
    }

    /**
     * Only the JSON body path validates shape explicitly (see assignJsonOverride()/splitOverride());
     * a form-urlencoded body's merge goes through parse_str(), which is already lenient about a
     * bare key with no "=" (it becomes a key with an empty value) the same way a real query string
     * or form body is.
     */
    public function testBodyOverrideRejectsAnEntryWithNoEqualsForAJsonBody(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(
            [
                'method' => 'POST',
                'uri' => '/orders',
                'headers' => ['Content-Type' => ['application/json']],
                'body' => ['encoding' => 'utf8', 'content' => '{}', 'truncated' => false],
            ],
            ['status' => 200],
        );

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/key=value/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, force: true, bodyOverrides: ['not-a-pair']);
    }

    public function testAsSessionOverridesTheCookieForTheConfiguredSessionManagerCookieName(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context, $handler] = $this->contextWithSessionManager(new Response(200), 'MYSESSID');
        $cassette = $this->cassette(
            ['method' => 'GET', 'uri' => '/orders/1', 'cookies' => ['MYSESSID' => 'the-recorded-cookie-value']],
            ['status' => 200, 'headers' => [], 'body' => []],
        );

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, asSessionId: 'a-real-live-session-id-1234');

        $this->assertNotNull($handler->lastRequest);
        $this->assertSame(
            ['MYSESSID' => 'a-real-live-session-id-1234'],
            $handler->lastRequest->getCookieParams(),
        );
    }

    public function testAsSessionRejectsASessionIdNotShapedLikeOne(): void
    {
        Config::set('replay.allow_live', true, true, false);
        [$context] = $this->contextWithSessionManager(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/not shaped like a session id/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, asSessionId: 'nope');
    }

    public function testAsSessionRefusesWhenTheContextHasNoSessionManager(): void
    {
        // A real, empty Container -- not just an unstubbed getContainer() -- so
        // tryGet(SessionManager::class) genuinely exercises "not bound" rather than relying on
        // whatever PHPUnit's stub generator happens to answer for an unconfigured method chain.
        Config::set('replay.allow_live', true, true, false);
        $handler = new RecordingRequestHandler(new Response(200));
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $context->method('getContainer')->willReturn(new \Quiote\DI\Container());
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/no "session" factory slot/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, asSessionId: 'a-real-live-session-id-1234');
    }

    public function testCsrfValidationIsDisabledDuringDispatchByDefault(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::remove('core.csrf.enabled');
        $handler = new class implements RequestHandlerInterface {
            public ?bool $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = Config::getBool('core.csrf.enabled', true);

                return new Response(200);
            }
        };
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);

        $this->assertFalse($handler->seen);
        // Restored to "never configured" afterward, not left at false.
        $this->assertFalse(Config::has('core.csrf.enabled'));
    }

    public function testCsrfValidationIsRestoredToItsPreviousValueAfterDispatch(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.csrf.enabled', true, true, false);
        [$context] = $this->contextReturning(new Response(200));
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);

        $this->assertTrue(Config::getBool('core.csrf.enabled'));
        Config::remove('core.csrf.enabled');
    }

    public function testEnforceCsrfLeavesTheConfiguredValueUntouchedDuringDispatch(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.csrf.enabled', true, true, false);
        $handler = new class implements RequestHandlerInterface {
            public ?bool $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = Config::getBool('core.csrf.enabled', false);

                return new Response(200);
            }
        };
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live, enforceCsrf: true);

        $this->assertTrue($handler->seen);
        Config::remove('core.csrf.enabled');
    }

    public function testCsrfValidationIsDisabledDuringIsolatedDispatchByDefault(): void
    {
        Config::remove('core.csrf.enabled');
        $handler = new class implements RequestHandlerInterface {
            public ?bool $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = Config::getBool('core.csrf.enabled', true);

                return new Response(200);
            }
        };
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200, 'headers' => [], 'body' => []]);

        (new ReplayEngine())->replay($context, $cassette);

        $this->assertFalse($handler->seen);
        $this->assertFalse(Config::has('core.csrf.enabled'));
    }

    public function testAnExceptionDuringDispatchIsWrappedWithTheCassetteId(): void
    {
        Config::set('replay.allow_live', true, true, false);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('db unavailable');
            }
        };
        $context = $this->createStub(Context::class);
        $context->method('getRequestHandler')->willReturn($handler);
        $cassette = $this->cassette(['method' => 'GET', 'uri' => '/'], ['status' => 200], 'CRX2050');

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/CRX2050/');
        (new ReplayEngine())->replay($context, $cassette, mode: ReplayMode::Live);
    }
}

final class RecordingRequestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $lastRequest = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->response;
    }
}
