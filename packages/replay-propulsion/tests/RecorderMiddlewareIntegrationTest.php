<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Database\Adapter\Propulsion\PropulsionDatabase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\DI\Container;
use Quiote\Replay\Adapter\Propulsion\PropulsionEffectSource;
use Quiote\Replay\Adapter\Propulsion\PropulsionQueryRecorder;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Recording\Redactor;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;

/**
 * End to end: a real request through `RecorderMiddleware`, wired to a real
 * Propulsion/SQLite connection via {@see PropulsionEffectSource} -- the same
 * `EffectSource` seam any other driver package plugs into, exercised here
 * with the one driver this package actually ships.
 */
final class RecorderMiddlewareIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    protected function setUp(): void
    {
        if (!class_exists(Propulsion::class)) {
            $this->markTestSkipped('quioteframework/propulsion not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        DatabaseDriverRegistry::reset();
        Propulsion::close();
        Propulsion::clearQueryObservers();
        (new ReflectionProperty(PropulsionDatabase::class, 'appliedConfiguration'))->setValue(null, null);
        Propulsion::addQueryObserver(new PropulsionQueryRecorder(new Redactor([], [], [])));
        EffectSourceRegistry::register(new PropulsionEffectSource());

        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', 'always', true, false);
    }

    protected function tearDown(): void
    {
        Propulsion::clearQueryObservers();
        Propulsion::close();
        Propulsion::setCorrelationId(null);
        DatabaseDriverRegistry::reset();
        EffectSourceRegistry::reset();
        foreach (['replay.enabled', 'replay.record', 'replay.sample_rate', 'replay.trigger_header', 'replay.max_bytes', 'replay.max_effects', 'replay.capture_body', 'replay.capture_session'] as $key) {
            Config::remove($key);
        }
        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function connect(): \Propulsion\Connection\PropulsionPDO
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['propulsion' => $db]);

        $db->initialize($manager, [
            'config' => $runtimeConfig,
            'datasource' => 'runtime',
        ]);

        return $db->getPropulsionConnection();
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

    public function testARequestThatTouchesPropulsionProducesPopulatedDbEffects(): void
    {
        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handler = new class($conn) implements RequestHandlerInterface {
            public function __construct(private readonly \Propulsion\Connection\PropulsionPDO $conn)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->conn->exec("INSERT INTO items (name) VALUES ('widget')");

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
        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $handlerFor = static fn(string $name) => new class($conn, $name) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Propulsion\Connection\PropulsionPDO $conn,
                private readonly string $name,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->conn->exec("INSERT INTO items (name) VALUES ('{$this->name}')");

                return new Response(200);
            }
        };

        $middleware->process(new ServerRequest('POST', '/one'), $handlerFor('first'));
        $middleware->process(new ServerRequest('POST', '/two'), $handlerFor('second'));

        $this->assertCount(2, $store->put);
        $firstEffects = $store->put[0][1]->effects;
        $secondEffects = $store->put[1][1]->effects;
        $this->assertTrue(self::anyFingerprintContains($firstEffects, "'first'"));
        $this->assertFalse(self::anyFingerprintContains($firstEffects, "'second'"), "first request's cassette must not contain the second request's query");
        $this->assertTrue(self::anyFingerprintContains($secondEffects, "'second'"));
        $this->assertFalse(self::anyFingerprintContains($secondEffects, "'first'"), "second request's cassette must not contain the first request's query");
    }

    private function writeRuntimeConfigFile(): string
    {
        $sqlitePath = $this->newTempFilePath('.sqlite');
        $configPath = $this->newTempFilePath('.php');

        $config = [
            'datasources' => [
                'default' => 'runtime',
                'runtime' => [
                    'adapter' => 'sqlite',
                    'connection' => [
                        'dsn' => 'sqlite:' . $sqlitePath,
                    ],
                ],
            ],
        ];

        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        return $configPath;
    }

    private function newTempFilePath(string $suffix): string
    {
        $path = sprintf('%s/quiote-replay-propulsion-mw-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        $this->filesToDelete[] = $path;

        return $path;
    }
}
