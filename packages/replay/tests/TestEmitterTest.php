<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Testing\TestEmitter;

final class TestEmitterTest extends TestCase
{
    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $resolved
     * @param list<Effect> $effects
     * @param array<string, mixed> $response
     * @param array<string, mixed>|null $exception
     */
    private function cassette(
        array $request = ['method' => 'GET', 'uri' => '/'],
        array $resolved = [],
        array $effects = [],
        array $response = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
        ?array $exception = null,
        ?string $recordedAt = null,
    ): Cassette {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: array_filter(['id' => '01JCXV8N4K', 'recorded_at' => $recordedAt], static fn($v) => $v !== null),
            request: $request,
            resolved: $resolved,
            session: null,
            user: null,
            effects: $effects,
            response: $response,
            exception: $exception,
            log: null,
        );
    }

    private function assertValidPhp(string $source): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote-test-emitter-') . '.php';
        file_put_contents($path, $source);
        try {
            $result = shell_exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)));
            $this->assertIsString($result);
            $this->assertStringContainsString('No syntax errors detected', $result, $source);
        } finally {
            @unlink($path);
        }
    }

    public function testEmitsAStatusAssertionAlways(): void
    {
        $cassette = $this->cassette(response: ['status' => 200, 'headers' => [], 'body' => []]);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('->assertStatus(200)', $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testEmitsAssertJsonEqualsForAJsonBody(): void
    {
        $cassette = $this->cassette(response: [
            'status' => 500,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => ['encoding' => 'utf8', 'content' => json_encode(['error' => 'nope']), 'truncated' => false],
        ]);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString("->assertJsonEquals([\n", $artifact->phpSource);
        $this->assertStringContainsString("'error' => 'nope'", $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testEmitsAssertSeeForAnErrorCassettesExceptionMessage(): void
    {
        $cassette = $this->cassette(
            response: ['status' => 500, 'headers' => [], 'body' => []],
            exception: ['class' => 'ErrorException', 'message' => 'Undefined array key "shipping"'],
        );

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('->assertSee(\'Undefined array key "shipping"\')', $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testEmitsAssertHeaderForARedirect(): void
    {
        $cassette = $this->cassette(response: ['status' => 302, 'headers' => ['Location' => ['/new']], 'body' => []]);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('AAA'));

        $this->assertStringContainsString("->assertHeader('Location', '/new')", $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testDoesNotEmitARedirectHeaderAssertionForA2xxStatus(): void
    {
        $cassette = $this->cassette(response: ['status' => 200, 'headers' => ['Location' => ['/new']], 'body' => []]);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('AAA'));

        $this->assertStringNotContainsString('assertHeader', $artifact->phpSource);
    }

    public function testDoesNotEmitJsonAssertionForANonJsonContentType(): void
    {
        $cassette = $this->cassette(response: [
            'status' => 200,
            'headers' => ['Content-Type' => ['text/html']],
            'body' => ['encoding' => 'utf8', 'content' => '{"looks":"like json but isnt declared as such"}', 'truncated' => false],
        ]);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringNotContainsString('assertJsonEquals', $artifact->phpSource);
    }

    public function testEmitsCommentsForDbWriteAndQueueEffectsWithoutCommentingOutCode(): void
    {
        $effects = [
            new Effect(0, EffectKind::Db, 'sql1', ['sql' => 'INSERT INTO orders (id) VALUES (1)'], 1, 100),
            new Effect(1, EffectKind::Db, 'sql2', ['sql' => 'SELECT * FROM orders'], [], 50),
            new Effect(2, EffectKind::Queue, 'push:SendConfirmation:{}', ['jobClass' => 'SendConfirmation'], true, 50),
        ];
        $cassette = $this->cassette(effects: $effects);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('// Recorded DB write -- assert this if it matters: INSERT INTO orders (id) VALUES (1)', $artifact->phpSource);
        $this->assertStringContainsString('// Recorded enqueued job -- assert this if it matters: push:SendConfirmation:{}', $artifact->phpSource);
        // A SELECT is a read, not a write -- must not be called out as a write to pin.
        $this->assertStringNotContainsString('SELECT * FROM orders', $artifact->phpSource);
        // No commented-out *code* calling a method that doesn't exist -- every non-blank,
        // non-comment line must be free of a dangling "// $this->" style disabled call.
        $this->assertDoesNotMatchRegularExpression('/\/\/\s*\$this->assert/', $artifact->phpSource);
    }

    public function testExpectFixedEmitsMarkTestIncompleteInsteadOfAssertions(): void
    {
        $cassette = $this->cassette(
            response: ['status' => 500, 'headers' => [], 'body' => []],
            exception: ['class' => 'ErrorException', 'message' => 'boom'],
        );

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'), expectFixed: true);

        $this->assertStringContainsString('$this->markTestIncomplete(', $artifact->phpSource);
        $this->assertStringNotContainsString('->assertStatus(', $artifact->phpSource);
        $this->assertStringNotContainsString('->assertSee(', $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testClassNameIsDerivedFromTheCassetteSlugAndIsAValidIdentifier(): void
    {
        $cassette = $this->cassette();

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('final class Replay01JCXV8N4KTest extends ReplayTestCase', $artifact->phpSource);
        $this->assertSame('tests/Replay/Replay01JCXV8N4KTest.php', $artifact->targetHint);
    }

    public function testAHyphenatedRawIdIsSlugifiedIntoAValidClassName(): void
    {
        // CorrelationId::sanitize() passes hyphens through, and a hyphen is not a legal PHP
        // identifier character -- the emitted class name must still be valid PHP.
        $cassette = $this->cassette();

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('order-42-abc'));

        $this->assertMatchesRegularExpression('/^final class Replay[A-Za-z0-9_]+Test extends ReplayTestCase$/m', $artifact->phpSource);
        $this->assertValidPhp($artifact->phpSource);
    }

    public function testMethodNameIsDerivedFromModuleAndAction(): void
    {
        $cassette = $this->cassette(resolved: ['module' => 'Orders', 'action' => 'Update']);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('public function testOrdersUpdateReproducesRecordedResponse(): void', $artifact->phpSource);
    }

    public function testMethodNameFallsBackWhenModuleAndActionAreUnknown(): void
    {
        $cassette = $this->cassette(resolved: []);

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString('public function testReproducesRecordedResponse(): void', $artifact->phpSource);
    }

    public function testReferencesTheCassetteFileRelativeToDir(): void
    {
        $cassette = $this->cassette();

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertStringContainsString("__DIR__ . '/cassettes/01JCXV8N4K.qcast'", $artifact->phpSource);
    }

    public function testGeneratedSourceIsValidPhpForARealisticEndToEndCassette(): void
    {
        $cassette = $this->cassette(
            request: ['method' => 'POST', 'uri' => '/orders/42'],
            resolved: ['module' => 'Orders', 'action' => 'Update'],
            effects: [new Effect(0, EffectKind::Db, 'sql1', ['sql' => 'UPDATE orders SET status = 1'], 1, 100)],
            response: [
                'status' => 500,
                'headers' => ['Content-Type' => ['application/json']],
                'body' => ['encoding' => 'utf8', 'content' => json_encode(['error' => 'Undefined array key "shipping"']), 'truncated' => false],
            ],
            exception: ['class' => 'ErrorException', 'message' => 'Undefined array key "shipping"'],
            recordedAt: '2026-08-18T09:12:44.318Z',
        );

        $artifact = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('01JCXV8N4K'));

        $this->assertValidPhp($artifact->phpSource);
        $this->assertSame($artifact->checksum, hash('sha256', $artifact->phpSource));
    }
}
