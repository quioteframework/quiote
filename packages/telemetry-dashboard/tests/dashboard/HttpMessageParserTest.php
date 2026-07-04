<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\HttpMessageParser;
use Quiote\Telemetry\Dashboard\MalformedRequestException;

/**
 * Covers the bounded, OTLP/HTTP-only parser the dashboard's receiver uses
 * (see HttpMessageParser's own docblock for why it is deliberately narrow).
 * Robustness against malformed/oversized/partial input is the point of this
 * class, so most of the coverage here is adversarial.
 */
class HttpMessageParserTest extends TestCase
{
    public function testReturnsNullUntilACompleteRequestIsBuffered(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\n");
        $this->assertNull($parser->tryParse());

        $parser->feed("Content-Type: application/x-protobuf\r\nContent-Length: 5\r\n\r\n");
        $this->assertNull($parser->tryParse());

        $parser->feed('abc');
        $this->assertNull($parser->tryParse());

        $parser->feed('de');
        $request = $parser->tryParse();

        $this->assertNotNull($request);
        $this->assertSame('POST', $request->method);
        $this->assertSame('/v1/traces', $request->path);
        $this->assertSame('application/x-protobuf', $request->header('Content-Type'));
        $this->assertSame('abcde', $request->body);
    }

    public function testHandlesTheRequestArrivingInOneChunk(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/metrics HTTP/1.1\r\nContent-Length: 3\r\n\r\nxyz");

        $request = $parser->tryParse();

        $this->assertNotNull($request);
        $this->assertSame('/v1/metrics', $request->path);
        $this->assertSame('xyz', $request->body);
    }

    public function testRequestWithNoBodyAndNoContentLengthParsesWithEmptyBody(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("GET /health HTTP/1.1\r\n\r\n");

        $request = $parser->tryParse();

        $this->assertNotNull($request);
        $this->assertSame('', $request->body);
    }

    public function testPipelinedRequestsAreParsedOneAtATime(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nContent-Length: 2\r\n\r\naa");
        $parser->feed("POST /v1/traces HTTP/1.1\r\nContent-Length: 2\r\n\r\nbb");

        $first = $parser->tryParse();
        $second = $parser->tryParse();
        $third = $parser->tryParse();

        $this->assertSame('aa', $first->body);
        $this->assertSame('bb', $second->body);
        $this->assertNull($third);
    }

    public function testHeaderNamesAreLowerCased(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nX-Custom-Header: Value\r\n\r\n");

        $request = $parser->tryParse();

        $this->assertSame('Value', $request->headers['x-custom-header']);
    }

    public function testMalformedRequestLineThrows(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("NOT A REQUEST LINE\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testUnsupportedMethodThrows(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("DELETE /v1/traces HTTP/1.1\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testMalformedHeaderLineThrows(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nNotAHeader\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testNonNumericContentLengthThrows(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nContent-Length: not-a-number\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testChunkedTransferEncodingIsRejected(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testExcessiveContentLengthThrows(): void
    {
        $parser = new HttpMessageParser();
        $parser->feed("POST /v1/traces HTTP/1.1\r\nContent-Length: 999999999999\r\n\r\n");

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testOversizedHeaderBlockThrowsRatherThanBufferingForever(): void
    {
        $parser = new HttpMessageParser();
        // No terminating \r\n\r\n -- an unbounded/attacker-controlled header
        // stream must not be allowed to grow the buffer indefinitely.
        $parser->feed("POST /v1/traces HTTP/1.1\r\n" . str_repeat('X-Pad: ' . str_repeat('a', 100) . "\r\n", 200));

        $this->expectException(MalformedRequestException::class);
        $parser->tryParse();
    }

    public function testGrosslyOversizedTotalPayloadThrowsOnFeed(): void
    {
        $parser = new HttpMessageParser();

        $this->expectException(MalformedRequestException::class);
        $parser->feed(str_repeat('a', 64 * 1024 * 1024));
    }
}
