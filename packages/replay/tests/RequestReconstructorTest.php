<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Replay\RequestReconstructor;
use Quiote\Request\WebRequest;

final class RequestReconstructorTest extends TestCase
{
    /** @param array<string, mixed> $request */
    private function cassetteWithRequest(array $request): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: [],
            request: $request,
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: [],
            exception: null,
            log: null,
        );
    }

    public function testRebuildsMethodUriHeadersAndUtf8Body(): void
    {
        $cassette = $this->cassetteWithRequest([
            'method' => 'POST',
            'uri' => 'http://localhost/widgets/42',
            'protocol' => '1.1',
            'headers' => ['Content-Type' => ['application/json']],
            'cookies' => ['session' => 'abc123'],
            'body' => ['encoding' => 'utf8', 'content' => '{"a":1}', 'truncated' => false],
            'server' => [],
        ]);

        $request = RequestReconstructor::fromCassette($cassette);

        $this->assertInstanceOf(WebRequest::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/widgets/42', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['session' => 'abc123'], $request->getCookieParams());
        $this->assertSame('{"a":1}', (string)$request->getBody());
    }

    public function testDecodesABase64Body(): void
    {
        $binary = "\xFF\xFE\x00binary";
        $cassette = $this->cassetteWithRequest([
            'method' => 'POST',
            'uri' => '/upload',
            'body' => ['encoding' => 'base64', 'content' => base64_encode($binary), 'truncated' => false],
        ]);

        $request = RequestReconstructor::fromCassette($cassette);

        $this->assertSame($binary, (string)$request->getBody());
    }

    public function testMissingMethodThrowsAClearException(): void
    {
        $cassette = $this->cassetteWithRequest(['uri' => '/pay']);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/no replayable request/');
        RequestReconstructor::fromCassette($cassette);
    }

    public function testANoRecordSkeletonRequestThrows(): void
    {
        // Matches what RecorderMiddleware actually stores for a #[NoRecord] action: method/uri
        // only, no body/headers -- but with no method it's still unreplayable.
        $cassette = $this->cassetteWithRequest([]);

        $this->expectException(ReplayException::class);
        RequestReconstructor::fromCassette($cassette);
    }

    public function testEmptyBodyRoundTripsAsAnEmptyString(): void
    {
        $cassette = $this->cassetteWithRequest([
            'method' => 'GET',
            'uri' => '/',
            'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
        ]);

        $request = RequestReconstructor::fromCassette($cassette);

        $this->assertSame('', (string)$request->getBody());
    }
}
