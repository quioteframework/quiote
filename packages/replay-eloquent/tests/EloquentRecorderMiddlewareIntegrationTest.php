<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Database\Adapter\Eloquent\EloquentDatabase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\DI\Container;
use Quiote\Replay\Adapter\Eloquent\EloquentEffectSource;
use Quiote\Replay\Adapter\Eloquent\ReplayEloquentDatabase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;

/**
 * End to end: a real request through `RecorderMiddleware`, wired to a real
 * Eloquent/SQLite connection via {@see EloquentEffectSource} -- the same
 * `EffectSource` seam any other driver package plugs into.
 *
 * The connection is built once and reused across both requests below (via
 * {@see DatabaseManager::recycleConnections()}, exactly like a worker
 * process would), so `testTwoSequentialRequestsDoNotLeakDbEffectsIntoEachOthersCassette`
 * is the test that would fail if `EloquentQueryRecorder` still took a fixed
 * `EffectLedger` at attach() time instead of reading {@see ActiveEffectLedger}.
 */
final class EloquentRecorderMiddlewareIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped('illuminate/database not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
        DatabaseDriverRegistry::reset();
        EffectSourceRegistry::register(new EloquentEffectSource());
        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', 'always', true, false);
    }

    protected function tearDown(): void
    {
        DatabaseDriverRegistry::reset();
        EffectSourceRegistry::reset();
        ActiveEffectLedger::reset();
        foreach (['replay.enabled', 'replay.record', 'replay.sample_rate', 'replay.trigger_header', 'replay.max_bytes', 'replay.max_effects', 'replay.capture_body', 'replay.capture_session'] as $key) {
            Config::remove($key);
        }
    }

    private function connect(): EloquentDatabase
    {
        $db = new ReplayEloquentDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['eloquent' => $db]);

        $db->initialize($manager, ['driver' => 'sqlite', 'database' => ':memory:']);

        return $db;
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

    private function context(CassetteStoreInterface $store): Context
    {
        $container = new Container();
        $container->set(CassetteStoreInterface::class, $store);
        $container->set(ClockInterface::class, new SystemClock());
        $container->set(RandomnessInterface::class, new SystemRandomness());
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        return $context;
    }

    /** @param list<\Quiote\Replay\Cassette\Effect> $effects */
    private static function anyFingerprintContains(array $effects, string $needle): bool
    {
        foreach ($effects as $effect) {
            if (str_contains($effect->fingerprint, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testARequestThatTouchesEloquentProducesPopulatedDbEffects(): void
    {
        $db = $this->connect();
        $conn = $db->getEloquentConnection();
        $conn->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handler = new class($conn) implements RequestHandlerInterface {
            public function __construct(private readonly \Illuminate\Database\Connection $conn)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->conn->table('items')->insert(['name' => 'widget']);

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('POST', '/widgets'), $handler);

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->meta['effects_instrumented']);
        $this->assertTrue(self::anyFingerprintContains($cassette->effects, 'insert into "items"'));
    }

    public function testTwoSequentialRequestsDoNotLeakDbEffectsIntoEachOthersCassette(): void
    {
        $db = $this->connect();
        $conn = $db->getEloquentConnection();
        $conn->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handlerFor = static fn(string $name) => new class($conn, $name) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Illuminate\Database\Connection $conn,
                private readonly string $name,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->conn->table('items')->insert(['name' => $this->name]);

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('POST', '/one'), $handlerFor('first'));
        // Mirrors DatabaseManager::recycleConnections() at the request boundary in a worker: the
        // same $db instance (and therefore the same wrapped connection) is reused, never rebuilt.
        $db->getDatabaseManager()->recycleConnections();
        $middleware->process(new ServerRequest('POST', '/two'), $handlerFor('second'));

        $this->assertCount(2, $store->put);
        $firstEffects = $store->put[0][1]->effects;
        $secondEffects = $store->put[1][1]->effects;
        $this->assertTrue(self::anyFingerprintContains($firstEffects, 'insert into "items"'));
        $this->assertSame(1, count(array_filter($firstEffects, static fn($e) => $e->kind === \Quiote\Replay\Cassette\EffectKind::Db)), "first request's cassette must contain exactly its own insert");
        $this->assertSame(1, count(array_filter($secondEffects, static fn($e) => $e->kind === \Quiote\Replay\Cassette\EffectKind::Db)), "second request's cassette must contain exactly its own insert");
        $this->assertSame(['first'], $store->put[0][1]->effects[0]->call['bindings']);
        $this->assertSame(['second'], $store->put[1][1]->effects[0]->call['bindings']);
    }
}
