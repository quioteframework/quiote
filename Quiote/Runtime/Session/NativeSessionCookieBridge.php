<?php

declare(strict_types=1);

namespace Quiote\Runtime\Session;

use Psr\Http\Message\ResponseInterface;

/**
 * Puts ext/session's Set-Cookie onto the PSR-7 response for runtimes with no
 * SAPI output channel.
 *
 * The legacy Storage subsystem deliberately relies on PHP's built-in session
 * cookie emission (session.use_cookies=1, no manual setcookie(), to avoid
 * duplicate headers). Under the CLI SAPI -- which is what RoadRunner and Swoole
 * run under -- header() is a silent no-op, so that cookie never reaches the
 * client: the session reads fine on a request that already carries the id, but
 * a brand-new session can never be established, and anything depending on one
 * (a login) breaks with no error anywhere.
 *
 * So off-SAPI: disable ext/session's own emission at worker boot, then
 * synthesise the header here from the params SessionStorage::startup() already
 * configured via session_set_cookie_params().
 *
 * Only the native $_SESSION path needs this. SessionManager's own backends
 * write their cookie onto the PSR-7 response already.
 */
final class NativeSessionCookieBridge
{
    /**
     * Stops ext/session emitting a Set-Cookie through the dead SAPI header
     * path, so the one this class appends is the only one. Called once per
     * worker process (including per forked child), not per request.
     */
    public function disableNativeEmission(): void
    {
        if (!function_exists('session_status')) {
            return;
        }
        @ini_set('session.use_cookies', '0');
    }

    /**
     * Appends the session cookie unless there is no session, or the response
     * already carries one for this session name (e.g. SessionManager handled
     * it, or an action set it explicitly).
     */
    public function apply(ResponseInterface $response): ResponseInterface
    {
        if (!function_exists('session_status')) {
            return $response;
        }

        $sid = session_id();
        if ($sid === false || $sid === '') {
            return $response;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $response;
        }

        $name = session_name();
        if ($name === false) {
            return $response;
        }

        if (self::alreadySet($response, $name)) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', self::buildCookie($name, $sid));
    }

    private static function alreadySet(ResponseInterface $response, string $name): bool
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (stripos($cookie, $name . '=') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function buildCookie(string $name, string $sid): string
    {
        $params = session_get_cookie_params();
        $parts = [$name . '=' . urlencode($sid)];

        $lifetime = $params['lifetime'];
        if ($lifetime > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $lifetime);
            $parts[] = 'Max-Age=' . $lifetime;
        }

        $parts[] = 'Path=' . $params['path'];

        if ($params['domain'] !== '') {
            $parts[] = 'Domain=' . $params['domain'];
        }

        if ($params['secure']) {
            $parts[] = 'Secure';
        }
        if ($params['httponly']) {
            $parts[] = 'HttpOnly';
        }

        // Read from ini rather than from the params array: SessionStorage::startup()
        // configures SameSite through ini_get/ini_set (so PHP's own Set-Cookie would
        // have included it) and never passes it to session_set_cookie_params().
        $sameSite = ini_get('session.cookie_samesite');
        if (is_string($sameSite) && $sameSite !== '') {
            $parts[] = 'SameSite=' . $sameSite;
        }

        return implode('; ', $parts);
    }
}
