<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\ContextRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\CassetteFetchCommand;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See {@see ReplayCommandTest}'s own docblock for why `ReplayPlugin` is registered and
 * `ContextRegistry::shared()->clear()` is called here. `replay.local_path` is pinned to its own
 * disposable temp dir, separate from `replay.store.path`, so a "fetched from the store, now
 * cached locally" assertion is testing two genuinely different directories, the way a real
 * `replay.store = azure-blob` deployment would have them.
 */
final class CassetteFetchCommandTest extends TestCase
{
    private string $storeDir;
    private string $localDir;
    private ?string $originalStore;
    private ?string $originalStorePath;
    private ?string $originalLocalPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-fetch-store-' . bin2hex(random_bytes(6));
        $this->localDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-fetch-local-' . bin2hex(random_bytes(6));
        $this->originalStore = Config::getNullableString('replay.store');
        $this->originalStorePath = Config::getNullableString('replay.store.path');
        $this->originalLocalPath = Config::getNullableString('replay.local_path');
        Config::set('replay.store', 'file', true, false);
        Config::set('replay.store.path', $this->storeDir, true, false);
        Config::set('replay.local_path', $this->localDir, true, false);

        PluginManager::add(new ReplayPlugin());
        PluginManager::bootFromConfig();
        ContextRegistry::shared()->clear();
    }

    protected function tearDown(): void
    {
        self::deleteRecursively($this->storeDir);
        self::deleteRecursively($this->localDir);
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

    private function putCassetteIn(string $dir, string $rawId): void
    {
        $store = new FileCassetteStore($dir);
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId],
            request: ['method' => 'GET', 'uri' => '/'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );
        $store->put(CassetteId::fromRaw($rawId), $cassette);
    }

    public function testAlreadyLocalCassetteIsReportedFromTheLocalCache(): void
    {
        $this->putCassetteIn($this->localDir, 'AAA');

        $tester = new CommandTester(new CassetteFetchCommand());
        $exitCode = $tester->execute(['id' => 'AAA']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('the local cache', $tester->getDisplay());
    }

    public function testCassetteFoundInTheConfiguredStoreIsCachedLocally(): void
    {
        $this->putCassetteIn($this->storeDir, 'AAA');
        $this->assertFileDoesNotExist($this->localDir . '/AAA.qcast');

        $tester = new CommandTester(new CassetteFetchCommand());
        $exitCode = $tester->execute(['id' => 'AAA']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('the configured store', $tester->getDisplay());
        $this->assertFileExists($this->localDir . '/AAA.qcast');
    }

    public function testUnknownIdWithNoIndexConfiguredFails(): void
    {
        $tester = new CommandTester(new CassetteFetchCommand());
        $exitCode = $tester->execute(['id' => 'does-not-exist']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No index could resolve cassette', $tester->getDisplay());
    }

    public function testJsonModeReportsSourceAndCachedPath(): void
    {
        $this->putCassetteIn($this->storeDir, 'AAA');

        $tester = new CommandTester(new CassetteFetchCommand());
        $tester->execute(['id' => 'AAA', '--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('AAA', $payload['id']);
        $this->assertIsString($payload['source']);
        $this->assertStringContainsString('the configured store', $payload['source']);
        $this->assertSame($this->localDir . '/AAA.qcast', $payload['cached_path']);
    }

    public function testToOptionOverridesTheLocalCacheDirectory(): void
    {
        $this->putCassetteIn($this->storeDir, 'AAA');
        $override = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-fetch-to-' . bin2hex(random_bytes(6));

        try {
            $tester = new CommandTester(new CassetteFetchCommand());
            $exitCode = $tester->execute(['id' => 'AAA', '--to' => $override]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($override . '/AAA.qcast');
            $this->assertFileDoesNotExist($this->localDir . '/AAA.qcast');
        } finally {
            self::deleteRecursively($override);
        }
    }
}
