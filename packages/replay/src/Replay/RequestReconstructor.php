<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Request\WebRequest;

/**
 * Rebuilds the PSR-7 request a cassette recorded, so {@see ReplayEngine} can
 * hand it to the real pipeline. There is no existing factory that decodes a
 * cassette's plain-array request shape directly (confirmed: `WebRequest::fromPsr()`
 * only reads state off an already-built PSR-7 object) -- this builds a plain
 * `Nyholm\Psr7\ServerRequest` first, exactly as `WebRequest` itself composes
 * internally, then normalizes it through `WebRequest::fromPsr()` so it is the
 * same request shape a real worker would hand to
 * `Context::getRequestHandler()->handle()`.
 */
final class RequestReconstructor
{
    /**
     * @throws ReplayException if the cassette carries no method/uri to replay --
     *         a `#[NoRecord]` skeleton, or `replay.capture_body` was off when it was recorded.
     */
    public static function fromCassette(Cassette $cassette): ServerRequestInterface
    {
        $data = $cassette->request;
        $method = $data['method'] ?? null;
        $uri = $data['uri'] ?? null;
        if (!is_string($method) || !is_string($uri)) {
            throw new ReplayException(
                'Cassette carries no replayable request -- it may have been recorded under '
                . '#[NoRecord], or with replay.capture_body disabled.',
            );
        }

        $protocol = is_string($data['protocol'] ?? null) ? $data['protocol'] : '1.1';
        $server = is_array($data['server'] ?? null) ? $data['server'] : [];
        $cookies = is_array($data['cookies'] ?? null) ? $data['cookies'] : [];

        // PSR-7 validates the method, URI, header names and header values it is given, and every
        // one of those comes out of the cassette. Wrapped here so a malformed cassette is reported
        // as one: ReplayCommand catches this via ReplayException and says so, where an escaping
        // InvalidArgumentException was caught by its generic handler and reported as
        // 'Could not resolve context "..."' -- pointing the reader at the wrong subsystem entirely.
        try {
            $request = new ServerRequest(
                $method,
                $uri,
                self::stringListHeaders($data['headers'] ?? null),
                self::decodeBody($data['body'] ?? null),
                $protocol,
                $server,
            );
            $request = $request->withCookieParams($cookies);

            return WebRequest::fromPsr($request);
        } catch (\InvalidArgumentException $e) {
            throw new ReplayException(sprintf(
                'Cassette carries a request PSR-7 will not accept (%s). The cassette is corrupt or has been edited.',
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /** @return array<string, list<string>> */
    private static function stringListHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $result = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                continue;
            }
            $result[$name] = is_array($values)
                ? array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string)$v : '', $values))
                : [is_scalar($values) ? (string)$values : ''];
        }

        return $result;
    }

    private static function decodeBody(mixed $body): string
    {
        if (!is_array($body)) {
            return '';
        }
        $content = $body['content'] ?? '';
        if (!is_string($content) || $content === '') {
            return '';
        }
        if (($body['encoding'] ?? 'utf8') === 'base64') {
            $decoded = base64_decode($content, true);

            return $decoded !== false ? $decoded : '';
        }

        return $content;
    }
}
