<?php

declare(strict_types=1);

namespace Quiote\Request;

use Psr\Http\Message\UriInterface;
use Quiote\Config\Config;
use Quiote\Util\Toolkit;

/**
 * Immutable holder for the URL metadata WebRequest exposes alongside the
 * wrapped PSR-7 request: scheme, host, port, path, query, and the derived
 * request URI / full URL / protocol string.
 */
final class RequestUrl
{
    public function __construct(
        public readonly ?string $protocol = null,
        public readonly string $scheme = '',
        public readonly string $host = '',
        public readonly int $port = 0,
        public readonly string $path = '',
        public readonly string $query = '',
        public readonly string $requestUri = '',
        public readonly string $url = '',
    ) {
    }

    public function withScheme(string $scheme): self
    {
        return new self($this->protocol, $scheme, $this->host, $this->port, $this->path, $this->query, $this->requestUri, $this->url);
    }

    public function withHost(string $host): self
    {
        return new self($this->protocol, $this->scheme, $host, $this->port, $this->path, $this->query, $this->requestUri, $this->url);
    }

    public function withPort(int $port): self
    {
        return new self($this->protocol, $this->scheme, $this->host, $port, $this->path, $this->query, $this->requestUri, $this->url);
    }

    public function withRequestUri(string $requestUri): self
    {
        return new self($this->protocol, $this->scheme, $this->host, $this->port, $this->path, $this->query, $requestUri, $this->url);
    }

    public function withPath(string $path): self
    {
        return new self($this->protocol, $this->scheme, $this->host, $this->port, $path, $this->query, $this->requestUri, $this->url);
    }

    public function withQuery(string $query): self
    {
        return new self($this->protocol, $this->scheme, $this->host, $this->port, $this->path, $query, $this->requestUri, $this->url);
    }

    public function withProtocol(?string $protocol): self
    {
        return new self($protocol, $this->scheme, $this->host, $this->port, $this->path, $this->query, $this->requestUri, $this->url);
    }

    /**
     * Effective port: falls back to the scheme default (443/80) when unset.
     */
    public function effectivePort(): int
    {
        if ($this->port === 0) {
            if ($this->scheme === 'https') {
                return 443;
            }
            if ($this->scheme === 'http') {
                return 80;
            }
        }
        return $this->port;
    }

    public function authority(bool $forcePort = false): string
    {
        $port = $this->effectivePort();
        return $this->host . ($forcePort || Toolkit::isPortNecessary($this->scheme, $port) ? ':' . $port : '');
    }

    public function fullUrl(): string
    {
        return $this->scheme . '://' . $this->authority() . $this->requestUri;
    }

    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * Derive URL metadata from a wrapped PSR-7 request's URI.
     * @param array<string, mixed> $serverParams
     */
    public static function fromUri(UriInterface $uri, array $serverParams, string $protocolVersion): self
    {
        $scheme = (string)$uri->getScheme();
        $host = (string)$uri->getHost();

        $rawPort = $uri->getPort();
        if ($rawPort === null) {
            if (isset($serverParams['SERVER_PORT']) && is_numeric($serverParams['SERVER_PORT'])) {
                $rawPort = (int)$serverParams['SERVER_PORT'];
            }
        }
        if ($rawPort === null || $rawPort === 0) {
            if ($scheme === 'https') {
                $rawPort = 443;
            } elseif ($scheme === 'http') {
                $rawPort = 80;
            }
        }
        $port = (int)($rawPort ?? 0);

        $query = (string)$uri->getQuery();
        $path = (string)$uri->getPath();
        $requestUri = $path . ($query !== '' ? '?' . $query : '');
        $protocol = $protocolVersion !== '' ? 'HTTP/' . $protocolVersion : null;
        $url = $uri->__toString();

        return new self($protocol, $scheme, $host, $port, $path, $query, $requestUri, $url);
    }

    /**
     * Safely coerce a $_SERVER-style value to a string; non-scalar values
     * (which should never legitimately appear in $_SERVER) become ''.
     */
    private static function toStr(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Derive legacy URL metadata from PHP's server parameters when no PSR-7
     * request is available (e.g. unit tests, early bootstrap flows).
     * @param array<string, mixed> $server
     */
    public static function fromServerParams(array $server): self
    {
        // Determine scheme with priority: explicit forwarded proto -> request scheme -> HTTPS flag.
        $scheme = '';
        if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
            $forwarded = explode(',', self::toStr($server['HTTP_X_FORWARDED_PROTO']));
            $scheme = strtolower(trim($forwarded[0]));
        }
        if ($scheme === '' && !empty($server['REQUEST_SCHEME'])) {
            $scheme = strtolower(self::toStr($server['REQUEST_SCHEME']));
        }
        if ($scheme === '') {
            $https = $server['HTTPS'] ?? null;
            if (is_string($https)) {
                $flag = strtolower($https);
                if ($flag === 'on' || $flag === '1' || $flag === 'https') {
                    $scheme = 'https';
                } elseif ($flag === 'off' || $flag === '0') {
                    $scheme = 'http';
                }
            } elseif ($https === true) {
                $scheme = 'https';
            }
        }
        if ($scheme === '') {
            $scheme = 'http';
        }

        // Resolve host (and potential port) from Host header first, then server name/address.
        $hostHeader = $server['HTTP_HOST'] ?? '';
        if ($hostHeader === '') {
            $hostHeader = $server['SERVER_NAME'] ?? ($server['SERVER_ADDR'] ?? '');
        }
        $authority = '//' . ltrim(self::toStr($hostHeader), '/');
        $parsedHost = parse_url($authority, PHP_URL_HOST);
        $parsedPort = parse_url($authority, PHP_URL_PORT);
        $host = is_string($parsedHost) ? $parsedHost : '';
        $port = ($parsedPort !== null && $parsedPort !== false) ? (int)$parsedPort : null;
        if ($host === '' && !empty($server['SERVER_NAME'])) {
            $host = self::toStr($server['SERVER_NAME']);
        }
        if ($port === null && isset($server['SERVER_PORT']) && is_numeric($server['SERVER_PORT'])) {
            $port = (int)$server['SERVER_PORT'];
        }
        if ($port === null) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        // Trusted-host validation. The Host header is attacker-controlled and feeds
        // generated absolute URLs (base href, redirect Location for "/"-relative
        // targets, password-reset links, ...), so an unvalidated value enables
        // host-header poisoning / cache poisoning. When core.trusted_hosts is set
        // (array of exact hostnames and/or "/regex/" patterns), a Host matching none
        // of them is replaced with the first literal trusted host (safe
        // canonicalization). Empty/unset preserves the previous behavior (no
        // restriction) for backwards compatibility — set it in production.
        $trustedHosts = Config::getArray('core.trusted_hosts', []);
        if ($trustedHosts !== [] && $host !== '') {
            $matched = false;
            $firstLiteral = null;
            foreach ($trustedHosts as $th) {
                if (!is_string($th) || $th === '') {
                    continue;
                }
                $isRegex = (strlen($th) > 1 && $th[0] === '/' && str_ends_with($th, '/'));
                if ($firstLiteral === null && !$isRegex) {
                    $firstLiteral = $th;
                }
                if ($isRegex ? (bool)@preg_match($th, $host) : (strcasecmp($th, $host) === 0)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched && $firstLiteral !== null) {
                $host = $firstLiteral;
            }
        }

        // Build request URI, path, and query pieces.
        $requestUri = self::toStr($server['REQUEST_URI'] ?? '');
        if ($requestUri === '' && isset($server['ORIG_PATH_INFO'])) {
            $requestUri = self::toStr($server['ORIG_PATH_INFO']);
            if (!empty($server['QUERY_STRING'])) {
                $requestUri .= '?' . self::toStr($server['QUERY_STRING']);
            }
        }
        if ($requestUri === '') {
            $requestUri = '/';
        }
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $query = parse_url($requestUri, PHP_URL_QUERY);
        if ($query === null || $query === false) {
            $query = '';
        }

        $protocol = isset($server['SERVER_PROTOCOL']) ? self::toStr($server['SERVER_PROTOCOL']) : null;
        $requestUriFull = $path . ($query !== '' ? '?' . $query : '');
        $url = $scheme . '://' . $host . (Toolkit::isPortNecessary($scheme, $port) ? ':' . $port : '') . $requestUriFull;

        return new self($protocol, $scheme, $host, (int)$port, $path, $query, $requestUriFull, $url);
    }
}
