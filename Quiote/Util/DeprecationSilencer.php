<?php
namespace Quiote\Util;

/**
 * Central helper to reduce noise from repetitive deprecation/notice messages during test runs.
 * Emits a given message only once per PHP process unless QUIOTE_DEPRECATION_VERBOSE=1 is set.
 */
final class DeprecationSilencer
{
    /** @var array<int,array<string,bool>> */
    private static array $emitted = [];

    /**
     * Emits $message through `trigger_error()` at most once per process for a given
     * message/level pair.
     *
     * Repeat calls with the same message and level are dropped. Setting the
     * `QUIOTE_DEPRECATION_VERBOSE` environment variable disables the de-duplication
     * so every call is emitted. The emitted-message table is static and never
     * pruned, so a message silenced once stays silenced for the worker's lifetime.
     */
    public static function triggerOnce(string $message, int $level = E_USER_DEPRECATED): void
    {
        if(getenv('QUIOTE_DEPRECATION_VERBOSE')) {
            @trigger_error($message, $level); return;
        }
        if(isset(self::$emitted[$level][$message])) { return; }
        self::$emitted[$level][$message] = true;
        @trigger_error($message, $level);
    }
}
