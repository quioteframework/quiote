<?php

declare(strict_types=1);

namespace Quiote\DevOverlay\Report;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Logging\Log;
use Quiote\Support\Clock\ClockInterface;
use SplFileObject;
use Throwable;

/**
 * Turns a caught exception into the JSON-ready report the overlay renders: the request it came
 * from, and every exception in its `getPrevious()` chain with its throw site, a source excerpt and
 * its stack frames.
 *
 * Frames are listed by location, each labelled with the function that location sits in -- the
 * reading a developer expects, and not PHP's own `getTrace()` shape, where each entry's
 * `function` is the one *called* from that location. Argument values are never included: they
 * are the one part of a trace that routinely carries credentials.
 *
 * @phpstan-type SourceExcerpt array{start: int, highlight: int, lines: list<string>}
 * @phpstan-type Frame array{file: ?string, line: ?int, call: string, vendor: bool, source: ?SourceExcerpt}
 * @phpstan-type ExceptionEntry array{class: string, message: string, code: int|string, file: string, line: int, source: ?SourceExcerpt, frames: list<Frame>}
 * @phpstan-type ExceptionReport array{id: string, time: string, status: int, request: array{method: string, uri: string}, exceptions: list<ExceptionEntry>}
 */
final readonly class ExceptionReportBuilder
{
    /** How many lines either side of the highlighted one an excerpt carries. */
    private const int CONTEXT_LINES = 6;

    /** Files larger than this are not read for an excerpt; a minified or generated file is not worth showing. */
    private const int MAX_SOURCE_BYTES = 2_097_152;

    public function __construct(
        private ClockInterface $clock,
        private int $maxFrames = 60,
        private int $maxSourceFrames = 12,
        private int $maxChain = 10,
    ) {
    }

    /** @return ExceptionReport */
    public function build(string $id, Throwable $exception, ServerRequestInterface $request, int $status): array
    {
        $entries = [];
        for ($current = $exception; $current !== null && count($entries) < $this->maxChain; $current = $current->getPrevious()) {
            $entries[] = $this->entry($current);
        }

        return [
            'id' => $id,
            'time' => $this->clock->now()->format(DATE_ATOM),
            'status' => $status,
            'request' => [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ],
            'exceptions' => $entries,
        ];
    }

    /** @return ExceptionEntry */
    private function entry(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'source' => $this->excerpt($exception->getFile(), $exception->getLine()),
            'frames' => $this->frames($exception),
        ];
    }

    /** @return list<Frame> */
    private function frames(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $locations = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
        foreach ($trace as $entry) {
            $locations[] = ['file' => $entry['file'] ?? null, 'line' => $entry['line'] ?? null];
        }

        $frames = [];
        $withSource = 0;
        foreach (array_slice($locations, 0, $this->maxFrames) as $index => $location) {
            $file = $location['file'];
            $line = $location['line'];
            $vendor = $file !== null && self::isVendor($file);
            $source = null;
            if ($file !== null && $line !== null && !$vendor && $withSource < $this->maxSourceFrames) {
                $source = $this->excerpt($file, $line);
                $withSource++;
            }
            $frames[] = [
                'file' => $file,
                'line' => $line,
                'call' => self::callName($trace[$index] ?? null),
                'vendor' => $vendor,
                'source' => $source,
            ];
        }

        return $frames;
    }

    /** @param array{function: string, class?: class-string, type?: '->'|'::'}|null $entry */
    private static function callName(?array $entry): string
    {
        if ($entry === null) {
            return '{main}';
        }

        return isset($entry['class'], $entry['type'])
            ? $entry['class'] . $entry['type'] . $entry['function'] . '()'
            : $entry['function'] . '()';
    }

    private static function isVendor(string $file): bool
    {
        return str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /** @return ?SourceExcerpt */
    private function excerpt(string $file, int $line): ?array
    {
        if ($line < 1 || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $size = filesize($file);
        if ($size === false || $size > self::MAX_SOURCE_BYTES) {
            return null;
        }

        $start = max(1, $line - self::CONTEXT_LINES);
        try {
            $reader = new SplFileObject($file);
            $reader->seek($start - 1);
            $lines = [];
            while (!$reader->eof() && count($lines) < self::CONTEXT_LINES * 2 + 1) {
                $current = $reader->current();
                $lines[] = rtrim(is_string($current) ? $current : '', "\r\n");
                $reader->next();
            }
        } catch (Throwable $e) {
            // The excerpt is decoration; the frame itself still reports file and line without it.
            Log::for($this)->debug(sprintf('Could not read a source excerpt from %s: %s', $file, $e->getMessage()));

            return null;
        }

        return $lines === [] ? null : ['start' => $start, 'highlight' => $line, 'lines' => $lines];
    }
}
