<?php

namespace Quiote\Telemetry;

/**
 * A single unit of work in a trace. Every mutator returns $this so call sites
 * can chain; {@see end()} is idempotent — safe to call more than once (e.g. once
 * explicitly in a `finally` block and again implicitly via a wrapping scope
 * guard).
 */
interface SpanHandle
{
    /**
     * Renames the span (e.g. once route matching resolves the root request
     * span's low-cardinality identity — docs/OPENTELEMETRY_PLAN.md, Phase 6).
     */
    public function updateName(string $name): static;

    public function setAttribute(string $key, mixed $value): static;

    /** @param array<string,mixed> $attributes */
    public function setAttributes(array $attributes): static;

    /** @param array<string,mixed> $attributes */
    public function addEvent(string $name, array $attributes = []): static;

    public function recordException(\Throwable $e): static;

    public function setStatusError(?string $description = null): static;

    public function end(): void;

    /**
     * The span's trace ID (32 lowercase hex chars), or null for a no-op span
     * or one with no valid context. IDs exist regardless of the sampling
     * decision (docs/OPENTELEMETRY_PLAN.md, Phase 7) — a dropped/unsampled
     * span still has a real trace ID, just nothing exported for it.
     */
    public function traceId(): ?string;

    /** The span's own span ID (16 lowercase hex chars), or null — see {@see traceId()}. */
    public function spanId(): ?string;
}
