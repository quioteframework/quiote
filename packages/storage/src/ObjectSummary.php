<?php

declare(strict_types=1);

namespace Quiote\Storage;

use DateTimeImmutable;

/**
 * One entry in a {@see ObjectListing}: the same three metadata fields {@see ObjectMetadata}
 * carries for a single object, plus the key they describe, so a listing result and a
 * {@see ObjectStoreClientInterface::head()} result read the same way.
 *
 * Populated from a list response's body rather than headers, so it is its own type rather than
 * an {@see ObjectMetadata} with a key bolted on -- the two are parsed differently even though
 * they describe the same three things.
 *
 * @since      4.2.0
 */
final readonly class ObjectSummary
{
    public function __construct(
        public string $key,
        public ?int $size,
        public ?DateTimeImmutable $lastModified,
        public ?string $etag,
    ) {
    }
}
