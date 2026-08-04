<?php

declare(strict_types=1);

namespace Quiote\Session;

use Quiote\Exception\StorageException;
use Throwable;

/**
 * The shipped session codec: igbinary when it is available and wanted, JSON otherwise, and
 * reads both regardless of which it writes.
 *
 * One class rather than one per format, because the two share the thing that must not vary:
 * how a stored payload is recognised on the way back in. A payload beginning with `{` or `[` is
 * JSON -- igbinary's format never does, it begins with a null byte -- and anything else is
 * offered to igbinary. That single rule is why a payload written by one backend stays readable
 * by another, and why switching `prefer_binary` on an existing store does not orphan what is
 * already in it.
 *
 * Which format to *write* is a per-backend decision, so it is a constructor argument rather
 * than a subclass: a local file or a database column benefits from igbinary's smaller, faster
 * payload, while for an object store the network round-trip dominates and JSON keeps the stored
 * object readable by anything else looking at the bucket.
 *
 * @since      3.2.0
 */
final class SessionCodec implements SessionCodecInterface
{
    /**
     * @param      bool $preferBinary Write igbinary when the extension is loaded. False writes
     *             JSON always. Decoding accepts both either way.
     */
    public function __construct(private readonly bool $preferBinary = true) {}

    /**
     * A codec for a store where payload size and encode/decode cost matter -- a local file, a
     * database column.
     */
    public static function binaryPreferred(): self
    {
        return new self(true);
    }

    /**
     * A codec for a store where the transport dominates and a human-readable payload is worth
     * more than a compact one -- an object store, a document database.
     */
    public static function portable(): self
    {
        return new self(false);
    }

    public function encode(array $data): string
    {
        if ($this->preferBinary && function_exists('igbinary_serialize')) {
            try {
                $packed = igbinary_serialize($data);
                if (is_string($packed)) {
                    return $packed;
                }
            } catch (Throwable $e) {
                // Falls through to JSON, which every build can write and read.
                \Quiote\Logging\Log::for($this)->debug(
                    '[SessionCodec] igbinary could not encode the session payload, using JSON: '
                    . $e->getMessage()
                );
            }
        }

        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Neither format could represent this payload, which means the session holds
            // something unserializable -- a closure, a resource, an object with no JSON form.
            // Failing loudly here is right: silently storing nothing would lose the session.
            throw new StorageException(
                'Session data cannot be encoded for storage: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function decode(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        if (self::looksLikeJson($payload)) {
            try {
                return self::asSessionData(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException $e) {
                \Quiote\Logging\Log::for($this)->debug(
                    '[SessionCodec] payload looked like JSON but did not decode: ' . $e->getMessage()
                );

                return null;
            }
        }

        if (!function_exists('igbinary_unserialize')) {
            // A binary payload on a build without the extension. Nothing can read it, and
            // saying so is more useful than answering "no session" without explanation.
            \Quiote\Logging\Log::for($this)->warning(
                '[SessionCodec] the stored session payload is not JSON and igbinary is not loaded, '
                . 'so it cannot be read; install ext-igbinary or clear the session store'
            );

            return null;
        }

        try {
            return self::asSessionData(@igbinary_unserialize($payload));
        } catch (Throwable $e) {
            \Quiote\Logging\Log::for($this)->debug(
                '[SessionCodec] igbinary could not decode the session payload: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Whether $payload is a JSON object or array.
     *
     * The one discriminator between the two formats. JSON encoding of an array or object always
     * begins with `{` or `[`, and igbinary's never does, so this is exact rather than heuristic
     * -- and unlike matching igbinary's own header it does not have to be revised when igbinary
     * changes its format version.
     */
    private static function looksLikeJson(string $payload): bool
    {
        return str_starts_with($payload, '{') || str_starts_with($payload, '[');
    }

    /**
     * Narrow a decoded payload to the string-keyed shape a session is.
     *
     * A JSON list, or an igbinary payload holding one, decodes to integer keys. That is not
     * session data, and handing it back would make the caller's key lookups silently miss, so it
     * is reported as unreadable instead.
     *
     * @return     array<string, mixed>|null
     */
    private static function asSessionData(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
