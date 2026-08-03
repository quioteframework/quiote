<?php

use PHPUnit\Framework\TestCase;
use Quiote\Request\WebRequest;

/**
 * The URL metadata (getUrlHost() and friends) and the wrapped PSR-7 URI are two views of
 * the same value, so every mutator has to leave them agreeing. A disagreement is what lets
 * a host- or scheme-based check pass while the request actually carries something else.
 */
class WebRequestUrlMutationTest extends TestCase
{
    private function request(string $uri = 'https://example.test/path?a=b'): WebRequest
    {
        return new WebRequest('GET', $uri);
    }

    public function testWithUrlHostUpdatesBothViews(): void
    {
        $new = $this->request()->withUrlHost('other.test');

        $this->assertSame('other.test', $new->getUrlHost());
        $this->assertSame('other.test', $new->getUri()->getHost());
    }

    public function testWithUrlHostLeavesTheOriginalUntouched(): void
    {
        $original = $this->request();

        $original->withUrlHost('other.test');

        $this->assertSame('example.test', $original->getUrlHost());
        $this->assertSame('example.test', $original->getUri()->getHost());
    }

    public function testWithUrlSchemeUpdatesBothViews(): void
    {
        $new = $this->request()->withUrlScheme('http');

        $this->assertSame('http', $new->getUrlScheme());
        $this->assertSame('http', $new->getUri()->getScheme());
        $this->assertFalse($new->isHttps());
    }

    public function testWithUrlPortUpdatesBothViews(): void
    {
        $new = $this->request()->withUrlPort(8443);

        $this->assertSame(8443, $new->getUrlPort());
        $this->assertSame(8443, $new->getUri()->getPort());
    }

    /**
     * A scheme-default port is a concrete number in the URL metadata but must stay out of
     * the PSR-7 authority.
     */
    public function testDefaultPortIsOmittedFromThePsrUri(): void
    {
        $new = $this->request()->withUrlPort(443);

        $this->assertSame(443, $new->getUrlPort());
        $this->assertNull($new->getUri()->getPort());
        $this->assertSame('example.test', $new->getUri()->getAuthority());
    }

    public function testWithUrlPathUpdatesBothViewsAndTheRequestUri(): void
    {
        $new = $this->request()->withUrlPath('/elsewhere');

        $this->assertSame('/elsewhere', $new->getUrlPath());
        $this->assertSame('/elsewhere', $new->getUri()->getPath());
        $this->assertSame('/elsewhere?a=b', $new->getRequestUri());
    }

    public function testWithUrlQueryUpdatesBothViewsAndTheRequestUri(): void
    {
        $new = $this->request()->withUrlQuery('c=d');

        $this->assertSame('c=d', $new->getUrlQuery());
        $this->assertSame('c=d', $new->getUri()->getQuery());
        $this->assertSame('/path?c=d', $new->getRequestUri());
    }

    public function testWithUrlQueryAcceptsAnEmptyQuery(): void
    {
        $new = $this->request()->withUrlQuery('');

        $this->assertSame('', $new->getUrlQuery());
        $this->assertSame('', $new->getUri()->getQuery());
        $this->assertSame('/path', $new->getRequestUri());
    }

    public function testWithRequestUriSplitsPathAndQuery(): void
    {
        $new = $this->request()->withRequestUri('/new/path?x=1&y=2');

        $this->assertSame('/new/path?x=1&y=2', $new->getRequestUri());
        $this->assertSame('/new/path', $new->getUrlPath());
        $this->assertSame('x=1&y=2', $new->getUrlQuery());
        $this->assertSame('/new/path', $new->getUri()->getPath());
        $this->assertSame('x=1&y=2', $new->getUri()->getQuery());
    }

    public function testWithRequestUriWithoutAQueryClearsTheQuery(): void
    {
        $new = $this->request()->withRequestUri('/bare');

        $this->assertSame('/bare', $new->getRequestUri());
        $this->assertSame('/bare', $new->getUrlPath());
        $this->assertSame('', $new->getUrlQuery());
        $this->assertSame('', $new->getUri()->getQuery());
    }

    public function testWithProtocolAlignsThePsrProtocolVersion(): void
    {
        $new = $this->request()->withProtocol('HTTP/1.0');

        $this->assertSame('HTTP/1.0', $new->getProtocol());
        $this->assertSame('1.0', $new->getProtocolVersion());
    }

    public function testWithProtocolLeavesThePsrVersionAloneForAMalformedValue(): void
    {
        $original = $this->request();
        $version = $original->getProtocolVersion();

        $new = $original->withProtocol('not-a-protocol');

        $this->assertSame('not-a-protocol', $new->getProtocol());
        $this->assertSame($version, $new->getProtocolVersion());
    }

    public function testWithProtocolAcceptsNull(): void
    {
        $new = $this->request()->withProtocol(null);

        $this->assertNull($new->getProtocol());
    }

    public function testGetUrlReflectsTheMutatedComponents(): void
    {
        $new = $this->request()
            ->withUrlScheme('http')
            ->withUrlHost('other.test')
            ->withUrlPort(8080)
            ->withRequestUri('/x?y=1');

        $this->assertSame('http://other.test:8080/x?y=1', $new->getUrl());
        $this->assertSame('http', $new->getUri()->getScheme());
        $this->assertSame('other.test', $new->getUri()->getHost());
        $this->assertSame(8080, $new->getUri()->getPort());
        $this->assertSame('/x', $new->getUri()->getPath());
    }

    /**
     * The deprecated void setters change the instance in place, but must still leave the
     * two views of the URL agreeing.
     */
    public function testDeprecatedSettersMutateInPlaceAndKeepBothViewsInSync(): void
    {
        $request = $this->request();

        $request->setUrlHost('other.test');
        $request->setUrlScheme('http');
        $request->setUrlPort(8080);
        $request->setUrlPath('/moved');
        $request->setUrlQuery('z=9');

        $this->assertSame('other.test', $request->getUrlHost());
        $this->assertSame('other.test', $request->getUri()->getHost());
        $this->assertSame('http', $request->getUrlScheme());
        $this->assertSame('http', $request->getUri()->getScheme());
        $this->assertSame(8080, $request->getUrlPort());
        $this->assertSame(8080, $request->getUri()->getPort());
        $this->assertSame('/moved', $request->getUrlPath());
        $this->assertSame('/moved', $request->getUri()->getPath());
        $this->assertSame('z=9', $request->getUrlQuery());
        $this->assertSame('z=9', $request->getUri()->getQuery());
        $this->assertSame('/moved?z=9', $request->getRequestUri());
    }

    public function testDeprecatedSetRequestUriKeepsBothViewsInSync(): void
    {
        $request = $this->request();

        $request->setRequestUri('/legacy?q=1');

        $this->assertSame('/legacy?q=1', $request->getRequestUri());
        $this->assertSame('/legacy', $request->getUri()->getPath());
        $this->assertSame('q=1', $request->getUri()->getQuery());
    }

    public function testDeprecatedSetProtocolKeepsBothViewsInSync(): void
    {
        $request = $this->request();

        $request->setProtocol('HTTP/2');

        $this->assertSame('HTTP/2', $request->getProtocol());
        $this->assertSame('2', $request->getProtocolVersion());
    }

    /**
     * Runtime parameters must survive a URL change: the URL mutators rebuild the wrapped
     * request, and dropping the parameter store would silently deny access to already
     * validated parameters.
     */
    public function testUrlMutationPreservesRuntimeParameters(): void
    {
        $request = $this->request()->setParameter('kept', 'value');

        $new = $request->withUrlHost('other.test');

        $this->assertSame('value', $new->getParameter('kept'));
    }
}
