<?php

namespace Quiote\Telemetry;

use Quiote\Event\Lifecycle\KernelBootEvent;
use Quiote\Event\Lifecycle\WorkerRequestCompletedEvent;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Wires the OTel-SDK-dependent exporter bootstrap ({@see TelemetryBootstrap})
 * into the generic event seams instead of {@see \Quiote\Runtime\Kernel}
 * calling it by hard FQCN. The always-on `Trace` facade this exporter feeds
 * ({@see Trace}, {@see
 * TraceRegistry}, the no-op handles) stays in core regardless — only the
 * SDK-backed provider setup/flush moves through this seam.
 *
 * `KernelBootEvent` fires at the end of {@see \Quiote\Quiote::bootstrap()},
 * which every `Quiote\Runtime\Kernel::bootstrap()` call already goes through
 * before it used to call `TelemetryBootstrap::configureFromConfig()` directly
 * — routing the same call through this listener changes nothing observable.
 * `WorkerRequestCompletedEvent` fires once per request from `Kernel`'s
 * worker-mode reset step, replacing the old direct
 * `TelemetryBootstrap::flushAfterRequest()` call there.
 *
 * Not yet an installable package (`Quiote\Telemetry\TelemetryBootstrap` and
 * the OTel-SDK classes still live in this repo, and `Quiote::bootstrap()`
 * runs this plugin unconditionally today — see the "core default" note
 * there) — when the exporter moves to `quioteframework/telemetry-otel`, that
 * core-default call is deleted and this plugin (unchanged) is registered via
 * the `plugins` config key instead, exactly like {@see \Quiote\Mcp\McpPlugin}.
 */
#[PluginAttribute(name: 'quiote/telemetry-otel')]
final class TelemetryPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->listen(KernelBootEvent::class, static function (): void {
            TelemetryBootstrap::configureFromConfig();
        });
        $registrar->listen(WorkerRequestCompletedEvent::class, static function (): void {
            TelemetryBootstrap::flushAfterRequest();
        });
    }
}
