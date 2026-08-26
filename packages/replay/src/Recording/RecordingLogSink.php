<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;

/**
 * Feeds every log event emitted while a request is being recorded into
 * {@see RecordingLogBuffer}, so a cassette's `log` section reflects what the
 * application actually logged for that request rather than always being
 * `null`.
 *
 * Registered once, at boot, by {@see \Quiote\Replay\ReplayPlugin} -- same
 * lifetime as every other sink -- and gates on {@see RecordingLogBuffer::isActive()},
 * so an app with no recording in progress (`replay.enabled` off, or a request
 * {@see SamplingPolicy} declined) pays one static-array-emptiness check per
 * log statement and nothing else. Still subject to the category's own
 * configured minimum level: `CategoryLogger::log()` resolves that threshold
 * before consulting any sink, so this does not capture more than the app's
 * own logging configuration already allows through.
 */
final class RecordingLogSink implements SinkInterface
{
    #[\Override]
    public function isEnabled(Level $level, string $category): bool
    {
        return RecordingLogBuffer::isActive();
    }

    #[\Override]
    public function emit(LogEvent $event): void
    {
        RecordingLogBuffer::record([
            'timestamp' => $event->timestamp,
            'level' => $event->level->label(),
            'category' => $event->category,
            'message' => $event->renderMessage(),
        ]);
    }

    #[\Override]
    public function flush(): void
    {
    }
}
