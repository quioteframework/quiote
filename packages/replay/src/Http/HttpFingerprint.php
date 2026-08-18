<?php

declare(strict_types=1);

namespace Quiote\Replay\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The fingerprint scheme shared by {@see RecordingHttpTransport} and
 * {@see \Quiote\Replay\Replay\StubbedHttpTransport}: method + normalized URI +
 * a hash of the request body. It has to be computed identically on both sides
 * or a cassette recorded by one could never be matched by the other.
 *
 * Reading the body to hash it must not disturb it for whatever reads it next
 * (the real transport on the recording side; nothing, on the stub side, since
 * a stub never forwards the request at all) -- so a seekable body is rewound
 * before AND after the read. A non-seekable body cannot be read-and-restored
 * safely, so it is left untouched and its content is excluded from the
 * fingerprint entirely (`"unseekable-body"` in its place) rather than risking
 * consuming a stream the real transport still needs to send.
 */
final class HttpFingerprint
{
    public static function of(RequestInterface $request): string
    {
        return strtoupper($request->getMethod()) . ' ' . (string) $request->getUri() . ' ' . self::bodyHash($request->getBody());
    }

    private static function bodyHash(StreamInterface $body): string
    {
        if (!$body->isSeekable()) {
            return 'unseekable-body';
        }

        $body->rewind();
        $hash = hash('sha256', (string) $body);
        $body->rewind();

        return $hash;
    }

    /**
     * A plain-string snapshot of a seekable stream's content, restoring its
     * position afterward. Returns null for a non-seekable stream rather than
     * consuming it -- the caller decides what "no captured body" means for
     * its own recorded effect.
     */
    public static function captureBody(StreamInterface $stream): ?string
    {
        if (!$stream->isSeekable()) {
            return null;
        }

        $stream->rewind();
        $content = (string) $stream;
        $stream->rewind();

        return $content;
    }
}
