<?php

namespace Quiote\Telemetry\Dashboard;

use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Pure state -> widget-tree builder for the dashboard's live view: no I/O, no
 * `symfony/tui` runtime calls (`Tui::add()`/`requestRender()` etc.) -- only
 * this class and {@see TelemetryDashboardCommand} touch `Symfony\Component\Tui\*`
 * directly, containing the experimental package's surface to two files. Being
 * pure also makes it trivially testable: feed it a `DashboardSnapshot`,
 * assert on the text the returned widget tree renders, no terminal required.
 *
 * `symfony/tui` has no Chart/Sparkline/Gauge/Table widgets -- {@see Spark}
 * and {@see Bars} stand in for the first two, and route/recent-request rows
 * are hand-aligned text for the third. Every string that ultimately
 * originates from telemetry data (span names, status messages, route labels)
 * is passed through {@see TextSanitizer} before reaching a `TextWidget`,
 * since that widget renders raw ANSI passthrough and an instrumented app's
 * telemetry export is not a trusted input source.
 */
final class DashboardView
{
    private const BAR_WIDTH = 16;
    private const WORKER_RSS_CEILING_BYTES = 256 * 1024 * 1024;
    private const MEMORY_PEAK_CEILING_BYTES = 32 * 1024 * 1024;

    public static function build(DashboardSnapshot $snapshot, string $serviceName, string $listeningAddress): ContainerWidget
    {
        $root = new ContainerWidget();
        $root->setStyle(new Style(direction: Direction::Vertical, gap: 1, border: Border::from([1]), padding: Padding::from([1])));
        // Explicit, not left to ChartWidget's fill-ness propagating up through
        // ContainerWidget ancestors (see chartPanel()'s docblock) -- that
        // propagation only exists once $snapshot->hasData, since the "waiting
        // for telemetry" branch below has no ChartWidget descendant at all.
        // Without this, the box would only grow to fill the terminal once
        // the first span arrived, not on startup.
        $root->expandVertically(true);

        $root->add(self::header($snapshot, $serviceName));

        if (!$snapshot->hasData) {
            $root->add(self::waitingPanel($listeningAddress));
            $root->add(self::footer($listeningAddress));

            return $root;
        }

        $root->add(self::seriesRow($snapshot));
        $root->add(self::resourceRow($snapshot));
        $root->add(self::routeTable($snapshot));
        $root->add(self::recentFeed($snapshot));
        $root->add(self::footer($listeningAddress));

        return $root;
    }

    private static function header(DashboardSnapshot $snapshot, string $serviceName): TextWidget
    {
        $text = new TextWidget(sprintf(
            'quiote · telemetry:dashboard — %s   uptime %s   %d req   %.1f req/s   err %s',
            TextSanitizer::sanitize($serviceName),
            self::formatDuration($snapshot->uptimeSeconds),
            $snapshot->totalRequests,
            $snapshot->requestsPerSecond,
            self::formatPercent($snapshot->errorRate),
        ));
        $text->setStyle(new Style(bold: true));

        return $text;
    }

    private static function waitingPanel(string $listeningAddress): TextWidget
    {
        return new TextWidget(sprintf('Waiting for telemetry... point telemetry.otlp.endpoint at %s', $listeningAddress));
    }

    private static function seriesRow(DashboardSnapshot $snapshot): ContainerWidget
    {
        $row = new ContainerWidget();
        $row->setStyle(new Style(direction: Direction::Horizontal, gap: 2));

        $row->add(self::chartPanel(
            sprintf('Throughput (req/s, %ds)', count($snapshot->throughputSeries)),
            $snapshot->throughputSeries,
        ));
        $row->add(self::chartPanel(
            'Latency (ms)',
            $snapshot->latencySeries,
            sprintf('avg %.1f  p95 %.1f  max %.1f', $snapshot->avgLatencyMs, $snapshot->p95LatencyMs, $snapshot->maxLatencyMs),
        ));

        return $row;
    }

    /**
     * A labeled chart panel: header, a {@see ChartWidget} that fills whatever
     * vertical space is left over once every non-expanding sibling in the
     * tree (header row, resource gauges, route table, recent feed, footer)
     * has been measured -- and, by extension since `ContainerWidget::
     * isVerticallyExpanded()` propagates "does any child need to expand"
     * up through its ancestors, this is also what makes the *entire*
     * dashboard box grow to fill the terminal on startup and react live to
     * resizes, with zero explicit `expandVertically()` calls needed anywhere
     * in this class -- `ChartWidget` is the only descendant that actually
     * declares it, and the fill-ness propagates upward through every plain
     * `ContainerWidget` ancestor automatically.
     *
     * @param float[] $values
     */
    private static function chartPanel(string $label, array $values, ?string $footerStats = null): ContainerWidget
    {
        $panel = new ContainerWidget();
        $panel->setStyle(new Style(direction: Direction::Vertical, flex: 1));

        $header = new TextWidget($label);
        $header->setStyle(new Style(bold: true));
        $panel->add($header);
        $panel->add(new ChartWidget($values));

        if ($footerStats !== null) {
            $panel->add(new TextWidget($footerStats));
        }

        return $panel;
    }

    private static function resourceRow(DashboardSnapshot $snapshot): ContainerWidget
    {
        $row = new ContainerWidget();
        $row->setStyle(new Style(direction: Direction::Horizontal, gap: 2));

        $rssRatio = Bars::ratio($snapshot->workerRssBytes, self::WORKER_RSS_CEILING_BYTES);
        $rss = new TextWidget(sprintf(
            'Worker RSS  [%s] %s',
            Bars::render($snapshot->workerRssBytes, self::WORKER_RSS_CEILING_BYTES, self::BAR_WIDTH),
            self::formatBytes($snapshot->workerRssBytes),
        ));
        $rss->setStyle(new Style(color: self::colorForRatio($rssRatio), flex: 1));

        $cpu = new TextWidget(sprintf('CPU/req   user %.2fms  sys %.2fms', $snapshot->cpuUserMs, $snapshot->cpuSystemMs));
        $cpu->setStyle(new Style(flex: 1));

        $memRatio = Bars::ratio($snapshot->memoryPeakBytes, self::MEMORY_PEAK_CEILING_BYTES);
        $mem = new TextWidget(sprintf(
            'Mem peak    [%s] %s',
            Bars::render($snapshot->memoryPeakBytes, self::MEMORY_PEAK_CEILING_BYTES, self::BAR_WIDTH),
            self::formatBytes($snapshot->memoryPeakBytes),
        ));
        $mem->setStyle(new Style(color: self::colorForRatio($memRatio), flex: 1));

        $errRatio = min(1.0, $snapshot->errorRate / 0.10);
        $err = new TextWidget(sprintf(
            'Error rate  [%s] %s',
            Bars::render($snapshot->errorRate, 0.10, self::BAR_WIDTH),
            self::formatPercent($snapshot->errorRate),
        ));
        $err->setStyle(new Style(color: self::colorForErrorRatio($errRatio), flex: 1));

        $row->add($rss)->add($cpu)->add($mem)->add($err);

        return $row;
    }

    private static function routeTable(DashboardSnapshot $snapshot): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical));

        $header = new TextWidget(self::padColumns(['Route', 'reqs', 'avg ms', 'err%', 'cache%'], [40, 8, 8, 8, 8]));
        $header->setStyle(new Style(bold: true));
        $container->add($header);

        foreach ($snapshot->routeRows as $row) {
            $line = new TextWidget(self::padColumns([
                TextSanitizer::sanitize((string) $row['route']),
                (string) $row['count'],
                number_format($row['avgMs'], 1),
                self::formatPercent($row['errorRate']),
                self::formatPercent($row['cacheHitRate']),
            ], [40, 8, 8, 8, 8]));
            if ($row['errorRate'] > 0.0) {
                $line->setStyle(new Style(color: Color::from('red')));
            }
            $container->add($line);
        }

        return $container;
    }

    private static function recentFeed(DashboardSnapshot $snapshot): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical));

        $header = new TextWidget('Recent');
        $header->setStyle(new Style(bold: true));
        $container->add($header);

        foreach (array_slice($snapshot->recentErrors, 0, 5) as $entry) {
            $line = new TextWidget(sprintf(
                '%s  %s  %s  %.1fms  %s',
                self::formatClockTime($entry['second']),
                TextSanitizer::sanitize((string) $entry['name']),
                self::formatStatusCode($entry['statusCode'], $entry['isError']),
                $entry['durationMs'],
                TextSanitizer::sanitize((string) $entry['statusMessage']),
            ));
            $line->setStyle(new Style(color: Color::from('red')));
            $container->add($line);
        }

        foreach (array_slice($snapshot->recentSpans, 0, 5) as $entry) {
            if ($entry['isError']) {
                continue;
            }
            $container->add(new TextWidget(sprintf(
                '%s  %s  %s  %.1fms',
                self::formatClockTime($entry['second']),
                TextSanitizer::sanitize((string) $entry['name']),
                self::formatStatusCode($entry['statusCode'], $entry['isError']),
                $entry['durationMs'],
            )));
        }

        return $container;
    }

    private static function footer(string $listeningAddress): TextWidget
    {
        $footer = new TextWidget(sprintf('[q]uit  [c]lear   listening %s', $listeningAddress));
        $footer->setStyle(new Style(dim: true));

        return $footer;
    }

    /**
     * @param string[] $columns
     * @param int[] $widths
     */
    private static function padColumns(array $columns, array $widths): string
    {
        $parts = [];
        foreach ($columns as $i => $column) {
            $width = $widths[$i] ?? 10;
            $parts[] = self::padTo($column, $width);
        }

        return implode(' ', $parts);
    }

    private static function padTo(string $text, int $width): string
    {
        $length = mb_strlen($text);
        if ($length >= $width) {
            return mb_substr($text, 0, max(0, $width - 1)) . ($width > 0 ? '…' : '');
        }

        return $text . str_repeat(' ', $width - $length);
    }

    private static function colorForRatio(float $ratio): Color
    {
        return match (true) {
            $ratio >= 0.9 => Color::from('red'),
            $ratio >= 0.7 => Color::from('yellow'),
            default => Color::from('green'),
        };
    }

    private static function colorForErrorRatio(float $ratio): Color
    {
        return match (true) {
            $ratio >= 0.5 => Color::from('red'),
            $ratio >= 0.1 => Color::from('yellow'),
            default => Color::from('green'),
        };
    }

    private static function formatPercent(float $ratio): string
    {
        return number_format($ratio * 100, 1) . '%';
    }

    private static function formatBytes(float $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2) . 'GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . 'KB';
        }

        return number_format($bytes, 0) . 'B';
    }

    private static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    private static function formatClockTime(int $unixSecond): string
    {
        return gmdate('H:i:s', $unixSecond);
    }

    /**
     * `http.response.status_code` is genuinely absent on the root span for
     * an unhandled exception (`TelemetryMiddleware` records Error status via
     * `recordException()`/`setStatusError()` before any response exists to
     * read a status code from) -- rendering the raw `0` there reads as a
     * wrong/successful status rather than "no code was ever recorded".
     * Confirmed against the real sample app's `/boom` action, not
     * hypothesized: a live end-to-end run showed exactly this `0`.
     */
    private static function formatStatusCode(int $statusCode, bool $isError): string
    {
        if ($statusCode === 0 && $isError) {
            return 'ERR';
        }

        return (string) $statusCode;
    }
}
