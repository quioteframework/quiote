<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Request\WebRequest;
use Quiote\Runtime\Request\WorkerRequestFactory;

/**
 * The reverse-proxy correction used to be a private Kernel method that mutated
 * $_SERVER as a side effect, so none of this had test coverage. Both halves of
 * the result are asserted -- the PSR-7 URI and the CGI server params -- because
 * different parts of the framework read from each.
 */
final class WorkerRequestFactoryTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(array $headers, string $uri = 'http://app.internal:8080/thing?a=1'): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri, [
            'HTTP_HOST' => 'app.internal:8080',
            'SERVER_NAME' => 'app.internal',
            'SERVER_PORT' => '8080',
            'REQUEST_SCHEME' => 'http',
            'SCRIPT_NAME' => '/index.php',
        ]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function testWithoutProxyHeadersTheRequestPassesThroughUnchanged(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([]));

        $this->assertInstanceOf(WebRequest::class, $result);
        $this->assertSame('http', $result->getUri()->getScheme());
        $this->assertSame('app.internal', $result->getUri()->getHost());
        $this->assertSame(8080, $result->getUri()->getPort());
        $this->assertSame('http', $result->getServerParams()['REQUEST_SCHEME']);
    }

    public function testTerminatedTlsIsReflectedInBothTheUriAndTheServerParams(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example',
        ]));

        $this->assertSame('https', $result->getUri()->getScheme());
        $this->assertSame('public.example', $result->getUri()->getHost());

        $params = $result->getServerParams();
        $this->assertSame('https', $params['REQUEST_SCHEME']);
        $this->assertSame('on', $params['HTTPS']);
        $this->assertSame('public.example', $params['HTTP_HOST']);
        $this->assertSame('public.example', $params['SERVER_NAME']);
    }

    public function testTheHostHeaderFollowsTheCorrectedAuthority(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'X-Forwarded-Host' => 'public.example',
        ]));

        // preserveHost: false -- otherwise the app would generate URLs pointing
        // at the address the proxy connected to us on.
        $this->assertSame('public.example', $result->getHeaderLine('Host'));
    }

    public function testADefaultPortIsNotWrittenIntoTheAuthority(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example',
            'X-Forwarded-Port' => '443',
        ]));

        $params = $result->getServerParams();
        $this->assertSame('public.example', $params['HTTP_HOST']);
        $this->assertSame('443', $params['SERVER_PORT']);
    }

    public function testANonDefaultPortIsWrittenIntoTheAuthority(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example',
            'X-Forwarded-Port' => '8443',
        ]));

        $params = $result->getServerParams();
        $this->assertSame('public.example:8443', $params['HTTP_HOST']);
        $this->assertSame('8443', $params['SERVER_PORT']);
        $this->assertSame(8443, $result->getUri()->getPort());
    }

    public function testAProtoOnlyHeaderDoesNotInventAPort(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'X-Forwarded-Proto' => 'https',
        ]));

        // The original connection's port must not be pinned into the corrected
        // authority just because the scheme changed.
        $this->assertSame('8080', $result->getServerParams()['SERVER_PORT']);
        $this->assertSame('https', $result->getUri()->getScheme());
    }

    public function testTheRfc7239ForwardedHeaderIsHonoured(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr(self::request([
            'Forwarded' => 'proto=https; host="public.example:8443"',
        ]));

        $this->assertSame('https', $result->getUri()->getScheme());
        $this->assertSame('public.example', $result->getUri()->getHost());
        $this->assertSame('public.example:8443', $result->getServerParams()['HTTP_HOST']);
    }

    public function testDisablingTrustLeavesProxyHeadersUnapplied(): void
    {
        $result = (new WorkerRequestFactory(trustForwardedHeaders: false))->fromPsr(self::request([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example',
        ]));

        $this->assertSame('http', $result->getUri()->getScheme());
        $this->assertSame('app.internal', $result->getUri()->getHost());
        $this->assertSame('app.internal:8080', $result->getServerParams()['HTTP_HOST']);
    }

    public function testEverythingElseAboutTheRequestSurvivesTheRewrite(): void
    {
        $psr17 = new Psr17Factory();
        $request = self::request(['X-Forwarded-Proto' => 'https'])
            ->withMethod('POST')
            ->withBody($psr17->createStream('{"k":"v"}'))
            ->withHeader('Content-Type', 'application/json')
            ->withQueryParams(['a' => '1'])
            ->withCookieParams(['sid' => 'abc'])
            ->withParsedBody(['k' => 'v'])
            ->withAttribute('marker', 'kept');

        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr($request);

        $this->assertSame('POST', $result->getMethod());
        $this->assertSame('{"k":"v"}', (string) $result->getBody());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame(['a' => '1'], $result->getQueryParams());
        $this->assertSame(['sid' => 'abc'], $result->getCookieParams());
        $this->assertSame(['k' => 'v'], $result->getParsedBody());
        $this->assertSame('kept', $result->getAttribute('marker'));
        $this->assertSame('/thing', $result->getUri()->getPath());
    }

    public function testAWebRequestInIsStillAWebRequestOutWhenNothingNeedsCorrecting(): void
    {
        $original = new WebRequest('GET', 'http://app.internal/thing');

        $result = (new WorkerRequestFactory(trustForwardedHeaders: true))->fromPsr($original);

        // No proxy headers, so WebRequest::fromPsr() short-circuits to the same
        // instance rather than paying for a copy on every request.
        $this->assertSame($original, $result);
    }
}
