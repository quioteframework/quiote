<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\IsolatesFromLedger;
use Quiote\Replay\Replay\ReplayEngine;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Replay\ReplayMode;
use Quiote\Support\Clock\Clock;
use Quiote\Support\Environment\Environment;

/**
 * Isolated replay: running a cassette against stubs built from its own recorded effects, so nothing
 * the original request did happens again.
 *
 * The `Stubbed*` classes and every swap seam existed before this; what did not was the step that
 * substitutes them for a request's real collaborators, dispatches, and puts everything back. These
 * cover that step, and the two properties that make it worth having: the app really does read the
 * recorded values, and the process really is left as it was found -- including when the dispatch
 * throws, because a stub left installed would make every later request in the process silently
 * replay-shaped.
 */
final class IsolatedReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        EffectSourceRegistry::reset();
        ActiveEffectLedger::reset();
    }

    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        EffectSourceRegistry::reset();
        ActiveEffectLedger::reset();
        CacheManager::reset();
        Clock::useClock(null);
        Environment::useEnvironmentReader(null);
        Config::remove('replay.allow_live');
        parent::tearDown();
    }

    /** @param list<Effect> $effects */
    private function cassette(array $effects, string $recordedAt = '2026-08-18T09:12:44+00:00', int $status = 200, string $body = 'ok'): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'SUX2020', 'recorded_at' => $recordedAt, 'trigger' => 'error'],
            request: ['method' => 'GET', 'uri' => 'https://app.test/orders/42', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            resolved: ['route' => 'orders.show'],
            session: null,
            user: null,
            effects: $effects,
            response: ['status' => $status, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => $body, 'truncated' => false]],
            exception: null,
            log: null,
        );
    }

    /**
     * A context whose pipeline is one middleware, so a test can make the "application" read whatever
     * subsystem it wants to assert about.
     */
    private function contextRunning(callable $handler, ?Container $container = null): Context
    {
        $container ??= new Container();
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);
        $context->method('getName')->willReturn('test');
        $context->method('getRequestHandler')->willReturn(new class($handler) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $handler */
            public function __construct(private $handler)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        });

        return $context;
    }

    public function testTheApplicationReadsTheRecordedCacheValueNotARealCache(): void
    {
        $effects = [new Effect(0, EffectKind::Cache, 'get:orders.42', ['op' => 'get', 'key' => 'orders.42'], ['hit' => true, 'value' => 'recorded-order'])];
        $seen = null;
        $context = $this->contextRunning(function () use (&$seen): ResponseInterface {
            $seen = CacheManager::getCache()->get('orders.42');

            return new Response(200, [], 'ok');
        });

        (new ReplayEngine())->replay($context, $this->cassette($effects));

        $this->assertSame('recorded-order', $seen);
    }

    public function testTheApplicationReadsTheRecordedEnvironmentValue(): void
    {
        $effects = [new Effect(0, EffectKind::Env, 'APP_TIER', ['name' => 'APP_TIER'], 'recorded-tier')];
        $seen = null;
        $context = $this->contextRunning(function () use (&$seen): ResponseInterface {
            $seen = Environment::instance()->get('APP_TIER');

            return new Response(200, [], 'ok');
        });

        (new ReplayEngine())->replay($context, $this->cassette($effects));

        $this->assertSame('recorded-tier', $seen);
    }

    public function testTheClockIsFrozenAtTheRecordedInstant(): void
    {
        // Nothing records individual clock reads, but running at the recorded instant reproduces
        // every now()-dependent branch the original took, which is most of what a recorded clock
        // would have bought.
        $seen = null;
        $context = $this->contextRunning(function () use (&$seen): ResponseInterface {
            $seen = Clock::instance()->now()->format(DATE_ATOM);

            return new Response(200, [], 'ok');
        });

        (new ReplayEngine())->replay($context, $this->cassette([], '2026-08-18T09:12:44+00:00'));

        $this->assertSame('2026-08-18T09:12:44+00:00', $seen);
    }

    public function testAnOutboundHttpCallIsAnsweredFromTheRecordingWithoutASocket(): void
    {
        $effects = [new Effect(
            0,
            EffectKind::Http,
            'GET https://api.example.test/things ' . hash('sha256', ''),
            ['method' => 'GET', 'uri' => 'https://api.example.test/things', 'headers' => []],
            ['status' => 201, 'headers' => ['Content-Type' => ['application/json']], 'body' => '{"recorded":true}'],
        )];

        // The container the context will hand out, so the handler resolves the client exactly as
        // application code does -- through the binding IsolatedReplay substitutes.
        $container = new Container();
        $captured = null;
        $context = $this->contextRunning(function () use ($container, &$captured): ResponseInterface {
            $client = $container->tryGet(ClientInterface::class);
            self::assertInstanceOf(ClientInterface::class, $client);
            $captured = $client->sendRequest(new Request('GET', 'https://api.example.test/things'));

            return new Response(200, [], 'ok');
        }, $container);

        (new ReplayEngine())->replay($context, $this->cassette($effects));

        $this->assertInstanceOf(ResponseInterface::class, $captured);
        $this->assertSame(201, $captured->getStatusCode());
        $this->assertSame('{"recorded":true}', (string) $captured->getBody());
        $this->assertFalse($container->has(ClientInterface::class), 'a binding the app never had is removed again');
    }

    public function testAQueuePushIsCapturedRatherThanEnqueued(): void
    {
        // The safety property: an isolated replay of a request that enqueued a job must not enqueue
        // it again.
        if (!class_exists(\Quiote\Queue\QueueDriverRegistry::class)) {
            $this->markTestSkipped('quioteframework/queue is not installed.');
        }
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $result = (new ReplayEngine())->replay($context, $this->cassette([]));

        $this->assertSame(200, $result->response->getStatusCode());
    }

    public function testEveryStubIsRemovedAfterTheReplay(): void
    {
        $cacheBefore = CacheManager::getCache();
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        (new ReplayEngine())->replay($context, $this->cassette([]));

        $this->assertSame($cacheBefore, CacheManager::getCache(), 'the process cache is restored');
        $this->assertNull(ActiveEffectLedger::get(), 'the ledger is deactivated');
        // Clock and env fall back to their real defaults once the override is cleared.
        $this->assertNotSame('2026-08-18T09:12:44+00:00', Clock::instance()->now()->format(DATE_ATOM));
    }

    public function testEveryStubIsRemovedEvenWhenTheDispatchThrows(): void
    {
        $cacheBefore = CacheManager::getCache();
        $context = $this->contextRunning(static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        try {
            (new ReplayEngine())->replay($context, $this->cassette([]));
            $this->fail('Expected the dispatch failure to surface.');
        } catch (ReplayException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $this->assertSame($cacheBefore, CacheManager::getCache());
        $this->assertNull(ActiveEffectLedger::get(), 'a throwing replay must not leave a ledger active');
    }

    public function testIsolatedIsTheDefaultAndNeedsNoConfiguration(): void
    {
        // replay.allow_live is untouched here on purpose: the safe mode must work out of the box,
        // which it previously did not -- replay refused to run at all without allow_live.
        $this->assertFalse(Config::getBool('replay.allow_live', false));
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $result = (new ReplayEngine())->replay($context, $this->cassette([]));

        $this->assertSame(200, $result->response->getStatusCode());
        $this->assertTrue($result->drift->isClean());
    }

    public function testANonSafeMethodNeedsNoForceInIsolationBecauseNothingIsPerformed(): void
    {
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'SUX2020', 'recorded_at' => '2026-08-18T09:12:44+00:00'],
            request: ['method' => 'DELETE', 'uri' => 'https://app.test/accounts/42', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 204, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(204));

        $result = (new ReplayEngine())->replay($context, $cassette);

        $this->assertSame(204, $result->response->getStatusCode());
    }

    public function testAnEffectTheCodeAsksForButTheCassetteLacksIsReportedAsDrift(): void
    {
        $seen = 'untouched';
        $context = $this->contextRunning(function () use (&$seen): ResponseInterface {
            $seen = CacheManager::getCache()->get('never.recorded', 'default');

            return new Response(200, [], 'ok');
        });

        $result = (new ReplayEngine())->replay($context, $this->cassette([]));

        $this->assertSame('default', $seen, 'PSR-16 requires the default, not a throw');
        $codes = array_map(static fn($d): string => $d->code, $result->drift->diagnostics);
        $this->assertContains('REPLAY_EFFECT_MISS', $codes);
        $this->assertTrue($result->drift->hasErrors(), 'a miss means the code ran on an invented value');
    }

    public function testARecordedEffectNothingAsksForIsReportedAsDrift(): void
    {
        $effects = [new Effect(0, EffectKind::Cache, 'get:orders.42', ['op' => 'get', 'key' => 'orders.42'], ['hit' => true, 'value' => 'x'])];
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $result = (new ReplayEngine())->replay($context, $this->cassette($effects));

        $codes = array_map(static fn($d): string => $d->code, $result->drift->diagnostics);
        $this->assertContains('REPLAY_EFFECT_UNPLAYED', $codes);
    }

    public function testTheLedgerIsReturnedSoACallerCanInterrogateIt(): void
    {
        $effects = [new Effect(0, EffectKind::Cache, 'get:orders.42', ['op' => 'get', 'key' => 'orders.42'], ['hit' => true, 'value' => 'x'])];
        $context = $this->contextRunning(static function (): ResponseInterface {
            CacheManager::getCache()->get('orders.42');

            return new Response(200, [], 'ok');
        });

        $result = (new ReplayEngine())->replay($context, $this->cassette($effects));

        $this->assertNotNull($result->ledger);
        $this->assertSame([], $result->ledger->misses());
        $this->assertSame([], $result->ledger->unplayed());
    }

    public function testALiveReplayReturnsNoLedgerBecauseItHasNone(): void
    {
        Config::set('replay.allow_live', true, true, false);
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $result = (new ReplayEngine())->replay($context, $this->cassette([]), mode: ReplayMode::Live);

        $this->assertNull($result->ledger, 'a live replay\'s effects went to real collaborators');
    }

    public function testIsolationRefusesWhenARegisteredSourceCanOnlyObserve(): void
    {
        // The property that keeps isolated mode honest: an adapter whose recording seam fires after
        // the query has run cannot serve a recorded result, so replaying through it would read from
        // and write to the real database while appearing isolated.
        EffectSourceRegistry::register(new class implements EffectSource {
            public function activate(string $correlationId, EffectLedger $ledger): void
            {
            }

            public function deactivate(string $correlationId): void
            {
            }
        });
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/Cannot replay in isolation/');
        (new ReplayEngine())->replay($context, $this->cassette([]));
    }

    public function testIsolationProceedsWhenEverySourceCanServeFromTheLedger(): void
    {
        $source = new class implements IsolatesFromLedger {
            public ?EffectLedger $isolatedWith = null;
            public bool $ended = false;

            public function activate(string $correlationId, EffectLedger $ledger): void
            {
            }

            public function deactivate(string $correlationId): void
            {
            }

            public function beginIsolation(EffectLedger $ledger): void
            {
                $this->isolatedWith = $ledger;
            }

            public function endIsolation(): void
            {
                $this->ended = true;
            }
        };
        EffectSourceRegistry::register($source);
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200, [], 'ok'));

        $result = (new ReplayEngine())->replay($context, $this->cassette([]));

        $this->assertSame(200, $result->response->getStatusCode());
        // The driver was handed the replaying ledger, and told to stand down again afterwards.
        $this->assertSame($result->ledger, $source->isolatedWith);
        $this->assertTrue($source->ended);
    }

    public function testARefusalHappensBeforeAnythingIsSubstituted(): void
    {
        $cacheBefore = CacheManager::getCache();
        EffectSourceRegistry::register(new class implements EffectSource {
            public function activate(string $correlationId, EffectLedger $ledger): void
            {
            }

            public function deactivate(string $correlationId): void
            {
            }
        });
        $context = $this->contextRunning(static fn(): ResponseInterface => new Response(200));

        try {
            (new ReplayEngine())->replay($context, $this->cassette([]));
        } catch (ReplayException) {
            // expected
        }

        $this->assertSame($cacheBefore, CacheManager::getCache());
        $this->assertNull(ActiveEffectLedger::get());
    }

    public function testARecordingLedgerRefusesToBeAppendedToDuringAReplay(): void
    {
        $ledger = EffectLedger::forReplay([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/replaying ledger/');
        $ledger->record(EffectKind::Db, 'select 1', [], null);
    }
}
