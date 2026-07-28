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
    public function testNoSessionMeansTheResponseIsUntouched(): void
    {
        $response = (new Psr17Factory())->createResponse(200);

        $result = (new NativeSessionCookieBridge())->apply($response);

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

        $result = (new NativeSessionCookieBridge())->apply((new Psr17Factory())->createResponse(200));

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

        $cookie = (new NativeSessionCookieBridge())
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

        $result = (new NativeSessionCookieBridge())->apply($response);

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

        $cookies = (new NativeSessionCookieBridge())->apply($response)->getHeader('Set-Cookie');

        $this->assertCount(2, $cookies);

        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testAClosedSessionDoesNotProduceACookie(): void
    {
        session_name('QSESSID');
        session_id('abc123');
        session_start();
        session_write_close();

        // session_id() still reports the id after close, so PHP_SESSION_ACTIVE is
        // the check that matters -- a finished session has already had its cookie
        // dealt with.
        $result = (new NativeSessionCookieBridge())->apply((new Psr17Factory())->createResponse(200));

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function testDisableNativeEmissionTurnsOffPhpsOwnSetCookie(): void
    {
        ini_set('session.use_cookies', '1');

        (new NativeSessionCookieBridge())->disableNativeEmission();

        // Off-SAPI PHP's own emission goes through a dead header() call, so it has
        // to be switched off or the cookie is simply lost.
        $this->assertSame('0', ini_get('session.use_cookies'));
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

        $cookie = (new NativeSessionCookieBridge())
            ->apply((new Psr17Factory())->createResponse(200))
            ->getHeaderLine('Set-Cookie');

        $this->assertStringStartsWith('QSESSID=abc%2C123', $cookie);

        session_write_close();
    }
}
