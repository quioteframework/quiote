<?php
namespace Agavi\Util;

/**
 * Central helper to reduce noise from repetitive deprecation/notice messages during test runs.
 * Emits a given message only once per PHP process unless AGAVI_DEPRECATION_VERBOSE=1 is set.
 */
final class DeprecationSilencer
{
    private static array $emitted = [];

    public static function triggerOnce(string $message, int $level = E_USER_DEPRECATED): void
    {
        if(getenv('AGAVI_DEPRECATION_VERBOSE')) {
            @trigger_error($message, $level); return;
        }
        if(isset(self::$emitted[$level][$message])) { return; }
        self::$emitted[$level][$message] = true;
        @trigger_error($message, $level);
    }
}
