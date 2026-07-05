<?php

namespace Quiote\Logging;

/**
 * Ambient, stack-based logging context (Serilog LogContext / .NET BeginScope).
 * Properties pushed here are merged into every {@see LogEvent} emitted while the
 * scope is active — e.g. the request correlation id, the authenticated user id.
 * WORKER-MODE SAFETY: the stack is process-global. In a FrankenPHP worker the
 * process is long-lived, so a scope left on the stack would leak one request's
 * properties into the next request's logs (a cross-request data leak, same class
 * as the session-id leak). {@see clear()} MUST be called between requests — it is
 * wired into Context::reset().
 */
final class LogContext
{
    /** @var array<int,array<string,mixed>> Active frames keyed by monotonically increasing id. */
    private static array $frames = [];

    private static int $nextId = 0;

    /**
     * Memoized merge of all active frames; null when dirty.
     * @var array<string,mixed>|null
     */
    private static ?array $merged = null;

    /**
     * Push a set of properties. Hold the returned token and close it (or let it
     * go out of scope) to pop exactly this frame — order-independent, so nested
     * and overlapping scopes both behave.
     * @param array<string,mixed> $properties
     */
    public static function push(array $properties): ScopeToken
    {
        $id = self::$nextId++;
        self::$frames[$id] = $properties;
        self::$merged = null;
        return new ScopeToken($id);
    }

    /**
     * Push properties for the remainder of the request with NO token to hold —
     * the frame is removed only by {@see clear()} (worker reset). Use this for
     * request-lifetime enrichers such as the correlation id / user id, where
     * there is no natural block to scope to. Use {@see push()} (and hold the
     * token) for block-scoped context.
     * NB: `push()` without assigning its return value pops immediately (the
     * token is a temporary that is destroyed at end of statement) — use
     * `enrich()` when you deliberately want no token.
     * @param array<string,mixed> $properties
     */
    public static function enrich(array $properties): void
    {
        self::$frames[self::$nextId++] = $properties;
        self::$merged = null;
    }

    /**
     * @internal Called by ScopeToken to pop its frame.
     */
    public static function pop(int $id): void
    {
        if (array_key_exists($id, self::$frames)) {
            unset(self::$frames[$id]);
            self::$merged = null;
        }
    }

    /**
     * The merged property set of all active frames, later pushes winning on key
     * collisions. Memoized until the frame set changes.
     * @return array<string,mixed>
     */
    public static function current(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        $merged = [];
        foreach (self::$frames as $frame) {
            $merged = [...$merged, ...$frame];
        }
        return self::$merged = $merged;
    }

    public static function isEmpty(): bool
    {
        return self::$frames === [];
    }

    /**
     * Drop all scopes. Call between requests in worker mode (Context::reset()).
     */
    public static function clear(): void
    {
        self::$frames = [];
        self::$merged = null;
        // Note: $nextId intentionally NOT reset — keeps ids monotonic so any
        // still-held stale token cannot pop a future frame.
    }
}
