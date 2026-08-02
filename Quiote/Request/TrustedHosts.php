<?php

declare(strict_types=1);

namespace Quiote\Request;

use Quiote\Config\Config;

/**
 * The `core.trusted_hosts` allow-list, applied to a hostname taken from the
 * request.
 *
 * Every hostname the framework can learn from a request is attacker-controlled:
 * `Host` is written by the client, and `X-Forwarded-Host` / `X-Original-Host` /
 * `Forwarded` are *also* just request headers unless a proxy is known to
 * overwrite them (nothing in a PHP process can tell the difference). Those
 * values feed generated absolute URLs -- base href, the `Location` of a
 * "/"-relative redirect, password-reset links -- so an unfiltered one is a
 * host-header poisoning and cache-poisoning primitive.
 *
 * This lived inline in {@see RequestUrl} and so protected only the one path
 * that called it. {@see \Quiote\Routing\Routing::getBaseHref()} resolves a host
 * of its own from `$_SERVER` when no context request is available, and that
 * path went unfiltered -- meaning an application that had correctly configured
 * `core.trusted_hosts` was still poisonable through `X-Forwarded-Host`. Sharing
 * one implementation is what keeps the two from drifting apart again.
 *
 * A non-matching host is replaced with the first literal entry rather than
 * rejected outright: this runs while building a URL, where there is no response
 * to fail into, and canonicalizing to a host the operator named is both safe
 * and useful. An empty/unset setting applies no restriction at all, which
 * preserves the behaviour of deployments that predate the setting.
 * @since      3.0.4
 */
final class TrustedHosts
{
    private function __construct()
    {
    }

    /**
     * $host, or the first literal trusted host when $host matches none of them.
     *
     * @param      string $host The hostname resolved from the request.
     * @return     string The hostname to actually use.
     * @since      3.0.4
     */
    public static function filter(string $host): string
    {
        if ($host === '') {
            return $host;
        }

        return self::filterAgainst($host, Config::getArray('core.trusted_hosts', []));
    }

    /**
     * The filtering itself, against an explicit list.
     *
     * Separate from {@see filter()} so the rule can be exercised without a
     * bootstrapped {@see Config}, and so a caller holding an already-read list
     * does not re-read it.
     *
     * An entry wrapped in `/` is treated as a PCRE pattern, anything else as an
     * exact (case-insensitive) hostname. A pattern that fails to compile simply
     * does not match -- it must not be allowed to throw here, and it must not
     * count as a match either, or a typo would open the allow-list up rather
     * than close it.
     *
     * @param      string $host The hostname resolved from the request.
     * @param      array<array-key, mixed> $trustedHosts The configured entries.
     * @return     string The hostname to actually use.
     * @since      3.0.4
     */
    public static function filterAgainst(string $host, array $trustedHosts): string
    {
        if ($host === '' || $trustedHosts === []) {
            return $host;
        }

        $firstLiteral = null;
        foreach ($trustedHosts as $trusted) {
            if (!is_string($trusted) || $trusted === '') {
                continue;
            }
            $isRegex = strlen($trusted) > 1 && $trusted[0] === '/' && str_ends_with($trusted, '/');
            if ($firstLiteral === null && !$isRegex) {
                $firstLiteral = $trusted;
            }
            if ($isRegex ? @preg_match($trusted, $host) === 1 : strcasecmp($trusted, $host) === 0) {
                return $host;
            }
        }

        return $firstLiteral ?? $host;
    }
}
