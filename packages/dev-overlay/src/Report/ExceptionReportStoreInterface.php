<?php

declare(strict_types=1);

namespace Quiote\DevOverlay\Report;

/**
 * Where a report waits between the request that produced it and the overlay's follow-up fetch.
 *
 * The two are always separate requests -- usually separate PHP processes -- so the store has to
 * outlive the request. It only has to hold the recent past: a developer's browser asks for a
 * report within moments of the response that announced it.
 *
 * @phpstan-import-type ExceptionReport from ExceptionReportBuilder
 */
interface ExceptionReportStoreInterface
{
    /**
     * @param ExceptionReport $report
     * @throws \RuntimeException When the report cannot be kept.
     */
    public function put(string $id, array $report): void;

    /**
     * The report stored under `$id`, or null when there is none -- including when `$id` is not a
     * well-formed report id at all.
     *
     * @return ?array<string, mixed>
     */
    public function get(string $id): ?array;
}
