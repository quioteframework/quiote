<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Testing\ReplayTestEmission;

final class ReplayTestEmissionTest extends TestCase
{
    private string $appDir;
    private ?string $originalAppDir;
    private ?string $originalTestsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-test-emission-' . bin2hex(random_bytes(6));
        $this->originalAppDir = Config::getNullableString('core.app_dir');
        $this->originalTestsPath = Config::getNullableString('replay.tests_path');
        Config::set('core.app_dir', $this->appDir, true, false);
        Config::remove('replay.tests_path');
    }

    protected function tearDown(): void
    {
        self::deleteRecursively($this->appDir);
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

    private function cassette(string $rawId): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId, 'context' => 'testing'],
            request: ['method' => 'GET', 'uri' => '/', 'headers' => [], 'cookies' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false], 'server' => []],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );
    }

    public function testWritesTheCassetteCopyAndTheGeneratedTestUnderTestsPath(): void
    {
        $id = CassetteId::fromRaw('AAA');
        $cassette = $this->cassette('AAA');

        $result = ReplayTestEmission::emit($id, $cassette, expectFixed: false);

        $this->assertSame($this->appDir . '/tests/Replay/ReplayAAATest.php', $result['test']);
        $this->assertSame($this->appDir . '/tests/Replay/cassettes/AAA.qcast', $result['cassette']);
        $this->assertFileExists($result['test']);
        $this->assertFileExists($result['cassette']);
        $this->assertStringContainsString('final class ReplayAAATest extends ReplayTestCase', (string) file_get_contents($result['test']));
    }

    public function testTheCassetteCopyDecodesBackToTheOriginal(): void
    {
        $id = CassetteId::fromRaw('AAA');
        $cassette = $this->cassette('AAA');

        $result = ReplayTestEmission::emit($id, $cassette, expectFixed: false);

        $copy = (new FileCassetteStore(dirname($result['cassette'])))->get($id);
        $this->assertNotNull($copy);
        $this->assertSame('AAA', $copy->meta['id']);
    }

    public function testExpectFixedEmitsTheIncompleteSkeleton(): void
    {
        $id = CassetteId::fromRaw('AAA');
        $cassette = $this->cassette('AAA');

        $result = ReplayTestEmission::emit($id, $cassette, expectFixed: true);

        $this->assertStringContainsString('markTestIncomplete', (string) file_get_contents($result['test']));
    }

    public function testRespectsACustomTestsPathConfig(): void
    {
        Config::set('replay.tests_path', 'tests/CustomReplay', true, false);
        $id = CassetteId::fromRaw('AAA');
        $cassette = $this->cassette('AAA');

        $result = ReplayTestEmission::emit($id, $cassette, expectFixed: false);

        $this->assertSame($this->appDir . '/tests/CustomReplay/ReplayAAATest.php', $result['test']);
    }
}
