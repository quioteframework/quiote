<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\ReplayCommand;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Tester\CommandTester;

final class ReplayCommandTest extends TestCase
{
    private string $dir;
    private ?string $originalStore;
    private ?string $originalStorePath;
    private ?bool $originalAllowLive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-replay-cmd-' . bin2hex(random_bytes(6));
        $this->originalStore = Config::getNullableString('replay.store');
        $this->originalStorePath = Config::getNullableString('replay.store.path');
        $this->originalAllowLive = Config::has('replay.allow_live') ? Config::getBool('replay.allow_live') : null;
        Config::set('replay.store', 'file', true, false);
        Config::set('replay.store.path', $this->dir, true, false);
        Config::set('replay.allow_live', false, true, false);
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
        if ($this->originalAllowLive !== null) {
            Config::set('replay.allow_live', $this->originalAllowLive, true, false);
        } else {
            Config::remove('replay.allow_live');
        }
        parent::tearDown();
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
        $this->assertStringContainsString('No cassette found', $tester->getDisplay());
    }

    public function testNonFileStoreConfigurationFails(): void
    {
        Config::set('replay.store', 'azure-blob', true, false);
        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('only supports the "file" store', $tester->getDisplay());
    }

    public function testRefusesWhenAllowLiveIsFalse(): void
    {
        $this->putCassette('AAA', ['method' => 'GET', 'uri' => '/'], ['status' => 200]);

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--context' => 'testing']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('allow_live', $tester->getDisplay());
    }

    public function testRefusesANonIdempotentMethodWithoutForce(): void
    {
        Config::set('replay.allow_live', true, true, false);
        $this->putCassette('AAA', ['method' => 'POST', 'uri' => '/orders'], ['status' => 200]);

        $tester = new CommandTester(new ReplayCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--context' => 'testing']);

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
}
