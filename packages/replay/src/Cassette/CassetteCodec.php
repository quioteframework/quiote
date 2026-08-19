<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use JsonException;

/**
 * Encodes/decodes a {@see Cassette} to/from its `.qcast` container: canonical
 * JSON, gzipped by default so bodies and ledgers compress well, with a raw
 * (plain JSON) path for inspection.
 *
 * `_schema_version` is checked: this codec understands exactly one version. A
 * newer version is refused outright, naming the version it needs -- no
 * silent best-effort parsing. There is no older version yet, so the "load an
 * old version through a documented forward-reader" branch has nothing to
 * implement; when a version 2 exists, that branch is added here rather than
 * assumed in advance.
 */
final class CassetteCodec
{
    public const CURRENT_SCHEMA_VERSION = 1;

    /**
     * Ceiling on the inflated size of a `.qcast` payload, well above what
     * `replay.max_bytes`' own 2 MiB default plus a bounded effect ledger can produce, and far
     * below what an unbounded inflate can cost. See {@see decode()} for why a ceiling exists
     * at all.
     */
    public const DEFAULT_MAX_DECODED_BYTES = 33_554_432;

    /**
     * Input fed to the incremental inflater per step. Deliberately small: `inflate_add()`
     * inflates whatever it is given in one call and allocates the whole result before
     * returning, so bounding the *input* per call is what bounds the allocation -- checking the
     * running total between calls cannot help if one call is already too large. DEFLATE's
     * expansion is capped at roughly 1032:1, so 8 KiB in cannot produce more than about 8 MiB
     * out, which keeps the overshoot past the ceiling to one chunk's worth.
     */
    private const INFLATE_CHUNK_BYTES = 8_192;

    /**
     * @param positive-int $maxDecodedBytes Inflated-size ceiling for {@see decode()}.
     */
    public function __construct(
        private readonly int $maxDecodedBytes = self::DEFAULT_MAX_DECODED_BYTES,
    ) {
    }

    /** Gzip-wrapped JSON -- the on-disk `.qcast` format. */
    public function encode(Cassette $cassette): string
    {
        $compressed = gzencode($this->encodeRaw($cassette), 9);
        if ($compressed === false) {
            throw new CassetteCodecException('Failed to gzip-encode the cassette payload.');
        }

        return $compressed;
    }

    /** Plain JSON, uncompressed -- the `--raw` inspection format. */
    public function encodeRaw(Cassette $cassette): string
    {
        try {
            return json_encode($this->toArray($cassette), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new CassetteCodecException('Failed to encode the cassette payload as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decodes a gzip-wrapped `.qcast` payload.
     *
     * Inflated incrementally against {@see $maxDecodedBytes} rather than through `gzdecode()`,
     * because a cassette is untrusted input and gzip's compression ratio is unbounded: a few
     * hundred kilobytes of highly repetitive `.qcast` inflates to hundreds of megabytes, and
     * exhausting `memory_limit` is a fatal error rather than a catchable one -- so a single
     * oversized cassette in a store would take down `cassette:list`/`cassette:prune` for every
     * cassette, past any `catch (Throwable)` a caller wrapped it in. Checking the budget as the
     * output grows refuses that payload with a normal exception instead, and does so before the
     * allocation rather than after it.
     */
    public function decode(string $payload): Cassette
    {
        return $this->decodeRaw($this->inflateBounded($payload));
    }

    /**
     * @throws CassetteCodecException if the payload is not a gzip container, or inflates past
     *         the configured ceiling.
     */
    private function inflateBounded(string $payload): string
    {
        // @-suppressed deliberately: this is exactly the untrusted-input boundary the project's
        // no-silent-swallow rule carves out an exception for -- the failure is reported via the
        // explicit false checks below, not left to a raw PHP warning.
        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new CassetteCodecException('Could not initialise the gzip inflater for the cassette payload.');
        }

        $json = '';
        $length = strlen($payload);
        for ($offset = 0; $offset < $length; $offset += self::INFLATE_CHUNK_BYTES) {
            $isLast = $offset + self::INFLATE_CHUNK_BYTES >= $length;
            $chunk = @inflate_add(
                $context,
                substr($payload, $offset, self::INFLATE_CHUNK_BYTES),
                $isLast ? ZLIB_FINISH : ZLIB_NO_FLUSH,
            );
            if ($chunk === false) {
                throw new CassetteCodecException('Cassette payload is not a valid gzip container (truncated or corrupt).');
            }
            $json .= $chunk;
            if (strlen($json) > $this->maxDecodedBytes) {
                throw new CassetteCodecException(sprintf(
                    'Cassette payload inflates to more than the %d-byte ceiling this codec accepts; refusing to '
                    . 'decode it. A cassette this large is either corrupt or hostile -- a legitimate one is bounded '
                    . 'by replay.max_bytes.',
                    $this->maxDecodedBytes,
                ));
            }
        }

        if ($json === '') {
            throw new CassetteCodecException('Cassette payload is not a valid gzip container (truncated or corrupt).');
        }

        return $json;
    }

    /** Decodes a plain-JSON (`--raw`) payload. */
    public function decodeRaw(string $json): Cassette
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CassetteCodecException('Cassette payload is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!self::isStringKeyedArray($decoded)) {
            throw new CassetteCodecException('Cassette payload must decode to a JSON object.');
        }

        return $this->fromArray($decoded);
    }

    /** @return array<string, mixed> */
    private function toArray(Cassette $cassette): array
    {
        return [
            '_schema_version' => $cassette->schemaVersion,
            'meta' => $cassette->meta,
            'request' => $cassette->request,
            'resolved' => $cassette->resolved,
            'session' => $cassette->session,
            'user' => $cassette->user,
            'effects' => array_map(self::effectToArray(...), $cassette->effects),
            'response' => $cassette->response,
            'exception' => $cassette->exception,
            'log' => $cassette->log,
        ];
    }

    /** @return array<string, mixed> */
    private static function effectToArray(Effect $effect): array
    {
        return [
            'seq' => $effect->seq,
            'kind' => $effect->kind->value,
            'fingerprint' => $effect->fingerprint,
            'call' => $effect->call,
            'result' => $effect->result,
            'duration_us' => $effect->durationMicros,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function fromArray(array $data): Cassette
    {
        $version = $data['_schema_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new CassetteCodecException('Cassette payload is missing a positive integer "_schema_version".');
        }
        if ($version > self::CURRENT_SCHEMA_VERSION) {
            throw new CassetteCodecException(sprintf(
                'Cassette schema version %d is newer than this codec supports (%d). Upgrade quioteframework/replay.',
                $version,
                self::CURRENT_SCHEMA_VERSION,
            ));
        }

        $meta = self::requireSection($data, 'meta');
        $request = self::requireSection($data, 'request');
        $response = self::requireSection($data, 'response');

        return new Cassette(
            schemaVersion: $version,
            meta: $meta,
            request: $request,
            resolved: self::optionalSection($data, 'resolved') ?? [],
            session: self::optionalSection($data, 'session'),
            user: self::optionalSection($data, 'user'),
            effects: self::effectsFromArray($data['effects'] ?? []),
            response: $response,
            exception: self::optionalSection($data, 'exception'),
            log: self::optionalList($data, 'log'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function requireSection(array $data, string $key): array
    {
        $section = $data[$key] ?? null;
        if (!self::isStringKeyedArray($section)) {
            throw new CassetteCodecException(sprintf('Cassette payload is missing its required "%s" section.', $key));
        }

        return $section;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function optionalSection(array $data, string $key): ?array
    {
        $section = $data[$key] ?? null;

        return self::isStringKeyedArray($section) ? $section : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<mixed>|null
     */
    private static function optionalList(array $data, string $key): ?array
    {
        $list = $data[$key] ?? null;

        return is_array($list) ? array_values($list) : null;
    }

    /**
     * A JSON object decodes to a string-keyed PHP array unless a key happens to look like a
     * canonical integer, in which case `json_decode` silently produces an int key instead --
     * the one case a bare `is_array()` check can't tell apart from a JSON array. Every cassette
     * section is a JSON object, so that case is refused rather than accepted with a wrong type.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isStringKeyedArray(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Effect>
     * @throws CassetteCodecException if two effects share a `seq`.
     */
    private static function effectsFromArray(mixed $effects): array
    {
        if (!is_array($effects)) {
            throw new CassetteCodecException('Cassette "effects" must be a JSON array.');
        }

        $decoded = array_values(array_map(self::effectFromArray(...), $effects));

        // `EffectLedger` keys its consumed-set by `seq`, so two effects sharing one are marked
        // consumed together -- matching the first silently makes the second unreachable and turns
        // it into a phantom `unplayed()` entry. The recorder never produces a duplicate; a cassette
        // that carries one has been edited or corrupted, and saying so beats replaying it wrong.
        $seen = [];
        foreach ($decoded as $effect) {
            if (isset($seen[$effect->seq])) {
                throw new CassetteCodecException(sprintf(
                    'Cassette has two effects with seq %d; sequence numbers must be unique.',
                    $effect->seq,
                ));
            }
            $seen[$effect->seq] = true;
        }

        return $decoded;
    }

    private static function effectFromArray(mixed $effectData): Effect
    {
        if (!is_array($effectData)) {
            throw new CassetteCodecException('Cassette "effects" entry must be a JSON object.');
        }

        $seq = $effectData['seq'] ?? null;
        $kindValue = $effectData['kind'] ?? null;
        $fingerprint = $effectData['fingerprint'] ?? null;
        $call = $effectData['call'] ?? null;
        $durationMicros = $effectData['duration_us'] ?? null;

        if (!is_int($seq) || $seq < 0) {
            throw new CassetteCodecException('Cassette effect entry has an invalid or missing "seq".');
        }
        if (!is_string($kindValue) || EffectKind::tryFrom($kindValue) === null) {
            throw new CassetteCodecException(sprintf('Cassette effect entry has an unknown "kind" (%s).', var_export($kindValue, true)));
        }
        if (!is_string($fingerprint)) {
            throw new CassetteCodecException('Cassette effect entry has a missing or non-string "fingerprint".');
        }
        if (!self::isStringKeyedArray($call)) {
            throw new CassetteCodecException('Cassette effect entry has a missing or non-object "call".');
        }
        if ($durationMicros !== null && (!is_int($durationMicros) || $durationMicros < 0)) {
            throw new CassetteCodecException('Cassette effect entry has an invalid "duration_us".');
        }

        return new Effect(
            $seq,
            EffectKind::from($kindValue),
            $fingerprint,
            $call,
            $effectData['result'] ?? null,
            $durationMicros,
        );
    }
}
