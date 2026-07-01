<?php

namespace Agavi\Logging\Sink;

use Agavi\Logging\Level;

/**
 * Base for sinks that write one line per event to a stream. Handles the
 * per-sink minimum level (+ optional per-category overrides, longest-prefix
 * wins) and lazy stream opening. Subclasses implement {@see format()}.
 */
abstract class AbstractStreamSink implements SinkInterface
{
    /** @var resource|null */
    private $handle = null;

    /** @var string|null Path to open lazily (null when a resource was supplied). */
    private ?string $path;

    /** @var array<string,Level> memoized resolved min level per exact category */
    private array $resolvedMin = [];

    /**
     * @param Level $minLevel Minimum level this sink accepts by default.
     * @param array<string,Level> $categoryOverrides category-prefix => min level.
     * @param string|resource $stream A stream path (opened lazily, append) or an
     *        already-open writable resource (e.g. for tests).
     */
    public function __construct(
        private readonly Level $minLevel = Level::Debug,
        private readonly array $categoryOverrides = [],
        string $stream = 'php://stdout',
        $streamResource = null,
    ) {
        if (is_resource($streamResource)) {
            $this->handle = $streamResource;
            $this->path = null;
        } else {
            $this->path = $stream;
        }
    }

    public function isEnabled(Level $level, string $category): bool
    {
        return $level->passes($this->resolveMin($category));
    }

    private function resolveMin(string $category): Level
    {
        if (isset($this->resolvedMin[$category])) {
            return $this->resolvedMin[$category];
        }
        $best = null;
        $bestLen = -1;
        foreach ($this->categoryOverrides as $prefix => $level) {
            if ($category === $prefix || str_starts_with($category, $prefix . '.')) {
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $level;
                }
            }
        }
        return $this->resolvedMin[$category] = $best ?? $this->minLevel;
    }

    public function emit(\Agavi\Logging\LogEvent $event): void
    {
        $this->writeLine($this->format($event));
    }

    /**
     * Render an event to a single line (no trailing newline).
     */
    abstract protected function format(\Agavi\Logging\LogEvent $event): string;

    protected function writeLine(string $line): void
    {
        $h = $this->handle();
        if ($h !== null) {
            @fwrite($h, $line . "\n");
        }
    }

    /** @return resource|null */
    private function handle()
    {
        if ($this->handle === null && $this->path !== null) {
            $this->handle = @fopen($this->path, 'a');
        }
        return $this->handle ?: null;
    }

    public function flush(): void
    {
        if (is_resource($this->handle)) {
            @fflush($this->handle);
        }
    }

    /**
     * Format a UNIX timestamp (with microseconds) as ISO-8601 UTC with
     * milliseconds, e.g. 2026-07-01T08:02:55.123Z.
     */
    protected static function formatTimestamp(float $ts): string
    {
        $sec = (int) floor($ts);
        $ms = (int) round(($ts - $sec) * 1000);
        if ($ms >= 1000) {
            $sec += 1;
            $ms -= 1000;
        }
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
    }
}
