<?php

namespace Agavi\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Bridges cookies queued on an AgaviWebResponse into PSR-7 Set-Cookie headers.
 *
 * Previously the ~35-line serialization block was copy-pasted verbatim in both
 * SessionMiddleware and DispatchMiddleware, meaning it ran twice per response.
 * This class centralises the logic; callers add it at exactly one point.
 *
 * @package    agavi
 * @subpackage http
 */
final class CookieSerializer
{
    /**
     * Append Set-Cookie headers from $globalResp (AgaviWebResponse) to $response.
     *
     * @param  object            $globalResp  Agavi web response object (duck-typed).
     * @param  ResponseInterface $response    PSR-7 response to append headers to.
     * @param  string            $basePath    Default path for cookies without explicit path.
     *
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

                // Apply encode callback when value is non-null
                $val = $values['value'];
                if ($val !== null
                    && !empty($values['encode_callback'])
                    && $values['encode_callback'] !== false
                    && is_callable($values['encode_callback'])
                ) {
                    $val = call_user_func($values['encode_callback'], $val);
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
