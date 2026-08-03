<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Quiote\Config\Config;
use Quiote\Http\RequestScheme;
use Quiote\Testing\UnitTestCase;

class RequestSchemeTest extends UnitTestCase
{
    /** @var mixed */
    private $previousTrust;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTrust = Config::has('core.proxy.trust_forwarded_headers')
            ? Config::get('core.proxy.trust_forwarded_headers')
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousTrust === null) {
            Config::remove('core.proxy.trust_forwarded_headers');
        } else {
            Config::set('core.proxy.trust_forwarded_headers', $this->previousTrust);
        }
        parent::tearDown();
    }

    public function testAnHttpsUriIsSecure(): void
    {
        $this->assertTrue(RequestScheme::isHttps(new ServerRequest('GET', 'https://example.com/x')));
    }

    public function testAPlainHttpRequestIsNotSecure(): void
    {
        $this->assertFalse(RequestScheme::isHttps(new ServerRequest('GET', 'http://example.com/x')));
    }

    /**
     * The case the helper exists for: behind a TLS-terminating proxy the
     * connection this process sees is plain HTTP, so anything reading the URI
     * scheme alone concludes "not secure" for a request the browser made over
     * https -- which is how HSTS came to never be emitted in production.
     */
    public function testForwardedProtoIsHonouredBehindATerminatingProxy(): void
    {
        $request = (new ServerRequest('GET', 'http://example.com/x'))->withHeader('X-Forwarded-Proto', 'https');

        $this->assertTrue(RequestScheme::isHttps($request));
    }

    public function testOnlyTheLeftmostForwardedProtoTokenCounts(): void
    {
        // The chain lists the outermost proxy first, so the leftmost token is the
        // scheme the client actually used.
        $secure = (new ServerRequest('GET', 'http://example.com/x'))->withHeader('X-Forwarded-Proto', 'https, http');
        $plain = (new ServerRequest('GET', 'http://example.com/x'))->withHeader('X-Forwarded-Proto', 'http, https');

        $this->assertTrue(RequestScheme::isHttps($secure));
        $this->assertFalse(RequestScheme::isHttps($plain));
    }

    public function testForwardedProtoIsIgnoredWhenProxyHeadersAreNotTrusted(): void
    {
        // The header is client-supplied. An application reachable directly from
        // the internet must be able to stop any caller claiming its plaintext
        // request was secure.
        Config::set('core.proxy.trust_forwarded_headers', false);
        $request = (new ServerRequest('GET', 'http://example.com/x'))->withHeader('X-Forwarded-Proto', 'https');

        $this->assertFalse(RequestScheme::isHttps($request));
    }

    public function testServerHttpsFlagIsHonoured(): void
    {
        foreach (['on', '1', 'https'] as $flag) {
            $request = new ServerRequest('GET', 'http://example.com/x', [], null, '1.1', ['HTTPS' => $flag]);
            $this->assertTrue(RequestScheme::isHttps($request), sprintf('HTTPS=%s', $flag));
        }
    }

    public function testServerHttpsOffAndEmptyAreNotSecure(): void
    {
        // Apache sets HTTPS=off on plain requests, so treating any non-empty
        // value as secure would read that backwards.
        foreach (['off', '', '0'] as $flag) {
            $request = new ServerRequest('GET', 'http://example.com/x', [], null, '1.1', ['HTTPS' => $flag]);
            $this->assertFalse(RequestScheme::isHttps($request), sprintf('HTTPS=%s', var_export($flag, true)));
        }
    }

    public function testRequestSchemeServerParamIsHonoured(): void
    {
        $request = new ServerRequest('GET', 'http://example.com/x', [], null, '1.1', ['REQUEST_SCHEME' => 'https']);

        $this->assertTrue(RequestScheme::isHttps($request));
    }
}
