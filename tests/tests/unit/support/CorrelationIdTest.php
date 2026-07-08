<?php

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\Support\CorrelationId;

/**
 * The correlation-ID resolution/sanitization used by Context::handle() for the
 * inbound X-Correlation-Id header.
 * Adversarial coverage matters: the value is echoed into a response header and
 * log lines, so it is untrusted input.
 */
class CorrelationIdTest extends TestCase
{
    public function testAdoptsInboundHeaderValue(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Correlation-Id', 'abc-123');
        $this->assertSame('abc-123', CorrelationId::fromRequest($request));
    }

    public function testReturnsNullWhenHeaderAbsent(): void
    {
        $this->assertNull(CorrelationId::fromRequest(new ServerRequest('GET', '/')));
    }

    public function testCustomHeaderName(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('Request-Id', 'xyz');
        $this->assertSame('xyz', CorrelationId::fromRequest($request, 'Request-Id'));
        $this->assertNull(CorrelationId::fromRequest($request, 'X-Correlation-Id'));
    }

    public function testStripsControlBytesToPreventHeaderAndLogInjection(): void
    {
        // A CR/LF (and other control bytes) in an adopted value would be a
        // response-header / log-injection vector once echoed back.
        $this->assertSame('evilheader', CorrelationId::sanitize("evil\r\nheader"));
        $this->assertSame('ab', CorrelationId::sanitize("a\x00b"));
    }

    public function testTrimsAndRejectsWhitespaceOnlyValue(): void
    {
        $this->assertSame('id', CorrelationId::sanitize('  id  '));
        $this->assertNull(CorrelationId::sanitize("   \t "));
        $this->assertNull(CorrelationId::sanitize(''));
    }

    public function testCapsLength(): void
    {
        $long = str_repeat('x', 500);
        $result = CorrelationId::sanitize($long);
        $this->assertNotNull($result);
        $this->assertSame(200, mb_strlen($result));
    }

    public function testGenerateProducesDistinctNonEmptyIds(): void
    {
        $a = CorrelationId::generate();
        $b = CorrelationId::generate();
        $this->assertNotSame('', $a);
        $this->assertNotSame($a, $b);
        // URL/log-safe: no +/= from the base64 alphabet.
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $a);
    }
}
