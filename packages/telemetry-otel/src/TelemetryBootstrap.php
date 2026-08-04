<?php

namespace Quiote\Telemetry;

use Quiote\Config\Config;
use Quiote\Logging\Log;

/**
 * Owns the telemetry lifecycle for a process: build the providers once, flush at each request
 * boundary, and shut down on exit. Called unconditionally from `Kernel::bootstrap()` -- this class
 * decides whether there is anything to do, so callers never need a feature-flag check of their own.
 *
 * Construction is delegated: {@see TelemetryConfig} resolves the settings,
 * {@see TelemetryExporterFactory} builds the exporters, and {@see TelemetryProviderFactory}
 * assembles the providers around them. What remains here is the part that genuinely has to be
 * process-wide -- "configured once", the registered shutdown function, and the handles the
 * registry hands out.
 *
 * Every path that can fail -- telemetry disabled, the open-telemetry/sdk package not installed, a
 * bad exporter or endpoint configuration -- degrades to "telemetry stays off" rather than throwing.
 * It is not a hard dependency.
 */
final class TelemetryBootstrap
{
    private static bool $configured = false;
    private static bool $shutdownRegistered = false;

    /**
     * The exporter factory from the most recent successful configuration, kept so the in-memory
     * exporter accessors below can answer after the fact.
     */
    private static ?TelemetryExporterFactory $exporters = null;

    private function __construct() {}

    /**
     * Build the providers from config. Idempotent: a second call (a second `Kernel::bootstrap()` in
     * the same process) is a no-op that reports whether a real provider is already active. Call
     * {@see reset()} first to force a rebuild, for test isolation or to simulate a fresh worker.
     *
     * @return     bool True if a real, usable provider is now wired up.
     */
    public static function configureFromConfig(): bool
    {
        if (self::$configured) {
            return TraceRegistry::hasRealProvider();
        }
        self::$configured = true;

        if (!Config::getBool('telemetry.enabled', false)) {
            return false;
        }

        if (!class_exists(\OpenTelemetry\SDK\Trace\TracerProviderBuilder::class)) {
            Log::for(self::class)->warning(
                'telemetry.enabled is true but the open-telemetry/sdk package is not installed; '
                . 'telemetry stays disabled.'
            );

            return false;
        }

        try {
            $config = TelemetryConfig::fromConfig();
            $exporters = new TelemetryExporterFactory($config);
            $providers = new TelemetryProviderFactory($config, $exporters);

            $resource = $providers->resource();
            TraceRegistry::setProviders($providers->tracerProvider($resource), $providers->meterProvider($resource));
            TraceRegistry::setEnabled(true);
            self::$exporters = $exporters;
            self::registerShutdown();

            return true;
        } catch (\Throwable $e) {
            Log::for(self::class)->error(
                '[TelemetryBootstrap] failed to configure telemetry, falling back to disabled: '
                . $e::class . ': ' . $e->getMessage()
            );
            TraceRegistry::setEnabled(false);

            return false;
        }
    }

    /**
     * Force-flush the active providers. Called at every worker request boundary (the Kernel's
     * post-request reset closure) so each request's spans and metrics are exported without tearing
     * the provider down. A no-op when telemetry is not configured.
     */
    public static function flushAfterRequest(): void
    {
        $tracerProvider = TraceRegistry::tracerProvider();
        $meterProvider = TraceRegistry::meterProvider();
        if ($tracerProvider === null && $meterProvider === null) {
            return;
        }
        try {
            $tracerProvider?->forceFlush();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] span flush failed: ' . $e::class . ': ' . $e->getMessage());
        }
        try {
            $meterProvider?->forceFlush();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] metric flush failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Final flush and shutdown. Registered once through `register_shutdown_function`, so single-shot
     * mode (no persistent worker loop, no per-request reset closure) still exports its one request's
     * telemetry before the process exits, and worker mode gets a last-chance flush when the worker
     * terminates.
     */
    public static function shutdown(): void
    {
        self::flushAfterRequest();
        try {
            TraceRegistry::tracerProvider()?->shutdown();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] tracer provider shutdown failed: ' . $e::class . ': ' . $e->getMessage());
        }
        try {
            TraceRegistry::meterProvider()?->shutdown();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] meter provider shutdown failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Reset all bootstrap and registry state, for test isolation or to simulate a fresh worker. Not
     * used on the request path.
     *
     * Cannot un-register a previously scheduled `register_shutdown_function` callback, and does not
     * need to: that callback re-reads {@see TraceRegistry} when the process actually exits, so it is
     * a safe no-op once this call has cleared the provider.
     */
    public static function reset(): void
    {
        self::$configured = false;
        self::$exporters = null;
        TraceRegistry::reset();
    }

    /** The in-memory span exporter, when `telemetry.exporter = none` was used. For tests. */
    public static function inMemorySpanExporter(): ?\OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter
    {
        return self::$exporters?->inMemorySpanExporter();
    }

    /** The in-memory metric exporter, when `telemetry.exporter = none` was used. For tests. */
    public static function inMemoryMetricExporter(): ?\OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter
    {
        return self::$exporters?->inMemoryMetricExporter();
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::shutdown();
        });
    }
}
