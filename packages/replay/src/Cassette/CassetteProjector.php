<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

/**
 * Turns a decoded {@see Cassette} into the flat, section-keyed shape both `cassette:show` and any
 * other reader (an MCP capability, a future web view) present: request/response bodies excerpted
 * to length + sha256 by default, and an effect's captured rows excerpted to a count, so a 2 MiB
 * cassette or a query returning thousands of rows doesn't become that much output by accident.
 * `$includeBodies` is the one switch that turns both excerpts back into their full content.
 */
final class CassetteProjector
{
    /** @return array<string, mixed> */
    public static function project(Cassette $cassette, bool $includeBodies): array
    {
        return [
            'meta' => $cassette->meta,
            'request' => self::projectSection($cassette->request, $includeBodies),
            'resolved' => $cassette->resolved,
            'session' => $cassette->session,
            'user' => $cassette->user,
            'effects' => array_map(static fn(Effect $effect): array => self::effectToArray($effect, $includeBodies), $cassette->effects),
            'response' => self::projectSection($cassette->response, $includeBodies),
            'exception' => $cassette->exception,
            'log' => $cassette->log,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function projectSection(array $section, bool $includeBodies): array
    {
        if ($includeBodies) {
            return $section;
        }

        $body = $section['body'] ?? null;
        if (!is_array($body) || !isset($body['content']) || !is_string($body['content'])) {
            return $section;
        }

        $content = $body['content'];
        $section['body'] = [
            'encoding' => $body['encoding'] ?? null,
            'length' => strlen($content),
            'sha256' => hash('sha256', $content),
            'truncated' => $body['truncated'] ?? false,
        ];

        return $section;
    }

    /**
     * `call` (sql/source/bound_params, for a DB effect) is always shown in full: it's small and
     * already redacted at capture time by whichever driver-specific recorder produced it, unlike
     * a request/response body. `result`'s captured rows are the one part of an effect that can
     * genuinely be large -- a per-query cap, independent of `replay.max_effects` -- so those
     * follow the same excerpt-by-default rule {@see projectSection()} applies to bodies.
     *
     * @return array<string, mixed>
     */
    private static function effectToArray(Effect $effect, bool $includeBodies): array
    {
        $result = $effect->result;
        if (!$includeBodies && is_array($result) && is_array($result['rows'] ?? null)) {
            $result['rows'] = ['excerpted' => true, 'captured_row_count' => count($result['rows'])];
        }

        return [
            'seq' => $effect->seq,
            'kind' => $effect->kind->value,
            'fingerprint' => $effect->fingerprint,
            'call' => $effect->call,
            'result' => $result,
            'duration_us' => $effect->durationMicros,
        ];
    }
}
