<?php

declare(strict_types=1);

namespace Quiote\Runtime\Proxy;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the reverse-proxy headers off a PSR-7 request and reports the
 * scheme/host/port the client actually used.
 *
 * Pure: no superglobal access, no mutation. That matters because the same
 * correction has to apply to requests a CLI-hosted worker server hands us
 * (RoadRunner, Swoole), where there is no $_SERVER to adjust -- previously
 * this logic lived in Kernel and worked by writing to $_SERVER directly,
 * which only ever worked under a real SAPI.
 *
 * Precedence per field: the explicit X-* header wins, then RFC 7239
 * `Forwarded`. X-Original-Host is checked before X-Forwarded-Host because
 * proxies that rewrite Host (Azure Application Gateway, some ingress
 * controllers) put the client's original value there.
 *
 * Note: every proxy header is trusted unconditionally when enabled. There is
 * no trusted-proxy allowlist; an app reachable directly from the internet
 * should set `core.proxy.trust_forwarded_headers` to false.
 */
final class ForwardedHeaderResolver
{
    private const HOST_HEADERS = ['X-Original-Host', 'X-Forwarded-Host'];
    private const PROTO_HEADERS = ['X-Forwarded-Proto'];
    private const PORT_HEADERS = ['X-Forwarded-Port'];

    public function resolve(ServerRequestInterface $request): ForwardedAuthority
    {
        $hostRaw = $this->headerOrForwarded($request, self::HOST_HEADERS, 'host');
        $protoRaw = $this->headerOrForwarded($request, self::PROTO_HEADERS, 'proto');
        $portRaw = $this->headerOrForwarded($request, self::PORT_HEADERS, 'port');

        if ($hostRaw === null && $protoRaw === null && $portRaw === null) {
            return new ForwardedAuthority();
        }

        [$host, $portFromHost, $portExplicit] = $this->parseHostAndPort($hostRaw);
        $scheme = $this->normaliseScheme($this->firstToken($protoRaw));
        $port = $portFromHost;

        if ($port === null && $portRaw !== null) {
            $token = $this->firstToken($portRaw);
            if ($token !== null && $token !== '' && is_numeric($token)) {
                $port = (int) $token;
                $portExplicit = true;
            }
        }

        return new ForwardedAuthority($scheme, $host, $port, $portExplicit);
    }

    /**
     * Formats a host for use in an authority: bare IPv6 literals need
     * bracketing, everything else passes through.
     */
    public static function formatAuthorityHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }

    /** Whether $port needs to appear in an authority at all for $scheme. */
    public static function isPortNonDefault(?string $scheme, int $port): bool
    {
        return match (strtolower((string) $scheme)) {
            'http' => $port !== 80,
            'https' => $port !== 443,
            default => true,
        };
    }

    /**
     * @param list<string> $headerNames
     */
    private function headerOrForwarded(ServerRequestInterface $request, array $headerNames, string $forwardedParam): ?string
    {
        foreach ($headerNames as $name) {
            $value = $request->getHeaderLine($name);
            if ($value !== '') {
                return $value;
            }
        }

        $forwarded = $request->getHeaderLine('Forwarded');
        if ($forwarded === '') {
            return null;
        }

        return $this->extractFromForwarded($forwarded, $forwardedParam);
    }

    private function extractFromForwarded(string $header, string $field): ?string
    {
        foreach (explode(',', $header) as $entry) {
            $pairs = preg_split('/\s*;\s*/', trim($entry)) ?: [];
            foreach ($pairs as $pair) {
                if ($pair === '') {
                    continue;
                }
                $kv = explode('=', $pair, 2);
                if (count($kv) !== 2) {
                    continue;
                }
                if (strcasecmp(trim($kv[0]), $field) === 0) {
                    return trim($kv[1], "\" \t");
                }
            }
        }
        return null;
    }

    private function firstToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach (explode(',', $value) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return null;
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: bool}
     */
    private function parseHostAndPort(?string $raw): array
    {
        $token = $this->firstToken($raw);
        if ($token === null || $token === '') {
            return [null, null, false];
        }
        $authority = '//' . ltrim($token, '/');
        $host = parse_url($authority, PHP_URL_HOST);
        $port = parse_url($authority, PHP_URL_PORT);
        if (!is_string($host) || $host === '') {
            return [null, null, false];
        }
        $explicit = is_int($port);
        return [$host, $explicit ? $port : null, $explicit];
    }

    private function normaliseScheme(?string $scheme): ?string
    {
        if ($scheme === null || $scheme === '') {
            return null;
        }
        return strtolower($scheme);
    }
}
