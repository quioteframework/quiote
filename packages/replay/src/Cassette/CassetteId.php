<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use Quiote\Support\CorrelationId;

/**
 * A cassette's id, and the safe filesystem/object-store key derived from it.
 *
 * A cassette id is untrusted input -- {@see CorrelationId::sanitize()} strips
 * control bytes and caps length, but
 * passes `/`, `.` and `..` straight through, verified against its source. A
 * caller who controls the correlation header therefore controls where a
 * cassette is written unless the id is reduced to a safe slug before it ever
 * reaches a store key or a filename. `$raw` is kept as data (it still has
 * value for a human reading `meta`), `$slug` is what a store ever uses as a
 * key.
 */
final readonly class CassetteId
{
    private const SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private function __construct(
        public string $raw,
        public string $slug,
    ) {
    }

    /**
     * Builds a CassetteId from a request's correlation id, falling back to a
     * freshly generated one when absent -- so every cassette gets an id
     * regardless of whether the request carried a correlation header.
     */
    public static function fromCorrelationId(?string $raw): self
    {
        $sanitized = $raw !== null ? CorrelationId::sanitize($raw) : null;

        return self::fromRaw($sanitized ?? CorrelationId::generate());
    }

    /**
     * Builds a CassetteId from an already-known raw value -- e.g. an id typed
     * on the command line by a developer pasting it out of a log line.
     */
    public static function fromRaw(string $raw): self
    {
        return new self($raw, self::slugify($raw));
    }

    /**
     * A raw value that is already a safe slug is kept as-is (readable in a
     * directory listing); anything else -- path separators, control bytes,
     * non-ASCII, or simply too long -- is reduced to its SHA-256 hex digest,
     * which is always exactly 64 characters and therefore always satisfies
     * the slug pattern on its own. Two different raw values collide onto one
     * slug only as often as SHA-256 does.
     */
    private static function slugify(string $raw): string
    {
        if (preg_match(self::SLUG_PATTERN, $raw) === 1) {
            return $raw;
        }

        return hash('sha256', $raw);
    }
}
