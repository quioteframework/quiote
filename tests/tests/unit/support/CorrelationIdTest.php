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

    /**
     * base64url output. `strtr($b64, '+/=', 'ABC')` collided the three
     * non-alphanumeric characters onto genuine A/B/C output, discarding entropy
     * for no reason, and it consumed the padding itself -- leaving the following
     * rtrim($x, '=') with nothing to strip and literal Cs on the end of every id.
     */
    public function testGenerateProducesUnpaddedBase64Url(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $id = CorrelationId::generate();

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id, 'URL- and log-safe alphabet only');
            $this->assertStringNotContainsString('=', $id, 'padding must be stripped, not remapped');
        }
    }

    public function testGeneratedIdsSurviveSanitizationUnchanged(): void
    {
        // A generated id has to be adoptable as an inbound one without being
        // altered, or a correlation id would not correlate across a hop.
        $id = CorrelationId::generate();

        $this->assertSame($id, CorrelationId::sanitize($id));
    }
}
