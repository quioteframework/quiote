<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\TextSanitizer;

class TextSanitizerTest extends TestCase
{
    public function testOrdinaryTextPassesThroughUnchanged(): void
    {
        $this->assertSame('GET /orders/{id}', TextSanitizer::sanitize('GET /orders/{id}'));
    }

    public function testStripsEscapeIntroducer(): void
    {
        $malicious = "GET /\x1b[2J\x1b[Hpwned";
        $this->assertSame('GET /[2J[Hpwned', TextSanitizer::sanitize($malicious));
    }

    public function testStripsBellAndDel(): void
    {
        $this->assertSame('abc', TextSanitizer::sanitize("a\x07b\x7fc"));
    }

    public function testPreservesTabAndNewline(): void
    {
        $this->assertSame("a\tb\nc", TextSanitizer::sanitize("a\tb\nc"));
    }

    public function testStripsUtf8EncodedC1Controls(): void
    {
        $this->assertSame('ab', TextSanitizer::sanitize("a\xc2\x9bb"));
    }

    public function testPreservesMultibyteUtf8Content(): void
    {
        $this->assertSame("caf\xc3\xa9 \xf0\x9f\x98\x80", TextSanitizer::sanitize("caf\xc3\xa9 \xf0\x9f\x98\x80"));
    }
}
