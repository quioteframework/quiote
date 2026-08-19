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

    public function testTheRecordedClientAddressIsNotReplayedBackIntoTheRequest(): void
    {
        // A cassette carries no integrity marker and can arrive from an object store, a bug report
        // or a colleague. Restoring REMOTE_ADDR wholesale let a crafted cassette present itself as
        // originating from an address of its choosing -- which is what an internal-IP or
        // trusted-proxy check reads.
        $request = RequestReconstructor::fromCassette($this->cassetteWithRequest([
            'method' => 'GET',
            'uri' => 'https://example.test/admin',
            'server' => [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'example.test',
                'SERVER_PORT' => '443',
            ],
        ]));

        $server = $request->getServerParams();
        $this->assertArrayNotHasKey('REMOTE_ADDR', $server);
        // The descriptive params are kept, so a replayed request still looks like the recorded one.
        $this->assertSame('example.test', $server['HTTP_HOST']);
        $this->assertSame('443', $server['SERVER_PORT']);
    }

    public function testAnUnexpectedServerParamIsNotRestored(): void
    {
        $request = RequestReconstructor::fromCassette($this->cassetteWithRequest([
            'method' => 'GET',
            'uri' => 'https://example.test/x',
            'server' => ['HTTP_X_FORWARDED_FOR' => '10.0.0.1', 'SOMETHING_ELSE' => 'x'],
        ]));

        $this->assertSame([], $request->getServerParams());
    }

    public function testATruncatedRequestBodyIsRefusedRatherThanReplayedAsAPrefix(): void
    {
        // Sending a prefix and calling it the request attributes the difference to the application
        // rather than to the recording.
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/truncated request body/');
        RequestReconstructor::fromCassette($this->cassetteWithRequest([
            'method' => 'POST',
            'uri' => 'https://example.test/x',
            'body' => ['encoding' => 'utf8', 'content' => 'partial', 'truncated' => true],
        ]));
    }

    public function testAnUntruncatedBodyIsReplayedNormally(): void
    {
        $request = RequestReconstructor::fromCassette($this->cassetteWithRequest([
            'method' => 'POST',
            'uri' => 'https://example.test/x',
            'body' => ['encoding' => 'utf8', 'content' => 'whole', 'truncated' => false],
        ]));

        $this->assertSame('whole', (string)$request->getBody());
    }

    public function testAnUnusableUriIsReportedAsACassetteProblem(): void
    {
        // PSR-7 validates what the cassette supplies, and an escaping InvalidArgumentException was
        // caught by ReplayCommand's generic handler and reported as a context resolution failure.
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/PSR-7 will not accept/');
        RequestReconstructor::fromCassette($this->cassetteWithRequest([
            'method' => 'GET',
            'uri' => 'http://',
            'headers' => ['Bad Header Name' => ['x']],
        ]));
    }
}
