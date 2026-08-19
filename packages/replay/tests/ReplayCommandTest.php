<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\ContextRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\ReplayCommand;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `Quiote\Replay\ReplayPlugin` is not part of the sandbox test app's own
 * plugin list, so `Context::getInstance('web'|'testing')`'s real,
 * process-cached container never has `CassetteStoreInterface` bound unless
 * this test registers the plugin itself -- and, because a `Context` is
 * built once per profile per process, that registration only takes effect
 * on a *fresh* build. `ContextRegistry::shared()->clear()` ("for tests that
 * need a clean process-level slate") forces that in setUp(); tearDown()
 * undoes both so a later, unrelated test that resolves the same context
 * gets the same unplugged rebuild it would have without this file ever
 * having run.
 */
final class ReplayCommandTest extends TestCase
{
    private string $dir;
    private ?string $originalStore;
    private ?string $originalStorePath;
    private ?string $originalLocalPath;
    private ?bool $originalAllowLive;
    private ?string $originalAppDir;
    private ?string $originalTestsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-replay-cmd-' . bin2hex(random_bytes(6));
        $this->originalStore = Config::getNullableString('replay.store');
        $this->originalStorePath = Config::getNullableString('replay.store.path');
        $this->originalLocalPath = Config::getNullableString('replay.local_path');
        $this->originalAllowLive = Config::has('replay.allow_live') ? Config::getBool('replay.allow_live') : null;
        $this->originalAppDir = Config::getNullableString('core.app_dir');
        $this->originalTestsPath = Config::getNullableString('replay.tests_path');
        Config::set('replay.store', 'file', true, false);
        Config::set('replay.store.path', $this->dir, true, false);
        // Scoped to the same disposable temp dir as replay.store.path -- otherwise
        // fetchCassette()'s local-cache check would read/write whatever core.app_dir/var/cassettes
        // happens to resolve to (unset here, or leaked from an earlier test), which is exactly the
        // kind of cross-test state leak that makes a suite flaky.
        Config::set('replay.local_path', $this->dir, true, false);
        Config::set('replay.allow_live', false, true, false);

        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();
        ContextRegistry::shared()->clear();
    }

    protected function tearDown(): void
    {
        self::deleteRecursively($this->dir);
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
        if ($this->originalLocalPath !== null) {
            Config::set('replay.local_path', $this->originalLocalPath, true, false);
        } else {
            Config::remove('replay.local_path');
        }
        if ($this->originalAllowLive !== null) {
            Config::set('replay.allow_live', $this->originalAllowLive, true, false);
        } else {
            Config::remove('replay.allow_live');
        }
        if ($this->originalAppDir !== null) {
            Config::set('core.app_dir', $this->originalAppDir, true, false);
        } else {
            Config::remove('core.app_dir');
        }
        if ($this->originalTestsPath !== null) {
            Config::set('replay.tests_path', $this->originalTestsPath, true, false);
        } else {
            Config::remove('replay.tests_path');
        }
        PluginManager::reset();
        ContextRegistry::shared()->clear();
        parent::tearDown();
    }

    private static function deleteRecursively(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            self::deleteRecursively($child);
        }
        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    private function putCassette(string $rawId, array $request, array $response, ?string $context = null): void
    {
        $store = new FileCassetteStore($this->dir);
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId, 'context' => $context],
            request: $request,
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: $response,
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

    public function testUnknownCassetteIdFails(): void
    {
        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'does-not-exist']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No index could resolve cassette', $tester->getDisplay());
    }

    public function testUnregisteredStoreAliasFails(): void
    {
        Config::set('replay.store', 'not-a-real-store', true, false);
        // The plugin registered in setUp() already bound a working CassetteStoreInterface
        // service; rebuilding forces it to notice replay.store changed underneath it.
        ContextRegistry::shared()->clear();

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not resolve the configured cassette store', $tester->getDisplay());
        $this->assertStringContainsString('not-a-real-store', $tester->getDisplay());
    }

    public function testLiveRefusesWhenAllowLiveIsFalse(): void
    {
        $this->putCassette('AAA', ['method' => 'GET', 'uri' => '/'], ['status' => 200]);

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--context' => 'testing', '--live' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('allow_live', $tester->getDisplay());
    }

    public function testLiveRefusesANonSafeMethodWithoutForce(): void
    {
        Config::set('replay.allow_live', true, true, false);
        $this->putCassette('AAA', ['method' => 'POST', 'uri' => '/orders'], ['status' => 200]);

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--context' => 'testing', '--live' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testANoRecordSkeletonCassetteFailsWithAClearMessage(): void
    {
        Config::set('replay.allow_live', true, true, false);
        $this->putCassette('AAA', ['method' => null, 'uri' => null], ['status' => 200]);

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--context' => 'testing']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('no replayable request', $tester->getDisplay());
    }

    public function testDispatchesForRealAgainstTheTestingContextAndReportsJson(): void
    {
        // Whatever the sandbox app's "testing" context does with a bare GET / is not asserted
        // here -- ReplayEngineTest already covers the diffing logic against mocked handlers.
        // This only proves the command wires id -> cassette -> ReplayEngine -> real Context
        // dispatch -> JSON output correctly, end to end.
        Config::set('replay.allow_live', true, true, false);
        $this->putCassette(
            'AAA',
            ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            ['status' => 999, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => 'deliberately-wrong', 'truncated' => false]],
            'testing',
        );

        $tester = new CommandTester(new ReplayCommand());
        $tester->execute(['id' => 'AAA', '--json' => true]);

        $payload = $this->decodedJson($tester);
        $this->assertArrayHasKey('replayed_status', $payload);
        $this->assertIsInt($payload['replayed_status']);
        $this->assertSame(999, $payload['recorded_status']);
        // The response was deliberately recorded wrong (status 999), so drift must be reported.
        $this->assertFalse($payload['clean']);
        $this->assertNotEmpty($payload['diagnostics']);
    }

    public function testAsTestEmitsACassetteCopyAndAGeneratedTestFile(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.app_dir', $this->dir . '/app', true, false);
        $this->putCassette(
            'AAA',
            ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            ['status' => 999, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            'testing',
        );

        $tester = new CommandTester(new ReplayCommand());
        $tester->execute(['id' => 'AAA', '--as-test' => true]);

        $testFile = $this->dir . '/app/tests/Replay/ReplayAAATest.php';
        $cassetteFile = $this->dir . '/app/tests/Replay/cassettes/AAA.qcast';
        $this->assertFileExists($testFile);
        $this->assertFileExists($cassetteFile);
        $this->assertStringContainsString('Emitted test: ' . $testFile, $tester->getDisplay());
        $this->assertStringContainsString('Emitted cassette: ' . $cassetteFile, $tester->getDisplay());
        $this->assertStringContainsString('final class ReplayAAATest extends ReplayTestCase', (string)file_get_contents($testFile));
    }

    public function testAsTestJsonIncludesTheEmittedPaths(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.app_dir', $this->dir . '/app', true, false);
        $this->putCassette(
            'AAA',
            ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            ['status' => 999, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            'testing',
        );

        $tester = new CommandTester(new ReplayCommand());
        $tester->execute(['id' => 'AAA', '--as-test' => true, '--json' => true]);

        $payload = $this->decodedJson($tester);
        $this->assertArrayHasKey('emitted', $payload);
        $emitted = $payload['emitted'];
        $this->assertIsArray($emitted);
        $this->assertSame($this->dir . '/app/tests/Replay/ReplayAAATest.php', $emitted['test']);
        $this->assertSame($this->dir . '/app/tests/Replay/cassettes/AAA.qcast', $emitted['cassette']);
    }

    public function testWithoutAsTestNothingIsEmittedEvenWithExpectFixed(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.app_dir', $this->dir . '/app', true, false);
        $this->putCassette(
            'AAA',
            ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            ['status' => 999, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            'testing',
        );

        $tester = new CommandTester(new ReplayCommand());
        $tester->execute(['id' => 'AAA', '--expect-fixed' => true]);

        $this->assertDirectoryDoesNotExist($this->dir . '/app/tests');
        $this->assertStringNotContainsString('Emitted test', $tester->getDisplay());
    }

    public function testAsTestExpectFixedEmitsTheIncompleteSkeleton(): void
    {
        Config::set('replay.allow_live', true, true, false);
        Config::set('core.app_dir', $this->dir . '/app', true, false);
        $this->putCassette(
            'AAA',
            ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            ['status' => 500, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            'testing',
        );

        $tester = new CommandTester(new ReplayCommand());
        // Not asserting the exit code: it reflects drift against whatever the sandbox app's real
        // "testing" context actually returns for a bare GET /, which is orthogonal to whether
        // emission itself succeeded.
        $tester->execute(['id' => 'AAA', '--as-test' => true, '--expect-fixed' => true]);

        $testFile = $this->dir . '/app/tests/Replay/ReplayAAATest.php';
        $this->assertFileExists($testFile);
        $this->assertStringContainsString('markTestIncomplete', (string)file_get_contents($testFile));
        $this->assertStringNotContainsString('assertStatus', (string)file_get_contents($testFile));
    }
}
