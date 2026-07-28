<?php

declare(strict_types=1);

namespace Quiote\Runtime\Proxy;

/**
 * The scheme/host/port a reverse proxy says the client actually used, as
 * resolved by {@see ForwardedHeaderResolver}. Any field may be null when the
 * corresponding header was absent or unusable.
 *
 * $portExplicit distinguishes "the proxy told us a port" from "we inferred
 * nothing": only an explicit port is written back into the request's
 * SERVER_PORT / authority, so a plain `X-Forwarded-Proto: https` does not
 * silently pin port 80 from the original connection.
 */
final readonly class ForwardedAuthority
{
    public function __construct(
        public ?string $scheme = null,
        public ?string $host = null,
        public ?int $port = null,
        public bool $portExplicit = false,
    ) {
    }

    /** True when there is nothing to apply, so callers can skip the rewrite entirely. */
    public function isEmpty(): bool
    {
        return $this->scheme === null && $this->host === null && $this->port === null;
    }
}
