<?php

namespace Quiote\Logging\Sink;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;

/**
 * Human-readable single-line sink for local development. Writes to any stream
 * (stderr by default). Not intended for structured log aggregation — use
 * {@see JsonStdoutSink} in containers.
 *   2026-07-01T08:02:55.123Z WARNING Quiote.Routing: no route matched /foo {rid=abc}
 */
class TextStreamSink extends AbstractStreamSink
{
    /**
     * @param array<string,Level> $categoryOverrides
     */
    public function __construct(
        string $stream = 'php://stderr',
        Level $minLevel = Level::Debug,
        array $categoryOverrides = [],
        $streamResource = null,
    ) {
        parent::__construct($minLevel, $categoryOverrides, $stream, $streamResource);
    }

    protected function format(LogEvent $event): string
    {
        return self::formatPlainLine($event);
    }
}
