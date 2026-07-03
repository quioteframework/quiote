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

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function updateName(string $name): static
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        return $this;
    }

    public function recordException(\Throwable $e): static
    {
        return $this;
    }

    public function setStatusError(?string $description = null): static
    {
        return $this;
    }

    public function end(): void
    {
    }

    public function traceId(): ?string
    {
        return null;
    }

    public function spanId(): ?string
    {
        return null;
    }
}
