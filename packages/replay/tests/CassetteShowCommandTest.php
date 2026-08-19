<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\ContextRegistry;
use Quiote\Plugin\PluginManager;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Console\CassetteShowCommand;
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
final class CassetteShowCommandTest extends TestCase
{
    private string $dir;
    private ?string $originalStore;
    private ?string $originalStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassette-show-' . bin2hex(random_bytes(6));
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

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $resolved
     * @param list<\Quiote\Replay\Cassette\Effect> $effects
     */
    private function putCassette(string $rawId, array $response, array $resolved = [], array $effects = []): void
    {
        $store = new FileCassetteStore($this->dir);
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId],
            request: [],
            resolved: $resolved,
            session: null,
            user: null,
            effects: $effects,
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

    public function testShowsARequestedSectionAsJson(): void
    {
        $this->putCassette('AAA', ['status' => 500], ['route' => 'orders.update']);

        $tester = new CommandTester(new CassetteShowCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--json' => true, '--section' => 'resolved']);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodedJson($tester);
        $this->assertSame(['resolved' => ['route' => 'orders.update']], $payload);
    }

    public function testUnknownCassetteIdFails(): void
    {
        $tester = new CommandTester(new CassetteShowCommand());
        $exitCode = $tester->execute(['id' => 'does-not-exist']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No cassette found', $tester->getDisplay());
    }

    public function testBodiesAreExcerptedByDefault(): void
    {
        $this->putCassette('AAA', ['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'hello world', 'truncated' => false]]);

        $tester = new CommandTester(new CassetteShowCommand());
        $tester->execute(['id' => 'AAA', '--json' => true, '--section' => 'response']);

        $payload = $this->decodedJson($tester);
        $response = $payload['response'];
        $this->assertIsArray($response);
        $body = $response['body'];
        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('content', $body);
        $this->assertSame(11, $body['length']);
        $this->assertSame(hash('sha256', 'hello world'), $body['sha256']);
    }

    public function testIncludeBodiesFlagReturnsFullContent(): void
    {
        $this->putCassette('AAA', ['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'hello world', 'truncated' => false]]);

        $tester = new CommandTester(new CassetteShowCommand());
        $tester->execute(['id' => 'AAA', '--json' => true, '--section' => 'response', '--include-bodies' => true]);

        $payload = $this->decodedJson($tester);
        $response = $payload['response'];
        $this->assertIsArray($response);
        $body = $response['body'];
        $this->assertIsArray($body);
        $this->assertSame('hello world', $body['content']);
    }

    public function testUnknownSectionFails(): void
    {
        $this->putCassette('AAA', ['status' => 200]);

        $tester = new CommandTester(new CassetteShowCommand());
        $exitCode = $tester->execute(['id' => 'AAA', '--section' => 'not-a-real-section']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown --section', $tester->getDisplay());
    }

    public function testRawFlagReadsAPlainJsonFileBypassingTheStore(): void
    {
        $codec = new CassetteCodec();
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'RAW1'],
            request: [],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
            exception: null,
            log: null,
        );
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-raw-cassette-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $codec->encodeRaw($cassette));

        try {
            $tester = new CommandTester(new CassetteShowCommand());
            $exitCode = $tester->execute(['id' => $path, '--raw' => true, '--json' => true, '--section' => 'meta']);

            $this->assertSame(0, $exitCode);
            $payload = $this->decodedJson($tester);
            $this->assertSame(['meta' => ['id' => 'RAW1']], $payload);
        } finally {
            @unlink($path);
        }
    }

    public function testRawFlagOnAMissingFileFails(): void
    {
        $tester = new CommandTester(new CassetteShowCommand());
        $exitCode = $tester->execute(['id' => '/does/not/exist.json', '--raw' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not read', $tester->getDisplay());
    }

    public function testHumanReadableModeShowsASectionPerHeading(): void
    {
        $this->putCassette('AAA', ['status' => 200]);

        $tester = new CommandTester(new CassetteShowCommand());
        $tester->execute(['id' => 'AAA']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('meta', $display);
        $this->assertStringContainsString('response', $display);
    }
}
