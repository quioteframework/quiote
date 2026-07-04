# OpenTelemetry End-to-End Verification

The unit test suite (`tests/tests/unit/telemetry/`, 120 tests as of Phase 8)
proves `Quiote\Telemetry` produces correct span/metric *objects* against an
in-memory exporter. It says nothing about whether OTLP serialization, HTTP
transport, and a real collector's ingestion actually agree on the wire
format. This document is that second check: a real instrumented app, a real
OTel Collector, real HTTP requests, real telemetry observed arriving.

It also documents a real bug this exercise found and fixed — the kind of bug
that is, by construction, invisible to an in-memory-exporter unit test (see
"What this caught" below).

**This exercise is now also an automated, repeatable test**:
`tests/e2e/OtelCollectorE2ETest.php`, runnable with `composer test:e2e`. It
covers the same ground documented below (full span tree, root-span renaming,
the error-status regression, 404-is-not-an-error, metrics presence) plus one
thing the manual exercise didn't: it runs against real FrankenPHP **worker**
mode with `telemetry.export.mode = batch` (the manual walkthrough below used
`simple`), the actual production deployment shape
(`Dockerfile`/`Caddyfile`/`docker-compose.yml` at the repo root). It is
**deliberately excluded from `composer test`/CI** — see
`tests/config/phpunit.xml`'s `#[Group('e2e')]` exclusion, the same mechanism
already used for the APCu tests — because it needs Docker, builds a
container image, and takes real wall-clock time (~30s) to stand up and tear
down. The rest of this document is the original manual walkthrough, kept for
the narrative of how the bug below was actually found; the automated test is
what actually runs on demand now.

## Setup

**1. A real OTel Collector**, via Docker, with a `debug` exporter (prints
everything it receives to its own logs — the simplest possible "receiver
style thing" that proves data arrived, with no second system to doubt):

```yaml
# otel-collector-config.yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318
      grpc:
        endpoint: 0.0.0.0:4317
exporters:
  debug:
    verbosity: detailed
service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [debug]
    metrics:
      receivers: [otlp]
      exporters: [debug]
```

```
docker run -d --name otel-e2e-collector \
  -p 4318:4318 -p 4317:4317 \
  -v $PWD/otel-collector-config.yaml:/etc/otelcol-contrib/config.yaml \
  otel/opentelemetry-collector-contrib:latest
```

**2. The real sample app** (`samples/app`, already in this repo — a small
real Quiote app with `/`, `/about`, and a deliberately-throwing `/boom`
action), with `telemetry.*` temporarily enabled in
`samples/app/Config/settings.php`:

```php
'telemetry.enabled' => true,
'telemetry.exporter' => 'otlp',
'telemetry.export.mode' => 'simple',       // synchronous export, no batching delay
'telemetry.sampling.strategy' => 'always_on',
'telemetry.otlp.endpoint' => 'http://localhost:4318',
'telemetry.service.name' => 'quiote-sample-app',
```

**3. A real PSR-18 HTTP client**, temporarily. The OTLP exporter needs one
(`docs/OPENTELEMETRY_PLAN.md`'s Dependencies section explains why this repo
deliberately does *not* bundle one even in `require-dev` — its absence is
itself load-bearing for `testOtlpWithoutPsr18ClientFallsBackToDisabled`).
`composer require --dev symfony/http-client` for the duration of this
exercise only, then `composer remove --dev symfony/http-client` immediately
after — `composer.json`/`composer.lock` end this exercise byte-for-byte
identical to how they started, and the "no PSR-18 client" tests pass again
once it's gone (confirmed: they genuinely fail while the client is
present — proof the test is exercising a real condition, not a tautology).

**4. Serve the app for real**: `php -S 127.0.0.1:8123 -t samples/app/pub`
(the app's actual front controller, `samples/app/pub/index.php`, which calls
`Quiote\Runtime\Kernel::create([...])->run()` — the same code path a real
deployment uses) and hit it with `curl`.

## What was verified

### 1. Traces actually arrive, with the correct nested shape

`curl http://127.0.0.1:8123/` produced exactly the span tree Phases 3+6
predict — one trace ID shared across all four spans, correct parent/child
IDs, correct categories and attributes:

```
GET /                                    (Quiote.Http, Kind=Server, root — no parent)
  └─ match                               (Quiote.Routing — http.route=/, route_name=index)
  └─ Default:Index                       (Quiote.Action — quiote.module=Default, quiote.action=Index, ...)
       └─ Default:IndexSuccess           (Quiote.View — quiote.view.module=Default, quiote.view.name=IndexSuccess)
```

Resource attributes on every span confirm the SDK's own resource detection
ran for real: `service.name=quiote-sample-app` (our configured value),
`host.name`, `host.arch`, `os.*`, `process.pid`, `process.executable.path`,
`telemetry.sdk.{name,language,version}=opentelemetry/php/1.14.0`.

`curl http://127.0.0.1:8123/about` produced the same shape with `/about`
substituted throughout — confirming this isn't a hardcoded fixture, routing
genuinely drives the span identity per request.

### 2. Metrics actually arrive, with real trace-ID exemplars

Every request also produced a `ResourceMetrics` batch: `http.server.request.duration`,
`quiote.request.cpu.time` (split `cpu.mode=user`/`system`),
`quiote.request.memory.peak`, `quiote.worker.memory.rss`,
`http.server.request.count` — each histogram/gauge/sum carrying an
`Exemplar` whose `Trace ID`/`Span ID` matched that exact request's root span,
proving the metrics-to-trace correlation (not just the metrics themselves)
survives real OTLP serialization.

### 3. The error path — and a real bug this exercise found

`curl http://127.0.0.1:8123/boom` (the sample app's deliberately-throwing
action) exercises Phase 3's exception-recording path for real, not through a
synthetic handler. First attempt:

```
Name           : Default:Boom
Status code    : Error
Status message : Boom! This is a deliberately triggered error.
Events: exception { exception.type=RuntimeException, exception.message=..., exception.stacktrace=... }
```

correctly showed Error on the **action** span — but the **root** `GET /boom`
span came back `Status code: Unset`, with no `http.response.status_code`
attribute at all, even though `TelemetryMiddleware`'s own `finally` block
unconditionally calls `$span->recordException($error)->setStatusError(...)`
for exactly this case, and a unit test
(`TelemetryMiddlewareTest::testExceptionIsRecordedOnSpanAndRethrown`) already
passed against this exact code path.

**Root cause** (confirmed via targeted `error_log()` instrumentation, since
this is a real timing bug an in-memory exporter's synchronous, single-threaded
unit tests can't surface — nothing in the unit suite calls `Trace::current()`
from a *different* middleware than the one that owns the span, mid-exception,
the way the real pipeline does): `RoutingMiddleware` captures
`Trace::current()` into a local `$root` variable to rename it once a route
matches. `Trace::current()` returned an `OtelSpanHandle` whose `__destruct()`
unconditionally called `end()` on the real underlying span — with no concept
of "this is a borrowed reference, I don't own this span's lifecycle." When
`RoutingMiddleware::process()`'s stack frame unwound (during the exception
propagating out of `/boom`'s action, *before* reaching `TelemetryMiddleware`'s
own `catch`/`finally`, since routing is deeper in the call stack), PHP
destructed the now-out-of-scope `$root` — silently ending the root span. By
the time `TelemetryMiddleware`'s `finally` block ran moments later and called
`setStatusError()`/`recordException()` on that same (already-ended) span, the
real OTel `Span` implementation silently no-ops both calls (per spec: `if
($this->hasEnded) { return $this; }`) — no exception, no warning, just quietly
discarded.

**Fix**: `OtelSpanHandle` gained an `$ownsLifecycle` constructor flag
(default `true`). `Trace::span()` (which genuinely creates and owns a span)
keeps the default; `Trace::current()` (which only ever borrows a reference to
whatever's active) now passes `ownsLifecycle: false`, so its `__destruct()` is
a no-op — an explicit `->end()` call is still always honored, only *implicit*
destruction-triggered ending changes. Re-ran `/boom` after the fix:

```
Name           : GET /boom
Status code    : Error
Status message : Boom! This is a deliberately triggered error.
Attributes: ... quiote.duration_ms=27.76, quiote.cpu.user_ms=14.501, ...
```

Full CI suite re-run clean after the fix (1596 tests, only the pre-existing,
unrelated `RoutesListCommandTest` flake — confirmed to reproduce on a clean
`git stash` of the entire session's work, i.e. present before any of this
began). See `Quiote/Telemetry/OtelSpanHandle.php`'s class docblock for the
permanent record of this — it's the kind of bug that's easy to reintroduce by
"simplifying" the destructor later without this context.

**Why the unit suite didn't catch this**: every existing unit test that
exercises `TelemetryMiddleware`'s exception path uses a minimal synthetic
`RequestHandlerInterface` — there is no `RoutingMiddleware` (or any other
middleware) in between to capture and prematurely destruct a borrowed
`Trace::current()` reference. The bug only manifests when *two different
middleware*, one of which borrows a reference via `Trace::current()`, are
both in the real call chain and an exception unwinds through the borrower's
stack frame before reaching the owner's. That's a genuine integration
condition, which is exactly why this exercise — not more unit tests — is what
found it.

### 4. 4xx is correctly NOT treated as a span error

`curl http://127.0.0.1:8123/nonexistent-path-xyz` (404): root span `Status
code: Unset`, matching the design (`recordMeasurements()` only calls
`setStatusError()` for `statusCode >= 500`) — a 404 is an expected outcome,
not a span-level error, per OTel semantic conventions.

## Cleanup

Everything above was reverted after verification: `samples/app/Config/settings.php`'s
telemetry keys removed (back to `'telemetry.enabled' => false`), the
temporary compiled-config cache cleared, `symfony/http-client` removed via
`composer remove --dev`, the PHP dev server and the `otel-e2e-collector`
Docker container both stopped/removed. The only permanent artifacts from this
manual exercise were the `OtelSpanHandle`/`Trace::current()` bug fix and this
document — the automated version (`tests/e2e/`) came after, as its own,
separately-committed, self-contained fixture (own `Dockerfile` that installs
`require-dev` plus a locally-added `symfony/http-client` — neither touches
the repo's own `composer.json`/`composer.lock` — and its own
`samples/app/Config/settings.php` override baked into that image only).
`OtelCollectorE2ETest::tearDownAfterClass()` runs the Docker teardown and
output-directory cleanup automatically after every `composer test:e2e` run,
successful or not (PHPUnit always calls it).

## Running it yourself

```
composer test:e2e
```

Builds the e2e image, starts it alongside a real OTel Collector, waits for
the app's healthcheck, fires real requests, asserts on what the collector
actually received (via its `file` exporter, bind-mounted so the test can read
it directly), then tears everything down — collector logs also still show
everything via `debug`-exporter output if you want to watch a run manually
(`docker compose -f tests/e2e/docker-compose.yml up --build`, then `docker
compose -f tests/e2e/docker-compose.yml logs -f otel-collector` in another
terminal).
