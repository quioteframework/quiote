<?php

namespace Agavi\Logging\Sink;

use Agavi\Logging\Level;
use Agavi\Logging\LogEvent;

/**
 * Human-readable single-line sink for local development. Writes to any stream
 * (stderr by default). Not intended for structured log aggregation — use
 * {@see JsonStdoutSink} in containers.
 *
 *   2026-07-01T08:02:55.123Z WARNING Agavi.Routing: no route matched /foo {rid=abc}
 */
final class TextStreamSink extends AbstractStreamSink
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
        $line = sprintf(
            '%s %s %s: %s',
            self::formatTimestamp($event->timestamp),
            strtoupper($event->level->label()),
            $event->category,
            $event->renderMessage(),
        );

        $context = [...$event->scope, ...$event->properties];
        if ($context !== []) {
            $pairs = [];
            foreach ($context as $k => $v) {
                if ($v === null || is_scalar($v) || $v instanceof \Stringable) {
                    $pairs[] = $k . '=' . (string) $v;
                } else {
                    $pairs[] = $k . '=' . json_encode($v, JSON_UNESCAPED_SLASHES);
                }
            }
            $line .= ' {' . implode(', ', $pairs) . '}';
        }

        if ($event->exception !== null) {
            $e = $event->exception;
            $line .= sprintf(' | %s: %s @ %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        }

        return $line;
    }
}
