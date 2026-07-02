<?php

namespace Quiote\Logging\Sink;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;

/**
 * TextStreamSink that colors warning-and-above lines so they stand out in an
 * interactive terminal:
 *   yellow   = warning
 *   red      = error
 *   bold red = critical/alert/emergency
 * Debug/info/notice are left uncolored — the goal is making problems jump
 * out, not painting every line.
 *
 * Colors are auto-disabled when NO_COLOR is set (see https://no-color.org)
 * or the destination isn't a TTY (e.g. output redirected to a file or piped
 * into another program), so this is safe to use as a default dev-console
 * sink without leaking escape codes into redirected output. Pass $colors
 * explicitly to override the auto-detection.
 */
class AnsiTextStreamSink extends TextStreamSink
{
    private const RESET = "\033[0m";

    /**
     * @param array<string,Level> $categoryOverrides
     */
    public function __construct(
        string $stream = 'php://stderr',
        Level $minLevel = Level::Debug,
        array $categoryOverrides = [],
        $streamResource = null,
        private readonly ?bool $colors = null,
    ) {
        parent::__construct($stream, $minLevel, $categoryOverrides, $streamResource);
    }

    protected function format(LogEvent $event): string
    {
        $line = parent::format($event);
        $color = $this->colorFor($event->level);
        if ($color === '' || !$this->colorsEnabled()) {
            return $line;
        }
        return $color . $line . self::RESET;
    }

    protected function colorFor(Level $level): string
    {
        return match (true) {
            $level->value >= Level::Critical->value => "\033[1;31m", // bold red
            $level->value >= Level::Error->value    => "\033[31m",   // red
            $level->value >= Level::Warning->value  => "\033[33m",   // yellow
            default                                  => '',           // leave debug/info/notice plain
        };
    }

    private function colorsEnabled(): bool
    {
        if ($this->colors !== null) {
            return $this->colors;
        }
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        $handle = $this->handle();
        return $handle !== null && function_exists('stream_isatty') && @stream_isatty($handle);
    }
}
