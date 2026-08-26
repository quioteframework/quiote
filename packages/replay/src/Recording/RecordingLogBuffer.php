<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

/**
 * Stack-based holder of the log entries buffered for whichever request is
 * currently being recorded, mirroring {@see \Quiote\Logging\LogContext}'s
 * frame-stack shape for the same worker-mode reason: the process is
 * long-lived, so this state must be pushed at recording start and popped at
 * recording end rather than living on any shared instance.
 *
 * {@see RecordingLogSink} is the sole writer (registered once, at boot, by
 * {@see \Quiote\Replay\ReplayPlugin}); {@see RecorderMiddleware} is the sole
 * caller of {@see start()}/{@see finish()}.
 */
final class RecordingLogBuffer
{
    /** @var array<int, list<array<string, mixed>>> */
    private static array $frames = [];

    /** @var array<int, int> */
    private static array $caps = [];

    /** @var array<int, bool> */
    private static array $truncated = [];

    private static int $nextId = 0;

    /** Starts a new frame bounded to at most $maxEntries, and returns its id. */
    public static function start(int $maxEntries): int
    {
        $id = self::$nextId++;
        self::$frames[$id] = [];
        self::$caps[$id] = max(0, $maxEntries);
        self::$truncated[$id] = false;

        return $id;
    }

    /**
     * Whether any frame is currently active -- the cheap gate
     * {@see RecordingLogSink::isEnabled()} calls on every log statement, so an
     * app with `replay.enabled` off (no frame ever started) pays one comparison.
     */
    public static function isActive(): bool
    {
        return self::$frames !== [];
    }

    /**
     * Appends one entry to every active frame, dropping it (and marking that
     * frame truncated) once a frame is at its own cap -- so one huge chatty
     * request cannot cost another concurrently-recording request its own budget.
     *
     * @param array<string, mixed> $entry
     */
    public static function record(array $entry): void
    {
        foreach (self::$frames as $id => $frame) {
            if (count($frame) >= self::$caps[$id]) {
                self::$truncated[$id] = true;
                continue;
            }
            self::$frames[$id][] = $entry;
        }
    }

    /**
     * Ends the frame $id started, returning its entries and whether it dropped
     * any to its cap, and discarding its state either way.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    public static function finish(int $id): array
    {
        $frame = self::$frames[$id] ?? [];
        $truncated = self::$truncated[$id] ?? false;
        unset(self::$frames[$id], self::$caps[$id], self::$truncated[$id]);

        return [$frame, $truncated];
    }

    /**
     * Drops every frame. For test isolation and worker reset; not used on the
     * request path -- {@see start()}/{@see finish()} already pair up on both of
     * {@see RecorderMiddleware::process()}'s exit paths.
     */
    public static function reset(): void
    {
        self::$frames = [];
        self::$caps = [];
        self::$truncated = [];
    }
}
