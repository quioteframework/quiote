<?php

namespace Quiote\Response;

use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * Turns the cookie definitions queued on a response into `Set-Cookie` header lines.
 *
 * Pure translation: given a cookie's declared attributes it produces the normalized form and
 * the header string, and nothing else. It reaches for no context, no routing and no response
 * state -- the default path a cookie inherits when it declares none is passed in, so the
 * same definition always serializes the same way and can be asserted on directly.
 *
 * @since      3.2.0
 */
final class CookieSerializer
{
    /**
     * @param      string $defaultPath Path for a cookie that declares none. An empty string
     *             is treated as "/", so a cookie is never scoped to the empty path.
     */
    public function __construct(
        private readonly string $defaultPath = '/',
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Header lines for every queued cookie, in queue order.
     *
     * @param      array<string, array<array-key, mixed>> $cookies Name => definition.
     * @return     list<string>
     */
    public function headers(array $cookies): array
    {
        $lines = [];
        foreach ($cookies as $name => $cookie) {
            $lines[] = $this->header($name, $this->normalize($name, $cookie));
        }

        return $lines;
    }

    /**
     * Resolve a cookie definition into the concrete attribute set to be sent.
     *
     * An empty, null or false value means deletion, expressed as an empty value with an
     * expiry in the past and `Max-Age=0`. A lifetime may be a second count or any
     * strtotime()-parseable string; an unparseable one yields a session cookie rather than
     * an error, since a cookie is not worth failing a response over.
     *
     * The value is percent-encoded unless `encode_callback` says otherwise: a callable
     * replaces the encoding, and `false` asserts the value is already encoded. Encoding by
     * default is what stops a value from injecting extra cookie attributes such as
     * `; Domain=evil.example` or control characters.
     *
     * Every attribute is read defensively, so a cookie definition assembled by application
     * code with a missing or oddly typed key still serializes to something sendable rather
     * than failing the response.
     *
     * @param      array<array-key, mixed> $cookie Keys: value, lifetime, path, domain,
     *             secure, httponly, encode_callback, samesite -- all optional.
     * @return     array{
     *   value: string,
     *   expires: ?int,
     *   max_age: ?int,
     *   path: string,
     *   domain: ?string,
     *   secure: bool,
     *   httponly: bool,
     *   samesite: ?string
     * }
     */
    public function normalize(string $name, array $cookie): array
    {
        $now = $this->clock->unixTimestamp();
        $value = $cookie['value'] ?? null;
        $shouldDelete = ($value === false || $value === null || $value === '');

        if (!$shouldDelete) {
            $value = $this->encodeValue($name, $value, $cookie['encode_callback'] ?? 'rawurlencode');
        }

        if ($shouldDelete) {
            $value = '';
            $expires = $now - 86400;
            $maxAge = 0;
        } else {
            $expires = self::resolveExpiry($cookie['lifetime'] ?? 0, $now);
            $maxAge = $expires !== null ? max(0, $expires - $now) : null;
        }

        $path = $cookie['path'] ?? $this->defaultPath;
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $domain = $cookie['domain'] ?? null;
        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        $samesite = $cookie['samesite'] ?? null;
        $samesite = is_string($samesite) && $samesite !== '' ? ucfirst(strtolower($samesite)) : null;

        return [
            'value' => self::stringify($value),
            'expires' => $expires,
            'max_age' => $maxAge,
            'path' => $path,
            'domain' => $domain,
            'secure' => !empty($cookie['secure']),
            'httponly' => !empty($cookie['httponly']),
            'samesite' => $samesite,
        ];
    }

    /**
     * The `Set-Cookie` value for an already-normalized cookie.
     *
     * @param      array{
     *   value: string,
     *   expires: ?int,
     *   max_age: ?int,
     *   path: string,
     *   domain: ?string,
     *   secure: bool,
     *   httponly: bool,
     *   samesite: ?string
     * } $normalized
     */
    public function header(string $name, array $normalized): string
    {
        $parts = [$name . '=' . $normalized['value']];

        if ($normalized['expires'] !== null) {
            $parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s T', $normalized['expires']);
            if ($normalized['max_age'] !== null) {
                $parts[] = 'Max-Age=' . $normalized['max_age'];
            }
        } elseif ($normalized['max_age'] === 0) {
            $parts[] = 'Max-Age=0';
        }

        if ($normalized['path'] !== '') {
            $parts[] = 'Path=' . $normalized['path'];
        }
        if ($normalized['domain']) {
            $parts[] = 'Domain=' . $normalized['domain'];
        }
        if ($normalized['secure']) {
            $parts[] = 'Secure';
        }
        if ($normalized['httponly']) {
            $parts[] = 'HttpOnly';
        }
        if ($normalized['samesite']) {
            $parts[] = 'SameSite=' . $normalized['samesite'];
        }

        return implode('; ', $parts);
    }

    /**
     * Apply the cookie's encoding policy to its value.
     *
     * A failing encoder falls back to the raw value: a cookie is not worth failing a whole
     * response over, and the failure is reported so it is not silent.
     */
    private function encodeValue(string $name, mixed $value, mixed $encodeCallback): mixed
    {
        if ($encodeCallback === false) {
            // The caller asserts the value is already encoded.
            return $value;
        }

        try {
            if (is_callable($encodeCallback)) {
                return call_user_func($encodeCallback, $value);
            }

            return rawurlencode(self::stringify($value));
        } catch (\Throwable $e) {
            \Quiote\Logging\Log::for($this)->warning(
                '[CookieSerializer] encode callback failed for cookie "' . $name
                . '", sending the raw value: ' . $e->getMessage()
            );

            return $value;
        }
    }

    /**
     * Absolute expiry timestamp for a declared lifetime, or null for a session cookie.
     */
    private static function resolveExpiry(mixed $lifetime, int $now): ?int
    {
        if (is_string($lifetime) && $lifetime !== '') {
            $parsed = strtotime($lifetime);

            return $parsed !== false ? $parsed : null;
        }

        if (is_numeric($lifetime)) {
            $seconds = (int) $lifetime;

            return $seconds > 0 ? $now + $seconds : null;
        }

        return null;
    }

    /**
     * Coerce a cookie value to the string that goes on the wire. A non-scalar value has no
     * meaningful cookie representation and becomes empty.
     */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
