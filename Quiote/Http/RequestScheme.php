<?php

declare(strict_types=1);

namespace Quiote\Http;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Config\Config;

/**
 * Whether a PSR-7 request reached the client over TLS.
 *
 * The URI's own scheme is not enough on its own. Behind a TLS-terminating
 * proxy -- the ordinary production shape -- the connection this process sees is
 * plain HTTP, so `$request->getUri()->getScheme()` answers `http` for a request
 * the browser made over `https`. Anything deciding on "is this connection
 * secure" from that alone silently does the wrong thing in exactly the
 * deployment where it matters: {@see \Quiote\Security\Headers\SecurityHeadersMiddleware}
 * never emitted HSTS, and a `Secure` cookie attribute would be dropped.
 *
 * The forwarded header is consulted last and only when
 * `core.proxy.trust_forwarded_headers` is on (the default, matching
 * {@see \Quiote\Runtime\Proxy\ForwardedHeaderResolver}). It is client-supplied,
 * so an application reachable directly from the internet should turn that
 * setting off -- otherwise any caller can claim its plaintext request was
 * secure.
 * @since      3.0.4
 */
final class RequestScheme
{
    private function __construct()
    {
    }

    public static function isHttps(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $server = $request->getServerParams();

        $https = $server['HTTPS'] ?? null;
        if ($https === true) {
            return true;
        }
        if (is_scalar($https)) {
            $flag = strtolower((string) $https);
            if ($flag !== '' && $flag !== 'off' && $flag !== '0') {
                return true;
            }
        }

        $requestScheme = $server['REQUEST_SCHEME'] ?? null;
        if (is_scalar($requestScheme) && strtolower((string) $requestScheme) === 'https') {
            return true;
        }

        if (!Config::getBool('core.proxy.trust_forwarded_headers', true)) {
            return false;
        }

        // A comma-joined chain lists the outermost proxy first, so the leftmost
        // token is the scheme the client actually used.
        $forwarded = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        if ($forwarded === '') {
            return false;
        }
        $first = trim(explode(',', $forwarded)[0]);

        return $first === 'https';
    }
}
