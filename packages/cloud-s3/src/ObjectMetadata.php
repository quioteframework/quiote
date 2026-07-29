<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

use DateTimeImmutable;
use Exception;
use Psr\Http\Message\ResponseInterface;

/**
 * The subset of an object's HEAD response worth typing: everything else S3
 * returns (storage class, versioning, SSE headers) is available from the raw
 * response via {@see S3Client::request()} for callers that need it.
 *
 * Every field is nullable because a HEAD response is not contractually
 * obliged to carry it — a proxy or an S3-compatible server may omit
 * Content-Length or ETag, and callers that require a value should say so with
 * their own error rather than get a silently invented zero.
 */
final readonly class ObjectMetadata
{
    public function __construct(
        public ?int $contentLength,
        public ?DateTimeImmutable $lastModified,
        public ?string $etag,
    ) {
    }

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
     * Last-Modified is an IMF-fixdate, which DateTimeImmutable parses
     * natively (including the GMT zone). A malformed value is treated as an
     * absent one — a timestamp nobody can trust is worse than none.
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
