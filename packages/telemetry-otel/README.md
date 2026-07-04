# quioteframework/telemetry-otel

OpenTelemetry SDK exporter/bootstrap for [Quiote](https://github.com/quioteframework/quiote)'s always-on `Trace` facade. Builds worker-lifetime tracer/meter providers from `telemetry.*` settings and flushes them per request.

## Install

```
composer require quioteframework/telemetry-otel
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Telemetry\TelemetryPlugin::class],
```

...and set `telemetry.enabled` (plus `telemetry.exporter`, `telemetry.otlp.endpoint`, etc.) in your app's settings. Without this package installed and enabled, the kernel's own `Trace` facade stays a safe no-op — nothing calling `Trace::span()` needs to change either way.

## License

MIT. See [LICENSE](LICENSE).
