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
     * Server params a cassette may put back into a replayed request. A subset of
     * `RecorderMiddleware`'s own capture allowlist, with `REMOTE_ADDR` deliberately absent -- see
     * {@see replayableServerParams()}.
     *
     * @var list<string>
     */
    private const REPLAYABLE_SERVER_PARAMS = [
        'REQUEST_METHOD', 'REQUEST_URI', 'SERVER_PROTOCOL', 'HTTP_HOST',
        'SERVER_NAME', 'SERVER_PORT', 'REQUEST_TIME_FLOAT',
    ];

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
        // Replaying a truncated body sends a prefix and calls it the request. Whatever the
        // application then does differently gets attributed to the application rather than to the
        // recording, which is the wrong answer to give and worse than no answer.
        $body = $data['body'] ?? null;
        if (is_array($body) && ($body['truncated'] ?? false) === true) {
            throw new ReplayException(
                'Cassette carries a truncated request body (it exceeded replay.max_bytes when recorded), so '
                . 'replaying it would send only a prefix and report the difference as drift in the '
                . 'application. Re-record with a larger replay.max_bytes.',
            );
        }

        $protocol = is_string($data['protocol'] ?? null) ? $data['protocol'] : '1.1';
        $server = self::replayableServerParams($data['server'] ?? null);
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

            // A multipart/form-data request's raw body is never recorded (see
            // RecorderMiddleware::captureParsedBody()'s own docblock for why that isn't
            // recoverable even in principle) -- its form fields are restored from this instead,
            // the same shape the app itself reads them in via getParsedBody(). Reconstructing an
            // actual multipart-encoded byte string is unnecessary: nothing downstream re-parses
            // the raw body once it is already set here, PayloadParsingMiddleware included, which
            // only parses when getParsedBody() is not already populated.
            $parsedBody = $data['parsed_body'] ?? null;
            if (is_array($parsedBody) && $parsedBody !== []) {
                $request = $request->withParsedBody($parsedBody);
            }

            return WebRequest::fromPsr($request);
        } catch (\InvalidArgumentException $e) {
            throw new ReplayException(sprintf(
                'Cassette carries a request PSR-7 will not accept (%s). The cassette is corrupt or has been edited.',
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * The recorded `server` params that are safe to hand back to the application.
     *
     * A cassette is unauthenticated input -- there is no integrity marker on a `.qcast` file, and
     * one can arrive from an object store, a bug report or a colleague. Restoring `server`
     * wholesale meant a crafted cassette could present itself to the application as originating
     * from `REMOTE_ADDR` of its choosing, which is exactly what an internal-IP or trusted-proxy
     * check reads. `127.0.0.1` is a short cassette edit away.
     *
     * So the client's address is dropped rather than replayed. It is the one recorded server param
     * that grants anything, and a replay's request genuinely does not come from where the original
     * did -- restoring it was fidelity in appearance and a bypass in effect. The rest of the
     * recorder's own allowlist is descriptive (method, URI, protocol, host, port, time) and is kept
     * so a replayed request still looks like the one recorded.
     *
     * @return array<string, mixed>
     */
    private static function replayableServerParams(mixed $server): array
    {
        if (!is_array($server)) {
            return [];
        }

        $result = [];
        foreach ($server as $name => $value) {
            if (is_string($name) && in_array($name, self::REPLAYABLE_SERVER_PARAMS, true)) {
                $result[$name] = $value;
            }
        }

        return $result;
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
