<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Runtime\Proxy\ForwardedHeaderResolver;

final class ForwardedHeaderResolverTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(array $headers): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://app.internal/thing');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function testNoProxyHeadersYieldsAnEmptyAuthority(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([]));

        $this->assertTrue($authority->isEmpty());
        $this->assertNull($authority->scheme);
        $this->assertNull($authority->host);
        $this->assertNull($authority->port);
        $this->assertFalse($authority->portExplicit);
    }

    public function testReadsTheXForwardedTrio(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example',
            'X-Forwarded-Port' => '8443',
        ]));

        $this->assertFalse($authority->isEmpty());
        $this->assertSame('https', $authority->scheme);
        $this->assertSame('public.example', $authority->host);
        $this->assertSame(8443, $authority->port);
        $this->assertTrue($authority->portExplicit);
    }

    public function testXOriginalHostWinsOverXForwardedHost(): void
    {
        // A proxy that rewrites Host puts the client's original value in
        // X-Original-Host, so it has to be the more specific signal.
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Original-Host' => 'original.example',
            'X-Forwarded-Host' => 'rewritten.example',
        ]));

        $this->assertSame('original.example', $authority->host);
    }

    public function testAPortInTheHostHeaderIsUsedAndMarkedExplicit(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Host' => 'public.example:9000',
        ]));

        $this->assertSame('public.example', $authority->host);
        $this->assertSame(9000, $authority->port);
        $this->assertTrue($authority->portExplicit);
    }

    public function testOnlyTheFirstHopOfAChainedHeaderIsUsed(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Proto' => 'https, http',
            'X-Forwarded-Host' => 'first.example, second.example',
        ]));

        $this->assertSame('https', $authority->scheme);
        $this->assertSame('first.example', $authority->host);
    }

    public function testSchemeIsLowercased(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Proto' => 'HTTPS',
        ]));

        $this->assertSame('https', $authority->scheme);
    }

    public function testFallsBackToTheRfc7239ForwardedHeader(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'Forwarded' => 'for=203.0.113.7; proto=https; host="public.example:8443"',
        ]));

        $this->assertSame('https', $authority->scheme);
        $this->assertSame('public.example', $authority->host);
        $this->assertSame(8443, $authority->port);
    }

    public function testAnExplicitHeaderTakesPrecedenceOverForwarded(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Proto' => 'https',
            'Forwarded' => 'proto=http; host=from-forwarded.example',
        ]));

        $this->assertSame('https', $authority->scheme);
        // host had no X-* header, so it still comes from Forwarded.
        $this->assertSame('from-forwarded.example', $authority->host);
    }

    public function testMalformedForwardedValuesAreIgnoredRatherThanFatal(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'Forwarded' => 'garbage;;;=;host',
        ]));

        $this->assertTrue($authority->isEmpty());
    }

    public function testANonNumericPortIsRejected(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Port' => 'not-a-port',
        ]));

        $this->assertNull($authority->port);
        $this->assertFalse($authority->portExplicit);
    }

    public function testBareIpv6HostsAreBracketedForUseInAnAuthority(): void
    {
        $this->assertSame('[2001:db8::1]', ForwardedHeaderResolver::formatAuthorityHost('2001:db8::1'));
        $this->assertSame('[2001:db8::1]', ForwardedHeaderResolver::formatAuthorityHost('[2001:db8::1]'));
        $this->assertSame('public.example', ForwardedHeaderResolver::formatAuthorityHost('public.example'));
    }

    public function testDefaultPortsAreRecognisedPerScheme(): void
    {
        $this->assertFalse(ForwardedHeaderResolver::isPortNonDefault('http', 80));
        $this->assertFalse(ForwardedHeaderResolver::isPortNonDefault('https', 443));
        $this->assertTrue(ForwardedHeaderResolver::isPortNonDefault('http', 443));
        $this->assertTrue(ForwardedHeaderResolver::isPortNonDefault('https', 80));
        $this->assertTrue(ForwardedHeaderResolver::isPortNonDefault(null, 80));
    }

    public function testAnIpv6HostKeepsItsBracketsAndItsPortIsStillSplitOff(): void
    {
        $authority = (new ForwardedHeaderResolver())->resolve(self::request([
            'X-Forwarded-Host' => '[2001:db8::1]:8443',
        ]));

        // parse_url() hands back the bracketed form for an IPv6 literal, which is
        // also the form a URI authority needs, so it is kept as-is;
        // formatAuthorityHost() is what makes an *unbracketed* one safe.
        $this->assertSame('[2001:db8::1]', $authority->host);
        $this->assertSame(8443, $authority->port);
    }
}
