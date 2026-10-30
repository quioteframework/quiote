<?php

declare(strict_types=1);

namespace Quiote\DevOverlay\Report;

use JsonException;
use Quiote\Logging\Log;
use RuntimeException;

/**
 * Keeps each report as one JSON file in a directory, and only the newest `$maxReports` of them.
 *
 * A file per report needs no locking: ids are random, a write lands under a temporary name and is
 * renamed into place, and a reader that loses a race with pruning simply finds nothing. Ids are
 * checked against {@see ID_PATTERN} before they are ever turned into a path, since they arrive in
 * a URL.
 *
 * @phpstan-import-type ExceptionReport from ExceptionReportBuilder
 */
final readonly class FileExceptionReportStore implements ExceptionReportStoreInterface
{
    public const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private string $directory,
        private int $maxReports = 50,
    ) {
    }

    public function put(string $id, array $report): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a valid exception report id.', $id));
        }
        $this->ensureDirectory();

        try {
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Exception report %s could not be encoded: %s', $id, $e->getMessage()), 0, $e);
        }

        $target = $this->pathFor($id);
        $temporary = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $json) === false || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Exception report %s could not be written to %s.', $id, $this->directory));
        }

        $this->prune();
    }

    public function get(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        try {
            $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::for($this)->warning(sprintf('Exception report %s is not valid JSON and was ignored: %s', $id, $e->getMessage()));

            return null;
        }

        if (!is_array($report)) {
            return null;
        }

        /** @var array<string, mixed> $report */
        return $report;
    }

    private function pathFor(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Exception report directory %s could not be created.', $this->directory));
        }
    }

    /** Drops the oldest reports beyond `$maxReports`. A report another process already removed is not an error. */
    private function prune(): void
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false || count($files) <= $this->maxReports) {
            return;
        }

        $modified = [];
        foreach ($files as $file) {
            $modified[$file] = @filemtime($file) ?: 0;
        }
        asort($modified);

        $logger = null;
        foreach (array_slice(array_keys($modified), 0, count($files) - $this->maxReports) as $file) {
            if (!@unlink($file) && is_file($file)) {
                $logger ??= Log::for($this);
                $logger->warning(sprintf('Old exception report %s could not be removed.', $file));
            }
        }
    }
}
