<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use JsonException;

/**
 * Encodes/decodes a {@see Cassette} to/from its `.qcast` container, per
 * `docs/RECORD_REPLAY_PLAN.md` §4.4: canonical JSON, gzipped by default so
 * bodies and ledgers compress well, with a raw (plain JSON) path for
 * inspection.
 *
 * `_schema_version` is checked per §4.3: this codec understands exactly one
 * version. A newer version is refused outright, naming the version it
 * needs -- no silent best-effort parsing. There is no older version yet, so
 * the "load an old version through a documented forward-reader" branch that
 * section describes has nothing to implement; when a version 2 exists, that
 * branch is added here rather than assumed in advance.
 */
final class CassetteCodec
{
    public const CURRENT_SCHEMA_VERSION = 1;

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

    /** Decodes a gzip-wrapped `.qcast` payload. */
    public function decode(string $payload): Cassette
    {
        // @-suppressed deliberately: this is exactly the untrusted-input boundary the project's
        // no-silent-swallow rule carves out an exception for -- the failure is reported via the
        // explicit false check below, not left to a raw PHP warning.
        $json = @gzdecode($payload);
        if ($json === false) {
            throw new CassetteCodecException('Cassette payload is not a valid gzip container (truncated or corrupt).');
        }

        return $this->decodeRaw($json);
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

    /** @return list<Effect> */
    private static function effectsFromArray(mixed $effects): array
    {
        if (!is_array($effects)) {
            throw new CassetteCodecException('Cassette "effects" must be a JSON array.');
        }

        return array_values(array_map(self::effectFromArray(...), $effects));
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
