<?php

declare(strict_types=1);

namespace Quiote\Runtime\Proxy;

/**
 * The scheme/host/port a reverse proxy says the client actually used, as
 * resolved by {@see ForwardedHeaderResolver}. Any field may be null when the
 * corresponding header was absent or unusable.
 *
 * $portExplicit says the port is the client's and is written back into the
 * request's SERVER_PORT / authority: either the proxy sent it, or the proxy
 * sent a scheme and no port, which makes it that scheme's default. A plain
 * `X-Forwarded-Proto: https` therefore means port 443, never the port of the
 * connection from the proxy.
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
