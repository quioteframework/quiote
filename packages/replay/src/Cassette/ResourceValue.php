<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use Quiote\Logging\Log;
use RuntimeException;

/**
 * How a PHP resource is written into a cassette, and read back out of one.
 *
 * A cassette is JSON, and JSON has no representation for a resource: `json_encode()` fails on one
 * with "Type is not supported" and the whole cassette is lost. Resources do turn up in recorded
 * effects -- pdo_pgsql hands every `bytea` column back as a stream -- so each one is replaced with
 * a placeholder before it is stored:
 *
 *     {"$resource": "stream", "bytes": 1024, "base64": "...", "reason": null}
 *
 * A seekable stream no larger than {@see MAX_CAPTURE_BYTES} carries its content, read from offset
 * 0 and then put back at the position the application had it at, so the application's own read of
 * that same stream is unaffected. Anything else -- a non-seekable or larger stream, a closed one,
 * a non-stream resource -- is recorded without content and `reason` says why; it is never read,
 * since reading a stream that cannot seek back would consume it out from under the application.
 */
final class ResourceValue
{
    public const string KEY = '$resource';

    /** Content beyond this is not captured; the ledger's payload budget applies on top. */
    public const int MAX_CAPTURE_BYTES = 1_048_576;

    /**
     * `$value` with every resource in it, at any depth, replaced by its placeholder.
     */
    public static function replaceResources(mixed $value): mixed
    {
        if (self::isResource($value)) {
            return self::capture($value);
        }
        return is_array($value) ? self::replaceResourcesIn($value) : $value;
    }

    /**
     * {@see replaceResources()} for an array, keeping its key type.
     *
     * @template K of array-key
     * @param array<K, mixed> $values
     * @return array<K, mixed>
     */
    public static function replaceResourcesIn(array $values): array
    {
        foreach ($values as $key => $item) {
            if (is_array($item) || self::isResource($item)) {
                $values[$key] = self::replaceResources($item);
            }
        }

        return $values;
    }

    /** Whether `$value` is a placeholder this class wrote. */
    public static function isPlaceholder(mixed $value): bool
    {
        return is_array($value) && array_key_exists(self::KEY, $value) && is_string($value[self::KEY]);
    }

    /**
     * A fresh stream holding the content a placeholder recorded, positioned at its start.
     *
     * @param array<array-key, mixed> $placeholder
     * @return resource
     * @throws RuntimeException When the placeholder was recorded without its content: replaying
     *         an empty stream in its place would hand the application data it never saw.
     */
    public static function restore(array $placeholder)
    {
        $content = $placeholder['base64'] ?? null;
        $decoded = is_string($content) ? base64_decode($content, true) : false;
        if ($decoded === false) {
            $type = is_string($placeholder[self::KEY] ?? null) ? $placeholder[self::KEY] : 'resource';
            $reason = is_string($placeholder['reason'] ?? null) ? $placeholder['reason'] : 'no content was recorded';
            throw new RuntimeException(sprintf(
                'The cassette recorded a %s here without its content (%s), so it cannot be replayed faithfully.',
                $type,
                $reason,
            ));
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Could not open a memory stream to replay a recorded stream into.');
        }
        fwrite($stream, $decoded);
        rewind($stream);

        return $stream;
    }

    /**
     * `$row` with every placeholder in it turned back into a stream.
     *
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed>
     */
    public static function restoreRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value) && self::isPlaceholder($value)) {
                $row[$column] = self::restore($value);
            }
        }

        return $row;
    }

    private static function isResource(mixed $value): bool
    {
        return is_resource($value) || gettype($value) === 'resource (closed)';
    }

    /** @return array{'$resource': string, bytes: ?int, base64: ?string, reason: ?string} */
    private static function capture(mixed $resource): array
    {
        if (!is_resource($resource)) {
            return self::placeholder('resource', null, null, 'the resource was already closed');
        }
        $type = get_resource_type($resource);
        if ($type !== 'stream') {
            return self::placeholder($type, null, null, 'only stream content can be recorded');
        }

        $stat = fstat($resource);
        $bytes = is_array($stat) ? $stat['size'] : null;
        if (!stream_get_meta_data($resource)['seekable']) {
            return self::placeholder('stream', $bytes, null, 'the stream is not seekable, and reading it would consume it');
        }
        if ($bytes === null || $bytes > self::MAX_CAPTURE_BYTES) {
            return self::placeholder('stream', $bytes, null, sprintf('the stream is larger than %d bytes', self::MAX_CAPTURE_BYTES));
        }

        $position = ftell($resource);
        $content = stream_get_contents($resource, -1, 0);
        if (fseek($resource, $position === false ? 0 : $position) !== 0) {
            Log::create(self::class)->warning(sprintf(
                'A recorded stream could not be put back at offset %d; the application reading it next will start elsewhere.',
                $position === false ? 0 : $position,
            ));
        }
        if ($content === false) {
            return self::placeholder('stream', $bytes, null, 'the stream could not be read');
        }

        return self::placeholder('stream', strlen($content), base64_encode($content), null);
    }

    /** @return array{'$resource': string, bytes: ?int, base64: ?string, reason: ?string} */
    private static function placeholder(string $type, ?int $bytes, ?string $base64, ?string $reason): array
    {
        return [self::KEY => $type, 'bytes' => $bytes, 'base64' => $base64, 'reason' => $reason];
    }
}
