<?php

namespace Quiote\Logging\Sink;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;

/**
 * Plain-text sink that appends one line per event to a file on disk, creating
 * the parent directory if it doesn't exist yet (AbstractStreamSink's lazy
 * fopen() would otherwise fail silently against a missing directory). Same
 * line format as {@see TextStreamSink} — deliberately never colorized, since
 * a log file is read by more than terminals (tail | grep, log shippers, etc.)
 * and ANSI escape codes would just be noise there. For a colorized terminal
 * sink use {@see AnsiTextStreamSink}.
 */
final class FileSink extends AbstractStreamSink
{
    /**
     * @param string $path Filesystem path to append to.
     * @param array<string,Level> $categoryOverrides
     * @param resource|null $streamResource Pre-opened resource, for tests —
     *        when supplied, $path is never touched and no directory is created.
     */
    public function __construct(
        string $path,
        Level $minLevel = Level::Debug,
        array $categoryOverrides = [],
        $streamResource = null,
    ) {
        if ($streamResource === null) {
            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        parent::__construct($minLevel, $categoryOverrides, $path, $streamResource);
    }

    protected function format(LogEvent $event): string
    {
        return self::formatPlainLine($event);
    }
}
