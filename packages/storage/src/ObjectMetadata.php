<?php

declare(strict_types=1);

namespace Quiote\Storage;

use DateTimeImmutable;
use Exception;
use Psr\Http\Message\ResponseInterface;

/**
 * The subset of a stored object's HEAD response worth typing: everything else a provider returns
 * (storage class, versioning, generation, SSE and custom metadata headers) is available from the
 * raw response via the client's own `request()` method for callers that need it.
 *
 * Every field is nullable because a HEAD response is not contractually obliged to carry it -- a
 * proxy or an S3-compatible server may omit Content-Length or ETag, and callers that require a
 * value should say so with their own error rather than get a silently invented zero.
 *
 * Shared across providers: the three fields and their parsing are HTTP semantics, not provider
 * semantics, so S3, GCS and Azure describe an object the same way.
 *
 * @since      3.2.0
 */
final readonly class ObjectMetadata
{
    public function __construct(
        public ?int $contentLength,
        public ?DateTimeImmutable $lastModified,
        public ?string $etag,
    ) {
    }

    /**
     * Reads the typed fields out of a HEAD (or GET) response's headers.
     *
     * Each field independently becomes null when its header is absent or unusable: a
     * `Content-Length` that is not all digits, an unparseable `Last-Modified`, an empty
     * `ETag`. Nothing throws — a response with none of the three headers yields an all-null
     * instance.
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        return new self(
            self::parseContentLength($response->getHeaderLine('Content-Length')),
            self::parseHttpDate($response->getHeaderLine('Last-Modified')),
            self::parseEtag($response->getHeaderLine('ETag')),
        );
    }

    /**
     * The object's size, or null when the header is absent or is not a plain digit string.
     *
     * Rejects a value above `PHP_INT_MAX` rather than casting it: `(int)` saturates instead of
     * failing, so an 8-exabyte `Content-Length` would come back as a plausible-looking number that
     * is not the object's size -- the invented value this class exists to avoid handing out. No real
     * object store can produce one, but the header is whatever the far end sends, and a proxy or a
     * stub is not a real object store.
     */
    private static function parseContentLength(string $value): ?int
    {
        if (!ctype_digit($value)) {
            return null;
        }

        // Leading zeros are valid in a digit string and must not change the comparison below.
        $digits = ltrim($value, '0');
        if ($digits === '') {
            return 0;
        }

        $limit = (string) PHP_INT_MAX;
        // Equal-length digit strings compare lexicographically the same way they compare
        // numerically, which is what makes this safe without arbitrary-precision arithmetic.
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && $digits > $limit)) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * The ETag as a caller should compare it, or null when the header is absent or empty.
     *
     * A strong ETag loses its surrounding quotes -- `"abc"` becomes `abc` -- which is the form a
     * caller that stores one and compares it later wants. A weak ETag keeps its `W/"abc"` form
     * verbatim: a weak validator is not interchangeable with the strong tag carrying the same
     * opaque value, so reducing both to `abc` would let a caller treat one as the other. Anything
     * that is neither, such as a proxy sending an unquoted token, is passed through as it arrived
     * rather than guessed at.
     */
    private static function parseEtag(string $value): ?string
    {
        if (preg_match('~^"(.*)"$~', $value, $matches) === 1) {
            return $matches[1] === '' ? null : $matches[1];
        }

        return $value === '' ? null : $value;
    }

    /**
     * Last-Modified is an IMF-fixdate, which DateTimeImmutable parses natively (including the GMT
     * zone). A malformed value is treated as an absent one -- a timestamp nobody can trust is
     * worse than none.
     */
    private static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
