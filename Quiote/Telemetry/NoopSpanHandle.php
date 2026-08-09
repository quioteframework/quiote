<?php

namespace Quiote\Telemetry;

/**
 * The disabled-state {@see SpanHandle}: every call is a safe no-op. A single
 * shared instance is reused ({@see instance()}) so instrumenting a call site
 * costs no allocation whether telemetry is globally off, a trace category is
 * filtered out, or no real tracer has been wired up yet.
 */
final class NoopSpanHandle implements SpanHandle
{
    private static ?self $instance = null;

    /**
     * The shared no-op span handle, created on first call and reused for the
     * rest of the process. Safe to hand out freely: it is stateless, owns no
     * span lifecycle, and every call on it is discarded.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Discards the new name and returns $this. */
    public function updateName(string $name): static
    {
        return $this;
    }

    /** Discards the attribute and returns $this. */
    public function setAttribute(string $key, mixed $value): static
    {
        return $this;
    }

    /** Discards the attributes and returns $this. */
    public function setAttributes(array $attributes): static
    {
        return $this;
    }

    /** Discards the event and returns $this. */
    public function addEvent(string $name, array $attributes = []): static
    {
        return $this;
    }

    /** Discards the exception and returns $this; nothing is exported. */
    public function recordException(\Throwable $e): static
    {
        return $this;
    }

    /** Discards the error status and returns $this. */
    public function setStatusError(?string $description = null): static
    {
        return $this;
    }

    /** Does nothing; there is no span to end. */
    public function end(): void
    {
    }

    /** Always null — a no-op span has no trace context. */
    public function traceId(): ?string
    {
        return null;
    }

    /** Always null — a no-op span has no trace context. */
    public function spanId(): ?string
    {
        return null;
    }
}
