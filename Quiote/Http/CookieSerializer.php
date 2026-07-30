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
            if (!is_array($values)) {
                continue;
            }

            try {
                // Determine expiration timestamp
                $lifetime = $values['lifetime'] ?? 0;
                if (is_string($lifetime)) {
                    $parsed = strtotime($lifetime);
                    $expire = $parsed === false ? 0 : $parsed;
                } elseif (is_int($lifetime) || is_float($lifetime)) {
                    $expire = ($lifetime != 0) ? time() + (int) $lifetime : 0;
                } else {
                    throw new \InvalidArgumentException(sprintf('Cookie "lifetime" must be a string or number, got "%s".', get_debug_type($lifetime)));
                }

                $rawValue = $values['value'] ?? null;
                // Deleted/cleared cookie: expire in the past
                if ($rawValue === false || $rawValue === null || $rawValue === '') {
                    $expire = time() - 3600 * 24;
                }

                // Apply encode callback when value is non-null. When no callback is
                // provided we URL-encode by default so a value cannot inject extra
                // cookie attributes (e.g. "; Domain=evil.com") or control characters.
                // An explicit `encode_callback === false` opts out (value pre-encoded).
                $val = $rawValue;
                if ($val !== null) {
                    $cb = $values['encode_callback'] ?? 'rawurlencode';
                    if ($cb === false) {
                        // Caller asserts the value is already encoded; leave as-is.
                        $val = self::toCookieString($val, 'value');
                    } elseif (is_callable($cb)) {
                        $val = self::toCookieString(call_user_func($cb, $val), 'value');
                    } else {
                        $val = rawurlencode(self::toCookieString($val, 'value'));
                    }
                }

                $path = $values['path'] ?? $basePath;
                $path = is_string($path) ? $path : $basePath;

                if ($val === null) {
                    continue;
                }

                // Build Set-Cookie string
                $cookieStr = $name . '=' . $val;
                if ($expire > 0) {
                    $cookieStr .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $expire)
                        . '; Max-Age=' . max(0, $expire - time());
                }
                $cookieStr .= '; Path=' . ($path !== '' ? $path : '/');
                if (!empty($values['domain'])) {
                    $cookieStr .= '; Domain=' . self::toCookieString($values['domain'], 'domain');
                }
                if (!empty($values['secure'])) {
                    $cookieStr .= '; Secure';
                }
                if (!empty($values['httponly'])) {
                    $cookieStr .= '; HttpOnly';
                }
                if (!empty($values['samesite'])) {
                    $cookieStr .= '; SameSite=' . ucfirst(strtolower(self::toCookieString($values['samesite'], 'samesite')));
                }

                // Avoid duplicate Set-Cookie headers for the same cookie string
                $existing = $response->getHeader('Set-Cookie');
                if (!in_array($cookieStr, $existing, true)) {
                    $response = $response->withAddedHeader('Set-Cookie', $cookieStr);
                }
            } catch (\Throwable) {
                // Ignore per-cookie formatting errors
            }
        }

        return $response;
    }

    /**
     * Coerce a cookie attribute value into a string, rejecting anything that
     * cannot represent a cookie value/attribute unambiguously.
     */
    private static function toCookieString(mixed $value, string $attribute): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf('Cookie attribute "%s" must be stringable, got "%s".', $attribute, get_debug_type($value)));
    }
}
