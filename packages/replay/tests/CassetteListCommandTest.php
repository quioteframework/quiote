<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\ContextRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\CassetteListCommand;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See {@see ReplayCommandTest}'s own docblock for why `ReplayPlugin` is
 * registered and `ContextRegistry::shared()->clear()` is called here: the
 * command resolves `CassetteStoreInterface` through `core.default_context`'s
 * real, process-cached container, which the sandbox test app's own plugin
 * list does not otherwise put it in.
 */
final class CassetteListCommandTest extends TestCase
{
    private string $dir;
    private ?string $originalStore;
    private ?string $originalStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-list-' . bin2hex(random_bytes(6));
        $this->originalStore = Config::getNullableString('replay.store');
        $this->originalStorePath = Config::getNullableString('replay.store.path');
        Config::set('replay.store', 'file', true, false);
        Config::set('replay.store.path', $this->dir, true, false);

        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();
        ContextRegistry::shared()->clear();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
        if ($this->originalStore !== null) {
            Config::set('replay.store', $this->originalStore, true, false);
        } else {
            Config::remove('replay.store');
        }
        if ($this->originalStorePath !== null) {
            Config::set('replay.store.path', $this->originalStorePath, true, false);
        } else {
            Config::remove('replay.store.path');
        }
        PluginManager::reset();
        ContextRegistry::shared()->clear();
        parent::tearDown();
    }

    private function putCassette(string $rawId, int $status, ?string $route, string $recordedAt): void
    {
        $store = new FileCassetteStore($this->dir);
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId, 'recorded_at' => $recordedAt, 'trigger' => 'error'],
            request: [],
            resolved: ['route' => $route],
            session: null,
            user: null,
            effects: [],
            response: ['status' => $status],
            exception: null,
            log: null,
        );
        $store->put(CassetteId::fromRaw($rawId), $cassette);
    }

    /** @return array<string, mixed> */
    private function decodedJson(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    public function testListsEveryCassetteInJsonMode(): void
    {
        $this->putCassette('AAA', 500, 'orders.update', '2026-01-01T00:00:00+00:00');
        $this->putCassette('BBB', 200, 'orders.index', '2026-01-02T00:00:00+00:00');

        $tester = new CommandTester(new CassetteListCommand());
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodedJson($tester);
        $this->assertIsArray($payload['cassettes']);
        $ids = array_column($payload['cassettes'], 'id');
        $this->assertContains('AAA', $ids);
        $this->assertContains('BBB', $ids);
    }

    public function testStatusFilterAcceptsAStatusClass(): void
    {
        $this->putCassette('AAA', 500, null, '2026-01-01T00:00:00+00:00');
        $this->putCassette('BBB', 200, null, '2026-01-02T00:00:00+00:00');

        $tester = new CommandTester(new CassetteListCommand());
        $tester->execute(['--json' => true, '--status' => '5xx']);

        $payload = $this->decodedJson($tester);
        $this->assertIsArray($payload['cassettes']);
        $this->assertSame(['AAA'], array_column($payload['cassettes'], 'id'));
    }

    public function testStatusFilterAcceptsAnExactStatus(): void
    {
        $this->putCassette('AAA', 404, null, '2026-01-01T00:00:00+00:00');
        $this->putCassette('BBB', 500, null, '2026-01-02T00:00:00+00:00');

        $tester = new CommandTester(new CassetteListCommand());
        $tester->execute(['--json' => true, '--status' => '404']);

        $payload = $this->decodedJson($tester);
        $this->assertIsArray($payload['cassettes']);
        $this->assertSame(['AAA'], array_column($payload['cassettes'], 'id'));
    }

    public function testRouteFilterExcludesOtherRoutes(): void
    {
        $this->putCassette('AAA', 200, 'orders.update', '2026-01-01T00:00:00+00:00');
        $this->putCassette('BBB', 200, 'orders.index', '2026-01-02T00:00:00+00:00');

        $tester = new CommandTester(new CassetteListCommand());
        $tester->execute(['--json' => true, '--route' => 'orders.update']);

        $payload = $this->decodedJson($tester);
        $this->assertIsArray($payload['cassettes']);
        $this->assertSame(['AAA'], array_column($payload['cassettes'], 'id'));
    }

    public function testSinceFilterExcludesOlderCassettes(): void
    {
        $this->putCassette('AAA', 200, null, '2026-01-01T00:00:00+00:00');
        $this->putCassette('BBB', 200, null, '2026-06-01T00:00:00+00:00');

        $tester = new CommandTester(new CassetteListCommand());
        $tester->execute(['--json' => true, '--since' => '2026-03-01T00:00:00+00:00']);

        $payload = $this->decodedJson($tester);
        $this->assertIsArray($payload['cassettes']);
        $this->assertSame(['BBB'], array_column($payload['cassettes'], 'id'));
    }

    public function testEmptyStoreReportsNoCassettesInTableMode(): void
    {
        $tester = new CommandTester(new CassetteListCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No cassettes found', $tester->getDisplay());
    }

    public function testUnregisteredStoreAliasFailsWithAClearError(): void
    {
        Config::set('replay.store', 'not-a-real-store', true, false);
        // The plugin registered in setUp() already bound a working CassetteStoreInterface
        // service; rebuilding forces it to notice replay.store changed underneath it.
        ContextRegistry::shared()->clear();

        $tester = new CommandTester(new CassetteListCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not resolve the configured cassette store', $tester->getDisplay());
        $this->assertStringContainsString('not-a-real-store', $tester->getDisplay());
    }
}
