<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

/**
 * The full record of one request, as described by
 * `docs/RECORD_REPLAY_PLAN.md` §4.1. `meta`, `request` and `response` are the
 * only sections a cassette must carry; every other section is optional and
 * empty/null when not captured, so a minimal cassette stays small.
 *
 * A plain value object rather than one with sub-value-objects per section:
 * {@see \Quiote\Replay\Cassette\CassetteCodec} is the only reader/writer, and
 * splitting `request`/`response`/`resolved` into their own classes would add
 * indirection with no consumer that needs it yet.
 */
final readonly class Cassette
{
    /**
     * @param non-negative-int $schemaVersion
     * @param array<string, mixed> $meta {id, recorded_at, quiote_version, php_version, context,
     *        source_hash, runtime, trace_id, span_id, trigger}
     * @param array<string, mixed> $request {method, uri, protocol, headers, cookies, body,
     *        uploads, server}
     * @param array<string, mixed> $resolved {route, module, action, route_params, output_type,
     *        validated_params, validation_report}
     * @param array<string, mixed>|null $session {before, after, id_rotated}
     * @param array<string, mixed>|null $user {authenticated, identity, roles}
     * @param list<Effect> $effects
     * @param array<string, mixed> $response {status, headers, body, stray_output}
     * @param array<string, mixed>|null $exception {class, message, file, line, trace}
     * @param list<mixed>|null $log
     */
    public function __construct(
        public int $schemaVersion,
        public array $meta,
        public array $request,
        public array $resolved,
        public ?array $session,
        public ?array $user,
        public array $effects,
        public array $response,
        public ?array $exception,
        public ?array $log,
    ) {
    }
}
