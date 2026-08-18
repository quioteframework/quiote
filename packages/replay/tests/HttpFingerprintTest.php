<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Http\HttpFingerprint;

final class HttpFingerprintTest extends TestCase
{
    public function testMethodIsNormalizedToUppercase(): void
    {
        $upper = HttpFingerprint::of(new Request('GET', 'https://example.test/a'));
        $lower = HttpFingerprint::of(new Request('get', 'https://example.test/a'));

        $this->assertSame($upper, $lower);
    }

    public function testDifferentUrisProduceDifferentFingerprints(): void
    {
        $a = HttpFingerprint::of(new Request('GET', 'https://example.test/a'));
        $b = HttpFingerprint::of(new Request('GET', 'https://example.test/b'));

        $this->assertNotSame($a, $b);
    }

    public function testReadingTheBodyForTheFingerprintDoesNotConsumeItForALaterRead(): void
    {
        $request = new Request('POST', 'https://example.test/a', [], 'the-body');

        HttpFingerprint::of($request);

        $this->assertSame('the-body', (string) $request->getBody());
    }

    public function testCaptureBodyRestoresTheStreamPosition(): void
    {
        $request = new Request('POST', 'https://example.test/a', [], 'content');

        $captured = HttpFingerprint::captureBody($request->getBody());

        $this->assertSame('content', $captured);
        $this->assertSame('content', (string) $request->getBody());
    }
}
