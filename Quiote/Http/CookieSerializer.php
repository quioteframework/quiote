<?php

namespace Quiote\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Bridges cookies queued on an WebResponse into PSR-7 Set-Cookie headers.
 * Previously the ~35-line serialization block was copy-pasted verbatim in both
 * SessionMiddleware and DispatchMiddleware, meaning it ran twice per response.
 * This class centralises the logic; callers add it at exactly one point. */
final class CookieSerializer
{
    /**
     * Append Set-Cookie headers from $globalResp (WebResponse) to $response.
     * @param  object            $globalResp  Quiote web response object (duck-typed).
     * @param  ResponseInterface $response    PSR-7 response to append headers to.
     * @param  string            $basePath    Default path for cookies without explicit path.
     * @return ResponseInterface The (immutably) updated response.
     */
    public static function bridge(object $globalResp, ResponseInterface $response, string $basePath = '/'): ResponseInterface
    {
        if (!method_exists($globalResp, 'getCookies')) {
            return $response;
        }

        try {
            $cookies = $globalResp->getCookies();
        } catch (\Throwable) {
            return $response;
        }

        if (!is_array($cookies) || $cookies === []) {
            return $response;
        }

        foreach ($cookies as $name => $values) {
            try {
                // Determine expiration timestamp
                if (is_string($values['lifetime'])) {
                    $expire = strtotime($values['lifetime']);
                } else {
                    $expire = ($values['lifetime'] != 0) ? time() + (int)$values['lifetime'] : 0;
                }
                // Deleted/cleared cookie: expire in the past
                if ($values['value'] === false || $values['value'] === null || $values['value'] === '') {
                    $expire = time() - 3600 * 24;
                }

                // Apply encode callback when value is non-null. When no callback is
                // provided we URL-encode by default so a value cannot inject extra
                // cookie attributes (e.g. "; Domain=evil.com") or control characters.
                // An explicit `encode_callback === false` opts out (value pre-encoded).
                $val = $values['value'];
                if ($val !== null) {
                    $cb = $values['encode_callback'] ?? 'rawurlencode';
                    if ($cb === false) {
                        // Caller asserts the value is already encoded; leave as-is.
                    } elseif (is_callable($cb)) {
                        $val = call_user_func($cb, $val);
                    } else {
                        $val = rawurlencode((string) $val);
                    }
                }

                $path = $values['path'] ?? $basePath;

                if ($val === null) {
                    continue;
                }

                // Build Set-Cookie string
                $cookieStr = $name . '=' . (string)$val;
                if ($expire > 0) {
                    $cookieStr .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $expire)
                        . '; Max-Age=' . max(0, $expire - time());
                }
                $cookieStr .= '; Path=' . ($path ?: '/');
                if (!empty($values['domain'])) {
                    $cookieStr .= '; Domain=' . $values['domain'];
                }
                if (!empty($values['secure'])) {
                    $cookieStr .= '; Secure';
                }
                if (!empty($values['httponly'])) {
                    $cookieStr .= '; HttpOnly';
                }
                if (!empty($values['samesite'])) {
                    $cookieStr .= '; SameSite=' . ucfirst(strtolower((string)$values['samesite']));
                }

                // Avoid duplicate Set-Cookie headers for the same cookie string
                $existing = method_exists($response, 'getHeader') ? $response->getHeader('Set-Cookie') : [];
                if (!in_array($cookieStr, $existing, true)) {
                    $response = $response->withAddedHeader('Set-Cookie', $cookieStr);
                }
            } catch (\Throwable) {
                // Ignore per-cookie formatting errors
            }
        }

        return $response;
    }
}
