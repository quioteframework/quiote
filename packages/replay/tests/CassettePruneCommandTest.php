<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\ContextRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\CassettePruneCommand;
use Quiote\Replay\ReplayPlugin;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See {@see ReplayCommandTest}'s own docblock for why `ReplayPlugin` is
 * registered and `ContextRegistry::shared()->clear()` is called here.
 */
final class CassettePruneCommandTest extends TestCase
{
    private string $dir;
    private ?string $originalStore;
    private ?string $originalStorePath;
    private ?int $originalRetentionDays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-prune-' . bin2hex(random_bytes(6));
        $this->originalStore = Config::getNullableString('replay.store');
        $this->originalStorePath = Config::getNullableString('replay.store.path');
        $this->originalRetentionDays = Config::has('replay.retention_days') ? Config::getInt('replay.retention_days') : null;
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
        if ($this->originalRetentionDays !== null) {
            Config::set('replay.retention_days', $this->originalRetentionDays, true, false);
        } else {
            Config::remove('replay.retention_days');
        }
        PluginManager::reset();
        ContextRegistry::shared()->clear();
        parent::tearDown();
    }

    private function putCassette(string $rawId, ?string $recordedAt): void
    {
        $store = new FileCassetteStore($this->dir);
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: array_filter(['id' => $rawId, 'recorded_at' => $recordedAt], static fn($v) => $v !== null),
            request: [],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
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

    private static function isoAgo(int $seconds): string
    {
        return (new DateTimeImmutable('@' . (time() - $seconds)))->format(DATE_ATOM);
    }

    public function testOlderThanDeletesOnlyOldCassettes(): void
    {
        $this->putCassette('OLD', self::isoAgo(3600));
        $this->putCassette('NEW', self::isoAgo(10));

        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute(['--older-than' => '30m', '--json' => true]);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodedJson($tester);
        $this->assertSame(['OLD'], $payload['deleted']);
        $store = new FileCassetteStore($this->dir);
        $this->assertFalse($store->has(CassetteId::fromRaw('OLD')));
        $this->assertTrue($store->has(CassetteId::fromRaw('NEW')));
    }

    public function testKeepDeletesEverythingBeyondTheNMostRecent(): void
    {
        $this->putCassette('OLDEST', self::isoAgo(300));
        $this->putCassette('MIDDLE', self::isoAgo(200));
        $this->putCassette('NEWEST', self::isoAgo(100));

        $tester = new CommandTester(new CassettePruneCommand());
        $tester->execute(['--keep' => '2', '--json' => true]);

        $store = new FileCassetteStore($this->dir);
        $this->assertFalse($store->has(CassetteId::fromRaw('OLDEST')));
        $this->assertTrue($store->has(CassetteId::fromRaw('MIDDLE')));
        $this->assertTrue($store->has(CassetteId::fromRaw('NEWEST')));
    }

    public function testOlderThanAndKeepCompose(): void
    {
        // --older-than alone would only catch A; --keep=1 alone would only catch A and B (keeping
        // just C). Together, both A and B must go.
        $this->putCassette('A', self::isoAgo(3600));
        $this->putCassette('B', self::isoAgo(20));
        $this->putCassette('C', self::isoAgo(10));

        $tester = new CommandTester(new CassettePruneCommand());
        $tester->execute(['--older-than' => '30m', '--keep' => '1', '--json' => true]);

        $store = new FileCassetteStore($this->dir);
        $this->assertFalse($store->has(CassetteId::fromRaw('A')));
        $this->assertFalse($store->has(CassetteId::fromRaw('B')));
        $this->assertTrue($store->has(CassetteId::fromRaw('C')));
    }

    public function testACassetteWithNoRecordedAtIsNeverMatchedByOlderThan(): void
    {
        $this->putCassette('NO_TIMESTAMP', null);

        $tester = new CommandTester(new CassettePruneCommand());
        $tester->execute(['--older-than' => '1s']);

        $store = new FileCassetteStore($this->dir);
        $this->assertTrue($store->has(CassetteId::fromRaw('NO_TIMESTAMP')));
    }

    public function testACassetteWithNoRecordedAtCanStillBePrunedByKeep(): void
    {
        $this->putCassette('NO_TIMESTAMP', null);
        $this->putCassette('NEWEST', self::isoAgo(10));

        $tester = new CommandTester(new CassettePruneCommand());
        $tester->execute(['--keep' => '1']);

        $store = new FileCassetteStore($this->dir);
        $this->assertTrue($store->has(CassetteId::fromRaw('NEWEST')));
        $this->assertFalse($store->has(CassetteId::fromRaw('NO_TIMESTAMP')));
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $this->putCassette('OLD', self::isoAgo(3600));

        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute(['--older-than' => '30m', '--dry-run' => true, '--json' => true]);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodedJson($tester);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(['OLD'], $payload['deleted']);
        $store = new FileCassetteStore($this->dir);
        $this->assertTrue($store->has(CassetteId::fromRaw('OLD')), 'dry-run must not actually delete anything');
    }

    public function testDefaultsToRetentionDaysWhenNeitherOptionIsGiven(): void
    {
        Config::set('replay.retention_days', 1, true, false);
        $this->putCassette('OLD', self::isoAgo(2 * 86400));
        $this->putCassette('NEW', self::isoAgo(10));

        $tester = new CommandTester(new CassettePruneCommand());
        $tester->execute([]);

        $store = new FileCassetteStore($this->dir);
        $this->assertFalse($store->has(CassetteId::fromRaw('OLD')));
        $this->assertTrue($store->has(CassetteId::fromRaw('NEW')));
    }

    public function testNothingToPruneReportsSuccessWithoutDeleting(): void
    {
        $this->putCassette('NEW', self::isoAgo(10));

        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute(['--older-than' => '30m']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Nothing to prune', $tester->getDisplay());
    }

    public function testInvalidOlderThanFormatFails(): void
    {
        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute(['--older-than' => 'not-a-duration']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not parse --older-than', $tester->getDisplay());
    }

    public function testInvalidKeepValueFails(): void
    {
        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute(['--keep' => 'not-a-number']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--keep must be a non-negative integer', $tester->getDisplay());
    }

    public function testUnregisteredStoreAliasFails(): void
    {
        Config::set('replay.store', 'not-a-real-store', true, false);
        ContextRegistry::shared()->clear();

        $tester = new CommandTester(new CassettePruneCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not resolve the configured cassette store', $tester->getDisplay());
    }
}
