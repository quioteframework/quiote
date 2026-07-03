<?php

namespace Quiote\Logging\Sink;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;

/**
 * AnsiTextStreamSink with an emoji prefix per level, for local dev consoles
 * where a quick visual scan matters more than a clean tail | grep. Not meant
 * for anything that parses log output — use TextStreamSink/FileSink/
 * JsonStdoutSink for that.
 *   🔍 2026-07-01T08:02:55.123Z DEBUG ...
 *   ⚠️ 2026-07-01T08:02:55.123Z WARNING ...
 *   🔥 2026-07-01T08:02:55.123Z ERROR ...
 */
final class EmojiTextStreamSink extends AnsiTextStreamSink
{
    protected function format(LogEvent $event): string
    {
        return self::emojiFor($event->level) . ' ' . parent::format($event);
    }

    private static function emojiFor(Level $level): string
    {
        return match (true) {
            $level->value >= Level::Emergency->value => '💀',
            $level->value >= Level::Alert->value     => '🚨',
            $level->value >= Level::Critical->value  => '🔥',
            $level->value >= Level::Error->value     => '‼️',
            $level->value >= Level::Warning->value   => '⚠️',
            $level->value >= Level::Notice->value    => '📝',
            $level->value >= Level::Info->value      => 'ℹ️',
            $level->value >= Level::Debug->value     => '🪲',
            default                                   => '🔎', // Trace
        };
    }
}
