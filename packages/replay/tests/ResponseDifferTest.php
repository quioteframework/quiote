<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Replay\ResponseDiffer;
use Quiote\Support\Compiler\Diagnostic;

final class ResponseDifferTest extends TestCase
{
    private function differ(): ResponseDiffer
    {
        return new ResponseDiffer();
    }

    /** @param list<Diagnostic> $diagnostics */
    private function findByCode(array $diagnostics, string $code): ?Diagnostic
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return $diagnostic;
            }
        }

        return null;
    }

    public function testIdenticalResponseProducesNoDiagnostics(): void
    {
        $recorded = ['status' => 200, 'headers' => ['Content-Type' => ['text/plain']], 'body' => ['encoding' => 'utf8', 'content' => 'hello', 'truncated' => false]];
        $fresh = new Response(200, ['Content-Type' => 'text/plain'], 'hello');

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertSame([], $diagnostics);
    }

    public function testStatusMismatchIsAnError(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]];
        $fresh = new Response(500);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $diagnostic = $this->findByCode($diagnostics, 'REPLAY_STATUS_MISMATCH');
        $this->assertNotNull($diagnostic);
        $this->assertSame(Diagnostic::SEVERITY_ERROR, $diagnostic->severity);
    }

    public function testMissingRecordedHeaderIsAWarning(): void
    {
        $recorded = ['status' => 200, 'headers' => ['X-Feature' => ['on']], 'body' => []];
        $fresh = new Response(200);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $diagnostic = $this->findByCode($diagnostics, 'REPLAY_HEADER_MISSING');
        $this->assertNotNull($diagnostic);
        $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
    }

    public function testChangedHeaderValueIsAWarning(): void
    {
        $recorded = ['status' => 200, 'headers' => ['X-Feature' => ['on']], 'body' => []];
        $fresh = new Response(200, ['X-Feature' => 'off']);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertNotNull($this->findByCode($diagnostics, 'REPLAY_HEADER_MISMATCH'));
    }

    public function testUnexpectedNewHeaderIsAWarning(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => []];
        $fresh = new Response(200, ['X-New' => 'value']);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertNotNull($this->findByCode($diagnostics, 'REPLAY_HEADER_UNEXPECTED'));
    }

    #[DataProvider('volatileHeaderProvider')]
    public function testVolatileHeadersAreNeverReported(string $headerName): void
    {
        $recorded = ['status' => 200, 'headers' => [$headerName => ['recorded-value']], 'body' => []];
        $fresh = new Response(200, [$headerName => 'fresh-value']);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertSame([], $diagnostics);
    }

    /** @return list<array{0: string}> */
    public static function volatileHeaderProvider(): array
    {
        return [['Date'], ['Set-Cookie'], ['X-Correlation-Id'], ['X-Request-Id'], ['X-Quiote-Rid']];
    }

    public function testBodyMismatchIsAnErrorWithSha256InTheMessage(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => 'expected', 'truncated' => false]];
        $fresh = new Response(200, [], 'actual');

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $diagnostic = $this->findByCode($diagnostics, 'REPLAY_BODY_MISMATCH');
        $this->assertNotNull($diagnostic);
        $this->assertSame(Diagnostic::SEVERITY_ERROR, $diagnostic->severity);
        $this->assertStringContainsString(hash('sha256', 'expected'), $diagnostic->message);
        $this->assertStringContainsString(hash('sha256', 'actual'), $diagnostic->message);
    }

    public function testBase64BodyIsDecodedBeforeComparing(): void
    {
        $binary = "\xFF\xFEbinary";
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'base64', 'content' => base64_encode($binary), 'truncated' => false]];
        $fresh = new Response(200, [], $binary);

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertSame([], $diagnostics);
    }

    public function testTruncatedBodyOnlyComparesTheRecordedPrefix(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => 'hello ', 'truncated' => true]];
        $fresh = new Response(200, [], 'hello world -- much longer than what was recorded');

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $this->assertSame([], $diagnostics);
    }

    public function testTruncatedBodyWithAMismatchedPrefixIsAWarning(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => 'hello ', 'truncated' => true]];
        $fresh = new Response(200, [], 'goodbye world');

        $diagnostics = $this->differ()->diff($recorded, $fresh, 'CASSETTE1');

        $diagnostic = $this->findByCode($diagnostics, 'REPLAY_BODY_MISMATCH');
        $this->assertNotNull($diagnostic);
        $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
    }

    public function testABase64BodyDecodingToZeroIsNotTreatedAsEmpty(): void
    {
        // `?:` swallowed a body that legitimately decodes to "0", reporting a spurious mismatch.
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'base64', 'content' => base64_encode('0'), 'truncated' => false]];

        $diagnostics = (new ResponseDiffer())->diff($recorded, new Response(200, [], '0'), 'cid');

        $this->assertSame([], $diagnostics, 'A matching body must produce no diagnostics.');
    }

    public function testAnUndecodableBase64BodyStillReportsAMismatch(): void
    {
        $recorded = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'base64', 'content' => '!!!not base64!!!', 'truncated' => false]];

        $diagnostics = (new ResponseDiffer())->diff($recorded, new Response(200, [], 'anything'), 'cid');

        $this->assertNotSame([], $diagnostics);
    }
}
