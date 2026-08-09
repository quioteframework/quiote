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
     * `ETag`. The ETag's surrounding quotes are stripped. Nothing throws — a response with
     * none of the three headers yields an all-null instance.
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $length = $response->getHeaderLine('Content-Length');
        $etag = trim($response->getHeaderLine('ETag'), '"');

        return new self(
            ctype_digit($length) ? (int) $length : null,
            self::parseHttpDate($response->getHeaderLine('Last-Modified')),
            $etag === '' ? null : $etag,
        );
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
