# quioteframework/telemetry-dashboard

A live TUI dashboard + minimal OTLP/HTTP receiver for [Quiote](https://github.com/quioteframework/quiote) apps. A standalone process, not part of the request path — no app bootstrap required to run it.

## Install

```
composer require quioteframework/telemetry-dashboard
```

## Use

Nothing to configure — installing the package is enough. Run:

```
quiote telemetry:dashboard
```

Point your app's `telemetry.otlp.endpoint` at the dashboard's receiver address (default `127.0.0.1:4318`) to see live traffic.

## License

MIT. See [LICENSE](LICENSE).
