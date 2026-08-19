<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Testing\TestEmitter;
use Quiote\Support\CorrelationId;

/**
 * A cassette is untrusted input, and {@see TestEmitter} interpolates several of its
 * free-form strings into PHP comments in the emitted source. These cover every such
 * interpolation against the sequences that end a comment and hand the remainder to the
 * parser as code -- a newline, the block-comment terminator, and the closing tag.
 *
 * The assertion is made against the token stream rather than the raw text, because the
 * property that matters is not whether a payload's *text* survives (inert comment text is
 * harmless and worth keeping legible for whoever reads the generated file) but whether any
 * of it is ever tokenized as something other than a comment, a string literal, or the
 * emitter's own code. A payload that reaches the parser shows up as executable tokens or as
 * inline HTML; one that stays inside a comment cannot.
 */
final class TestEmitterCommentSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $toDelete = [];

    protected function tearDown(): void
    {
        foreach ($this->toDelete as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $meta */
    private static function cassette(array $meta, ?string $exceptionMessage = null, int $status = 200): Cassette
    {
        return new Cassette(
            schemaVersion: 1,
            meta: $meta,
            request: ['method' => 'GET', 'uri' => 'https://example.test/x'],
            resolved: ['module' => 'Demo', 'action' => 'Index'],
            session: null,
            user: null,
            effects: [],
            response: ['status' => $status, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: $exceptionMessage === null ? null : ['class' => 'RuntimeException', 'message' => $exceptionMessage],
            log: null,
        );
    }

    /**
     * Asserts the emitted source parses, never leaves PHP mode, and contains no executable
     * trace of $payloadMarker -- every occurrence must sit inside a comment or a string
     * literal.
     */
    private function assertPayloadStaysInert(string $source, string $payloadMarker, string $label): void
    {
        $path = sys_get_temp_dir() . '/quiote-emitter-' . $label . '-' . getmypid() . '.php';
        $this->toDelete[] = $path;
        file_put_contents($path, $source);
        $lint = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', $lint, "Emitted source did not parse:\n$source\n$lint");

        $inert = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            self::assertNotSame(
                T_INLINE_HTML,
                $token[0],
                "Emitted source left PHP mode -- the closing tag escaped a comment:\n$source",
            );
            if (in_array($token[0], $inert, true)) {
                continue;
            }
            self::assertStringNotContainsString(
                $payloadMarker,
                (string) $token[1],
                sprintf(
                    "Payload reached the parser as a %s token, not as comment or string text:\n%s",
                    token_name($token[0]),
                    $source,
                ),
            );
        }
    }

    public function testRecordedAtCannotCloseTheHeaderDocblock(): void
    {
        $payload = '2026-01-01 */ file_put_contents("/tmp/quiote-pwned", "rce"); /*';
        $source = (new TestEmitter())->emit(self::cassette(['id' => 'aaa', 'recorded_at' => $payload]), CassetteId::fromRaw('aaa'))->phpSource;

        $this->assertPayloadStaysInert($source, 'file_put_contents', 'recorded-at');
    }

    public function testCassetteIdAdoptedFromACorrelationHeaderCannotCloseTheHeaderDocblock(): void
    {
        // Exactly what CorrelationId::sanitize() lets through: control bytes are stripped, the
        // comment-closing sequence is not -- which is why the emitter cannot rely on it.
        $sanitized = CorrelationId::sanitize('ccc */ file_put_contents("/tmp/quiote-pwned", "rce"); /*');
        self::assertIsString($sanitized);
        self::assertStringContainsString('*/', $sanitized, 'Guard: sanitize() is expected to pass the payload through.');

        $source = (new TestEmitter())->emit(self::cassette(['id' => $sanitized, 'recorded_at' => '2026-01-01']), CassetteId::fromRaw($sanitized))->phpSource;

        $this->assertPayloadStaysInert($source, 'file_put_contents', 'correlation-id');
    }

    public function testExceptionMessageNewlineCannotEscapeTheExpectFixedComment(): void
    {
        $payload = "boom\nfile_put_contents('/tmp/quiote-pwned','rce');\n// ";
        $source = (new TestEmitter())->emit(
            self::cassette(['id' => 'bbb', 'recorded_at' => '2026-01-01'], $payload, 500),
            CassetteId::fromRaw('bbb'),
            true,
        )->phpSource;

        // The comment line stays a single line; the full message survives only inside the
        // var_export()ed markTestIncomplete() argument, where it is escaped.
        self::assertMatchesRegularExpression('/^\s*\/\/ Recorded \(buggy\) response: [^\n]*$/m', $source);
        self::assertStringContainsString('markTestIncomplete', $source);
        $this->assertPayloadStaysInert($source, 'file_put_contents', 'exception-message');
    }

    public function testClosingTagInAnExceptionMessageCannotLeavePhpMode(): void
    {
        $source = (new TestEmitter())->emit(
            self::cassette(['id' => 'ddd', 'recorded_at' => '2026-01-01'], 'boom ?> leaked', 500),
            CassetteId::fromRaw('ddd'),
            true,
        )->phpSource;

        // Stripped from the comment, but deliberately kept in the var_export()ed string literal
        // below it -- inside a quoted PHP string the closing tag is ordinary text, and a
        // developer reading markTestIncomplete()'s output wants the message intact.
        self::assertStringContainsString('// Recorded (buggy) response: status 500 (RuntimeException: boom  leaked).', $source);
        self::assertStringContainsString("markTestIncomplete('Fix the recorded bug (status 500 (RuntimeException: boom ?> leaked))", $source);
        $this->assertPayloadStaysInert($source, 'leaked', 'closing-tag');
    }

    public function testEffectCommentsCannotEscapeViaSqlOrFingerprint(): void
    {
        $cassette = new Cassette(
            schemaVersion: 1,
            meta: ['id' => 'eee', 'recorded_at' => '2026-01-01'],
            request: ['method' => 'GET', 'uri' => 'https://example.test/x'],
            resolved: ['module' => 'Demo', 'action' => 'Index'],
            session: null,
            user: null,
            effects: [
                new Effect(0, EffectKind::Db, 'ins', ['sql' => "INSERT INTO t VALUES ('*/ file_put_contents(1); /*')"], 1),
                new Effect(1, EffectKind::Queue, 'push:Job:{"a":"?> file_put_contents(2);"}', ['op' => 'push'], null),
            ],
            response: ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );

        $source = (new TestEmitter())->emit($cassette, CassetteId::fromRaw('eee'))->phpSource;

        $this->assertPayloadStaysInert($source, 'file_put_contents', 'effects');
    }

    public function testAnOrdinaryCassetteKeepsItsReadableCommentText(): void
    {
        $source = (new TestEmitter())->emit(
            self::cassette(['id' => 'plain-id', 'recorded_at' => '2026-08-19T10:11:12+00:00'], 'Order 42 could not be shipped', 500),
            CassetteId::fromRaw('plain-id'),
            true,
        )->phpSource;

        self::assertStringContainsString('Generated from cassette "plain-id", recorded 2026-08-19T10:11:12+00:00.', $source);
        self::assertStringContainsString('// Recorded (buggy) response: status 500 (RuntimeException: Order 42 could not be shipped).', $source);
    }

    public function testAMultiByteCommentValueIsCutOnACharacterBoundary(): void
    {
        // 400 three-byte characters: past every cap, so every comment site truncates. A
        // byte-wise cut would split the last character and leave the file invalid UTF-8.
        $source = (new TestEmitter())->emit(
            self::cassette(['id' => 'fff', 'recorded_at' => str_repeat('€', 400)], str_repeat('€', 400), 500),
            CassetteId::fromRaw('fff'),
            true,
        )->phpSource;

        self::assertTrue(mb_check_encoding($source, 'UTF-8'), 'Emitted source must stay valid UTF-8 after truncation.');
        $this->assertPayloadStaysInert($source, 'nothing-to-find', 'multibyte');
    }
}
