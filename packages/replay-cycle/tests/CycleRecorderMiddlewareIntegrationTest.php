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
use Quiote\Database\Adapter\Cycle\CycleDatabase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\DI\Container;
use Quiote\Replay\Adapter\Cycle\CycleEffectSource;
use Quiote\Replay\Adapter\Cycle\ReplayCycleDatabase;
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
 * Cycle/SQLite connection via {@see CycleEffectSource} -- the same
 * `EffectSource` seam any other driver package plugs into.
 *
 * The connection is built once and reused across both requests below (via
 * {@see DatabaseManager::recycleConnections()}, exactly like a worker
 * process would), so `testTwoSequentialRequestsDoNotLeakDbEffectsIntoEachOthersCassette`
 * is the test that would fail if `CycleRecordingLogger` still took a fixed
 * `EffectLedger` at setLogger() time instead of reading {@see ActiveEffectLedger}.
 */
final class CycleRecorderMiddlewareIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Cycle\Database\DatabaseManager::class)) {
            $this->markTestSkipped('cycle/database not installed');
        }
        DatabaseDriverRegistry::reset();
        EffectSourceRegistry::register(new CycleEffectSource());
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

    private function connect(): CycleDatabase
    {
        $db = new ReplayCycleDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['cycle' => $db]);

        $db->initialize($manager, [
            'cycle' => [
                'default' => 'default',
                'databases' => ['default' => ['connection' => 'sqlite']],
                'connections' => ['sqlite' => new \Cycle\Database\Config\SQLiteDriverConfig(
                    connection: new \Cycle\Database\Config\SQLite\MemoryConnectionConfig(),
                )],
            ],
            'schema' => [],
        ]);

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

    public function testARequestThatTouchesCycleProducesPopulatedDbEffects(): void
    {
        $db = $this->connect();
        $cycleDb = $db->getCycleDatabaseManager()->database('default');
        $cycleDb->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handler = new class($cycleDb) implements RequestHandlerInterface {
            public function __construct(private readonly \Cycle\Database\DatabaseInterface $db)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->db->execute("INSERT INTO items (name) VALUES ('widget')");

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('POST', '/widgets'), $handler);

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->meta['effects_instrumented']);
        $this->assertTrue(self::anyFingerprintContains($cassette->effects, 'INSERT INTO items'));
    }

    public function testTwoSequentialRequestsDoNotLeakDbEffectsIntoEachOthersCassette(): void
    {
        $db = $this->connect();
        $cycleDb = $db->getCycleDatabaseManager()->database('default');
        $cycleDb->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handlerFor = static fn(string $name) => new class($cycleDb, $name) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Cycle\Database\DatabaseInterface $db,
                private readonly string $name,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->db->execute("INSERT INTO items (name) VALUES ('{$this->name}')");

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
        $this->assertTrue(self::anyFingerprintContains($firstEffects, "'first'"));
        $this->assertFalse(self::anyFingerprintContains($firstEffects, "'second'"), "first request's cassette must not contain the second request's query");
        $this->assertTrue(self::anyFingerprintContains($secondEffects, "'second'"));
        $this->assertFalse(self::anyFingerprintContains($secondEffects, "'first'"), "second request's cassette must not contain the first request's query");
    }
}
