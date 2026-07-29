<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use DateTimeImmutable;
use Exception;
use Psr\Http\Message\ResponseInterface;

/**
 * The subset of a Get Blob Properties response worth typing: everything else
 * Azure returns (blob type, lease state, x-ms-meta-* headers) is available
 * from the raw response via {@see AzureBlobClient::request()} for callers
 * that need it.
 *
 * Every field is nullable because the response is not contractually obliged
 * to carry it, and callers that require a value should say so with their own
 * error rather than get a silently invented zero.
 */
final readonly class BlobMetadata
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
