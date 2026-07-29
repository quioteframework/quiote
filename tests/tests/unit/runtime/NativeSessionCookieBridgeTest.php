<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Quiote\Runtime\Session\NativeSessionCookieBridge;

/**
 * Each session test runs in its own process: session_start()/ini_set() are
 * process-global, and a session left active here would leak into the rest of
 * the suite.
 */
final class NativeSessionCookieBridgeTest extends TestCase
{
    /**
     * These tests establish sessions with a bare session_start() rather than
     * through SessionStorage, so the real "was a session started?" signal is
     * never set. Supply it explicitly; testNoSessionMeansNoCookie covers the
     * negative case on its own.
     */
    private static function bridge(bool $sessionWasStarted = true): NativeSessionCookieBridge
    {
        return new NativeSessionCookieBridge(static fn(): bool => $sessionWasStarted);
    }
    public function testNoSessionMeansTheResponseIsUntouched(): void
    {
        $response = (new Psr17Factory())->createResponse(200);

        $result = (self::bridge())->apply($response);

        $this->assertSame($response, $result);
        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function testAnActiveSessionGetsASetCookieBuiltFromTheConfiguredParams(): void
    {
        session_name('QSESSID');
        session_set_cookie_params(3600, '/app', 'example.test', true, true);
        ini_set('session.cookie_samesite', 'Strict');
        session_id('abc123');
        session_start();

        $result = (self::bridge())->apply((new Psr17Factory())->createResponse(200));

        $cookies = $result->getHeader('Set-Cookie');
        $this->assertCount(1, $cookies);
        $cookie = $cookies[0];
        $this->assertStringStartsWith('QSESSID=abc123', $cookie);
        $this->assertStringContainsString('Path=/app', $cookie);
        $this->assertStringContainsString('Domain=example.test', $cookie);
        $this->assertStringContainsString('Max-Age=3600', $cookie);
        $this->assertStringContainsString('Expires=', $cookie);
        $this->assertStringContainsString('Secure', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Strict', $cookie);

        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testASessionCookieLifetimeOfZeroProducesNoExpiry(): void
    {
        session_name('QSESSID');
        session_set_cookie_params(0, '/');
        session_id('abc123');
        session_start();

        $cookie = (self::bridge())
            ->apply((new Psr17Factory())->createResponse(200))
            ->getHeaderLine('Set-Cookie');

        // A session cookie, i.e. one that dies with the browser: no Expires,
        // no Max-Age.
        $this->assertStringNotContainsString('Expires=', $cookie);
        $this->assertStringNotContainsString('Max-Age=', $cookie);

        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testAnExistingCookieForTheSameSessionNameIsNotDuplicated(): void
    {
        session_name('QSESSID');
        session_id('abc123');
        session_start();

        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Set-Cookie', 'QSESSID=set-by-someone-else; Path=/');

        $result = (self::bridge())->apply($response);

        $cookies = $result->getHeader('Set-Cookie');
        $this->assertCount(1, $cookies);
        $this->assertStringContainsString('set-by-someone-else', $cookies[0]);

        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testAnUnrelatedCookieDoesNotSuppressTheSessionCookie(): void
    {
        session_name('QSESSID');
        session_id('abc123');
        session_start();

        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Set-Cookie', 'other=1; Path=/');

        $cookies = (self::bridge())->apply($response)->getHeader('Set-Cookie');

        $this->assertCount(2, $cookies);

        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testAClosedSessionStillGetsItsCookie(): void
    {
        session_name('QSESSID');
        session_id('abc123');
        session_start();
        session_write_close();

        // This is the normal case, not an edge one: the session is written and
        // closed while the response is still being built, and the client needs
        // the cookie regardless. Requiring PHP_SESSION_ACTIVE here meant no
        // cookie was ever emitted for a real request.
        $result = (self::bridge())->apply((new Psr17Factory())->createResponse(200));

        $this->assertStringStartsWith('QSESSID=abc123', $result->getHeaderLine('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function testASessionThatWasNeverStartedProducesNoCookie(): void
    {
        session_name('QSESSID');

        // An empty session id is the one signal that means "no session exists".
        $result = (self::bridge())->apply((new Psr17Factory())->createResponse(200));

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function testDisableNativeEmissionLeavesSessionUseCookiesAlone(): void
    {
        ini_set('session.use_cookies', '1');

        (self::bridge())->disableNativeEmission();

        // Turning it off would make SessionStorage::startup() warn on every
        // request ("Session cookies cannot be used when session.use_cookies is
        // disabled"), and buys nothing: header() is already a no-op under the CLI
        // SAPI, so there is no duplicate cookie to suppress.
        $this->assertSame('1', ini_get('session.use_cookies'));
    }

    #[RunInSeparateProcess]
    public function testASessionIdIsUrlEncodedInTheCookieAsPhpWouldHaveDoneItself(): void
    {
        session_name('QSESSID');
        // PHP allows "," in a session id (session.sid_bits_per_character = 6 emits
        // it), and its own setcookie-based emission percent-encodes the value, so
        // this has to match rather than interpolate the id raw.
        session_id('abc,123');
        session_start();

        $cookie = (self::bridge())
            ->apply((new Psr17Factory())->createResponse(200))
            ->getHeaderLine('Set-Cookie');

        $this->assertStringStartsWith('QSESSID=abc%2C123', $cookie);

        session_write_close();
    }
}
