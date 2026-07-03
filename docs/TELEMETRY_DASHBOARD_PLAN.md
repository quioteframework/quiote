# Telemetry Dashboard (TUI) Plan

A `quiote telemetry:dashboard` command that renders a live, beautiful
text-based UI for monitoring a running Quiote app, built on
[`symfony/tui`](https://github.com/symfony/tui). Plus a small load-generator
script to drive the sample app (steady traffic + random pauses + occasional
bursts) so the dashboard has something to show.

## Status

**All 6 phases are implemented and verified**, including a full three-process
live demo (real sample app + real load generator + real dashboard, real OTLP
over the wire — see the Demo runbook below and each phase's "Done" notes for
what was actually observed). `Quiote\Telemetry\Dashboard\{HttpMessageParser,
ParsedHttpRequest, MalformedRequestException, OtlpDecoder, ReceivedSpan,
ReceivedMetric, ReceivedDataPoint, OtlpReceiver}` (Phases 1–2),
`{RingBuffer, RouteStats, DashboardSnapshot, DashboardState}` (Phase 3),
`{Spark, Bars, TextSanitizer, DashboardView}` plus
`Quiote\Console\Command\TelemetryDashboardCommand` (Phase 4), and
`samples/app/bin/loadgen.php` (Phase 5) all exist and are tested (106 new
tests across `tests/tests/unit/telemetry/dashboard/` and
`tests/tests/unit/console/TelemetryDashboardCommandTest.php`, all included
in the 1705-test `composer test` total as of this pass). `symfony/tui` (`^8.1`) is installed as `require-dev`
+ `suggest`, exactly mirroring how the `open-telemetry/*` packages are
handled — zero-cost for a production install, and `telemetry:dashboard` is
only registered when the package is actually present
(`Quiote\Console\Application::__construct()`).

Three findings from building this that diverge from the sketch below (each
detailed in its phase's own "Done" note, since the reasoning matters more
than the prose agreeing with itself):
- **Installing `symfony/tui` has a sharp edge**: `php-http/discovery`
  auto-reinstalls a PSR-18 client during any full Composer dependency
  resolution, silently undoing the "no PSR-18 client" invariant
  `docs/OPENTELEMETRY_PLAN.md` deliberately holds. Fixed procedure documented
  in Phase 1.
- **`Revolt\EventLoop::onSignal()` is not reliable for this command's own
  shutdown** when no timers are registered yet (`stream_select()` blocks
  indefinitely); `pcntl_async_signals()` + `pcntl_signal()` is what actually
  works, confirmed by hand in both the Phase 0 spike and the real command.
- **`DashboardState` derives throughput/latency/error-rate/per-route stats
  from root spans, not from OTLP metrics**, a deliberate improvement over the
  original sketch once Phase 6 of `docs/OPENTELEMETRY_PLAN.md` was checked:
  root spans already carry `http.route`/`quiote.cache.hit`/
  `http.response.status_code`, and per-span data gives genuine percentiles
  instead of histogram-bucket estimates. Metrics remain the only source for
  CPU time, memory, and worker RSS, which spans don't carry.

Two real display bugs were caught only by running the actual TUI against
real terminal output / a real full end-to-end run — both fixed, both noted
in their phase's "Done" section: a raw Unix-timestamp instead of a clock
time in the recent-request feed (Phase 4), and a misleading status code `0`
instead of `ERR` for exceptions that never reached the HTTP-response stage
(Phase 5).

---

## The core idea (how the dashboard gets its data)

Quiote's telemetry (`docs/OPENTELEMETRY_PLAN.md`, Phases 1–8) already exports
**OTLP** — spans and metrics over OTLP/HTTP to `telemetry.otlp.endpoint`. The
natural, zero-new-plumbing way to monitor the app is therefore:

> **The dashboard *is* a minimal OTLP/HTTP receiver.** It listens on a local
> port (default `4318`), the app points `telemetry.otlp.endpoint` at it, and
> every span/metric batch the app already exports lands in the dashboard's
> in-memory store and drives the live UI. No external OTel Collector, no
> Jaeger, no Prometheus — one process you run and watch.

This is the recommended design. It reuses the existing export path verbatim
(the app is instrumented exactly as it is for production; only the endpoint
changes) and needs **no new decoding dependency**: the OTLP protobuf
request-side message classes (`ExportTraceServiceRequest`,
`ExportMetricsServiceRequest`, with `mergeFromString()`) and the pure-PHP
`google/protobuf` runtime are **already installed** as transitive deps of
`open-telemetry/exporter-otlp` (in `require-dev`).

### Why in-process, on the same event loop

`symfony/tui`'s `Tui::run()` drives the **global Revolt event loop**
(`revolt/event-loop`, a required dep of `symfony/tui`). Revolt's loop is a
process-global singleton, so we register our own listening socket as a
readable-stream watcher (`Revolt\EventLoop::onReadable($server, …)`) *before*
calling `$tui->run()`, and it is serviced by the very same loop that renders
the UI. One process, one loop: OTLP ingestion and TUI rendering are
cooperatively scheduled, no threads, no second process.

The periodic re-render is driven by `Tui::scheduleInterval($cb, $seconds)` (or
`onTick()`), so the UI refreshes on a fixed cadence (e.g. 4–10 Hz) regardless
of ingest timing, reading a snapshot of the store each frame.

### Alternatives considered and rejected

- **Query an existing collector's backend** (Prometheus/Jaeger HTTP APIs):
  makes the dashboard depend on external infra and a query language; defeats
  the "just run it" goal. Out of scope (a future `--source=prometheus` could
  add it).
- **File tailing** (a new "file" exporter writing JSONL the TUI tails):
  requires a new exporter on the framework side and loses the live push
  semantics. The OTLP receiver reuses the *existing* exporter unchanged.
- **Separate receiver process + IPC to the TUI**: strictly more moving parts
  than sharing the one Revolt loop already in the process.

---

## Dependencies

- **`symfony/tui`** — add to `require-dev` **and** `suggest` (mirroring exactly
  how the `open-telemetry/*` packages are handled). It is a developer/ops
  tooling dependency, not a runtime one: a production install pays nothing, and
  the command registration is `class_exists()`-guarded so the CLI works
  identically when the package is absent (the command simply isn't offered, or
  prints an actionable "run `composer require --dev symfony/tui`" message).
  - Requires PHP ≥8.4.1 (we require ≥8.5 ✓). Pulls `revolt/event-loop`,
    `symfony/event-dispatcher`, `symfony/string` — all Symfony 8, already
    pervasive here.
  - **Experimental** (currently v8.1, no BC promise). Pin conservatively and
    isolate every `Symfony\Component\Tui\*` reference behind our own thin
    rendering layer (see "Rendering" below) so an upstream API change is a
    one-file fix, not a scatter.
- **No new decode dependency** — OTLP protobuf request classes + `google/protobuf`
  are already present via `open-telemetry/exporter-otlp` (`require-dev`).
- **PSR-18 client for the *demo* app** — the OTLP *exporter* (sample-app side)
  needs one, and its **absence is deliberately load-bearing** for
  `testOtlpWithoutPsr18ClientFallsBackToDisabled`
  (`docs/OPENTELEMETRY_PLAN.md`, Dependencies). So we do **not** add one to
  `composer.json`. The demo runbook installs it transiently
  (`composer require --dev symfony/http-client`, removed after), exactly the
  pattern `docs/OPENTELEMETRY_E2E_VERIFICATION.md` already established. The
  dashboard *receiver itself* needs no HTTP client — it only parses inbound
  requests.

---

## Widget reality check (drives the whole UI design)

`symfony/tui` ships primitive widgets — `TextWidget`, `ProgressBarWidget`,
`ContainerWidget` (layout), `Figlet` (big headers), `SelectListWidget`,
`InputWidget`, `MarkdownWidget`, `LoaderWidget`. **There is no Chart,
Sparkline, Gauge, or Table widget.** Everything richer is composed from text:

- **Sparklines / mini bar charts** → a pure helper that maps a numeric series
  to Unicode block glyphs (`▁▂▃▄▅▆▇█`) or braille, emitted as a styled
  `TextWidget`. (`Quiote\Telemetry\Dashboard\Spark`.)
- **Gauges / meters** (memory RSS vs. a ceiling, error-rate bar) →
  `ProgressBarWidget`, or a hand-drawn `█░` bar in a `TextWidget` for finer
  control of color thresholds.
- **Tables** (per-route stats) → column-aligned text (`str_pad`/`mb_str_pad`,
  `symfony/string` is available) inside a bordered `ContainerWidget`.
- **Layout** → nested `ContainerWidget`s. Confirm the constraint/direction API
  during the Phase 0 spike (the exact layout API isn't documented; it is
  experimental).

**Implication:** the dashboard's visual quality lives in *our* small rendering
helpers (spark, bars, tables, color thresholds), not in the library. That is
where the "beautiful" budget goes.

---

## Components & file layout

```
Quiote/Console/Command/TelemetryDashboardCommand.php   # the `telemetry:dashboard` command
Quiote/Telemetry/Dashboard/
    OtlpReceiver.php        # binds a stream-socket server on the Revolt loop; accepts OTLP/HTTP POSTs
    HttpMessageParser.php   # minimal, bounded HTTP/1.1 request parse (headers + Content-Length body)
    OtlpDecoder.php         # ExportTrace/MetricsServiceRequest -> plain PHP value objects
    DashboardState.php      # in-memory rolling store (thread-free; single-loop)
    RingBuffer.php          # fixed-size time-series ring (throughput, latency, cpu, rss over last N s)
    RouteStats.php          # per-route aggregates (count, avg ms, error %, last seen)
    Spark.php               # numeric series -> block-glyph sparkline string
    Bars.php                # value/ceiling -> colored bar string; horizontal bar chart
    DashboardView.php       # pure: DashboardState snapshot -> widget tree (no I/O, unit-testable)
samples/app/bin/loadgen.php # the load generator (dependency-free CLI)
```

Rationale for `Quiote\Telemetry\Dashboard\*` (not `Console\*`): the receiver,
decoder and store are telemetry-domain and independently testable; the Command
in `Console\Command` is just the orchestrator (parse options → start receiver →
start TUI → wire refresh). `DashboardView` is the *only* file that references
`Symfony\Component\Tui\*`, keeping the experimental surface contained.

---

## The OTLP receiver

A minimal, purpose-built OTLP/HTTP endpoint — **not** a general HTTP server.

- Bind `stream_socket_server("tcp://127.0.0.1:{port}")`, non-blocking, register
  with `Revolt\EventLoop::onReadable()`; accept connections, register each
  client socket likewise.
- **HTTP parse scope is deliberately tiny**: the OTel PHP OTLP/HTTP exporter
  always sends `POST /v1/traces` or `POST /v1/metrics` with a `Content-Length`
  (never chunked) and `Content-Type: application/x-protobuf` (or
  `application/json` if the app is configured `http/json`). `HttpMessageParser`
  reads until `\r\n\r\n`, then reads exactly `Content-Length` bytes. Anything
  outside this shape → `400`, logged, connection closed. No keep-alive
  complexity required (respond, close), though keep-alive is a cheap later
  optimization for worker-mode batch bursts.
- Decode the body: `ExportTraceServiceRequest::mergeFromString($body)` /
  `ExportMetricsServiceRequest::mergeFromString($body)` (protobuf) or
  `json_decode` (OTLP/JSON). Respond `200` with a serialized empty
  `ExportTraceServiceResponse` / `ExportMetricsServiceResponse` (partial-success
  count `0`) — what the exporter expects, so it doesn't log export failures.
- `OtlpDecoder` walks resource → scope → spans/metrics and produces plain PHP
  value objects (`ReceivedSpan`, `ReceivedMetric`) so `DashboardState` and the
  tests never touch protobuf types.
- **Robustness is non-negotiable**: a malformed/oversized/garbage request must
  never crash the dashboard — bounded read size, try/catch around decode,
  drop-and-continue, same "never take down the process" posture the telemetry
  middleware already holds on the app side.

---

## DashboardState (the rolling store)

Fed by the receiver, read by the render loop. Single-threaded (one Revolt
loop), so plain arrays — no locking.

- **Time series** (`RingBuffer`, ~120 s at 1 s resolution): requests/s,
  avg latency ms, p95 latency ms, CPU ms/req, worker RSS bytes, error count/s.
  Filled by bucketing incoming metrics/spans by arrival second.
- **Metric extraction**: from the metrics the app already emits
  (`docs/OPENTELEMETRY_PLAN.md`, Phase 3) —
  `http.server.request.duration` (histogram → avg = sum/count; p95 estimated
  from bucket boundaries), `quiote.request.cpu.time`,
  `quiote.request.memory.peak`, `quiote.worker.memory.rss` (gauge),
  `http.server.request.count` (counter, dimensioned by
  `http.response.status_code`, `cache.hit`). Counters are monotonic per worker
  → store deltas between scrapes for rate.
- **Per-route** (`RouteStats`): keyed by `http.route`/route name (from span
  attributes or metric dimensions) — count, avg ms, error %, cache-hit %, last
  seen. Bounded (top-N by traffic; the rest folded into an "other" row so the
  table can't grow unbounded).
- **Recent feed**: a bounded deque of the last ~N root spans (method, route,
  status, duration, trace id) and a separate **recent errors** deque (spans
  with Error status / 5xx / recorded exception). This is the "tail -f for
  requests" panel.
- **Totals & health**: uptime, total requests seen, current req/s, overall
  error rate, whether any data has arrived yet ("waiting for telemetry…").

---

## Rendering (`DashboardView` + TUI loop)

`DashboardView::build(DashboardState $snapshot): AbstractWidget` is a **pure
function** — state in, widget tree out, no I/O — so the layout and every
derived string (sparklines, bars, table cells, colors) are unit-testable by
asserting on produced text without a terminal.

Proposed layout (top→bottom, composed of `ContainerWidget`s):

```
┌ quiote · telemetry:dashboard ─────────────── service: quiote-sample-app ─┐
│  Figlet/heading · uptime 00:03:12 · 12,481 reqs · 42.3 req/s · err 1.2% │
├──────────────────────────────┬──────────────────────────────────────────┤
│ Throughput (req/s, 120s)     │ Latency avg / p95 (ms, 120s)              │
│ ▂▃▅▇█▆▅▃▂▁▂▄▆█…              │ ▁▂▂▃▃▅▄▃▂▂▁…   avg 8.4  p95 31.2  max 88  │
├──────────────────────────────┴──────────────────────────────────────────┤
│ Worker RSS  [██████████░░░░░░]  148 MB      CPU/req  user 6.1 sys 1.3 ms │
│ Mem peak    [████░░░░░░░░░░░░]   12 MB      Error rate [█░░░░░░] 1.2%     │
├───────────────────────────────────────────────────────────────────────── ┤
│ Route            reqs   avg ms   p95    err%   cache%                     │
│ GET /            8,201    6.2    22.1   0.0%    73%                       │
│ GET /about       3,140    9.8    41.0   0.0%    12%                       │
│ GET /boom          412   11.1    50.3  100.0%    0%   ← errors            │
├───────────────────────────────────────────────────────────────────────── ┤
│ Recent    12:03:11 GET /            200   6ms                            │
│           12:03:11 GET /boom        500  11ms  RuntimeException: Boom!   │
├───────────────────────────────────────────────────────────────────────── ┤
│ [q]uit  [p]ause  [c]lear  [t]races/[m]etrics  listening 127.0.0.1:4318   │
└───────────────────────────────────────────────────────────────────────── ┘
```

- **Color thresholds**: latency/error/RSS bars shift green→yellow→red by
  configurable thresholds (a small `Thresholds` value object) — the main lever
  of "at a glance, is it healthy?".
- **Keybindings** via `Tui::addListener()`/`handleInput()`: `q` quit,
  `p` pause ingestion display (freeze frame), `c` clear stats,
  `t`/`m` toggle a traces-detail vs. metrics-detail lower panel. Follow
  `symfony/tui`'s `QuitableTrait`/`KeybindingsTrait` conventions.
- **Refresh**: `scheduleInterval(fn () => $tui->clear()->add(DashboardView::build($state->snapshot()))->requestRender(), 0.2)`
  (exact API confirmed in Phase 0). Rebuilding the tree each frame is fine at
  this scale; if it flickers, diff/patch specific `TextWidget`s by id via
  `Tui::getById()`.

---

## Load generator (`samples/app/bin/loadgen.php`)

A dependency-free PHP CLI (uses `curl` handles; no framework bootstrap) that
sits in a loop and drives the sample app so the dashboard has live traffic.

- **Endpoints & weights**: mostly `/` and `/about`, some `/contact`, and an
  occasional `/boom` (so error-rate/red panels light up) — weights tunable.
- **Pacing**: a base inter-request sleep drawn from a random range
  (e.g. 50–400 ms), **plus periodic bursts** — every so often fire a tight
  cluster of M requests (via `curl_multi_*`) with little/no pause, then return
  to the calm cadence. This is exactly the "pauses in between and bursts here
  and there" the feature is meant to visualize.
- **Options**: `--base-url` (default `http://127.0.0.1:8123`), `--rps`,
  `--burst-every`, `--burst-size`, `--duration` (0 = forever),
  `--error-rate`. Prints a compact running status line
  (`sent=… ok=… err=… rps=…`) to stderr so it's usable standalone too.
- Graceful `SIGINT` stop.

---

## Demo runbook (verified end-to-end; not a script that mutates composer.json)

Ran exactly this sequence for real -- three processes, one full live loop of
sample app → OTLP → dashboard, and it produced a correctly-updating TUI (see
Phases 4/5's "Done" notes above for what was observed and the two display
bugs it caught).

1. `composer require --dev symfony/tui` (already permanent, done in Phase 1)
   **and**, transiently, `composer require --dev symfony/http-client` (so the
   sample app's OTLP exporter has a PSR-18 client). **Sharp edge**: this must
   be done with `php-http/discovery` temporarily disabled
   (`composer config allow-plugins.php-http/discovery false`) or Composer's
   own dependency resolution silently reinstalls a PSR-18 client on the very
   next `composer remove` too -- see Phase 1's findings above for the full
   mechanism and the exact command sequence that avoids it. Restore
   `allow-plugins.php-http/discovery` to `true` afterwards either way.
2. In `samples/app/Config/settings.php`, temporarily replace
   `'telemetry.enabled' => false,` with:
   ```php
   'telemetry.enabled' => true,
   'telemetry.exporter' => 'otlp',
   'telemetry.export.mode' => 'simple',
   'telemetry.sampling.strategy' => 'always_on',
   'telemetry.otlp.endpoint' => 'http://127.0.0.1:4318',
   'telemetry.service.name' => 'quiote-sample-app',
   ```
   Revert this file afterwards too (`git checkout samples/app/Config/settings.php`
   is simplest).
3. Terminal A: `php bin/quiote telemetry:dashboard --service=quiote-sample-app`
   (starts the OTLP receiver on `127.0.0.1:4318` + the live TUI; `--port`/
   `--host` to change the bind address).
4. Terminal B: `php -S 127.0.0.1:8123 -t samples/app/pub` (the sample app,
   its actual front controller -- the same one a real deployment uses).
5. Terminal C: `php samples/app/bin/loadgen.php --error-rate=0.1` (steady
   traffic across `/`, `/about`, `/contact`, with periodic bursts and a
   deliberate 10% `/boom` error rate to light up the dashboard's red panels).
6. Watch Terminal A update live. `q` to quit the dashboard, Ctrl-C the other
   two, then undo steps 1-2 (`composer remove --dev symfony/http-client`
   with discovery disabled the same way, `git checkout` the settings file).

Worker-mode (`batch`) is the production shape and also works (per the
existing `tests/e2e/OtlpCollectorE2ETest.php` fixture, which already proves
the app side against a real Collector in worker mode) -- `php -S` + `simple`
above is just the fastest thing to eyeball for this dashboard's own demo.

For a CI-safe / no-TTY smoke check that the command itself is wired
correctly (registered, resolves `DashboardView`, produces output) without
running any of the above: `php bin/quiote telemetry:dashboard --self-test`.

---

## Testing

**Done** — every bullet below shipped as described; also see
`tests/tests/unit/telemetry/dashboard/` (106 tests total, including:
`HttpMessageParserTest`,
`OtlpDecoderTest`, `OtlpReceiverTest`, `RingBufferTest`, `RouteStatsTest`,
`DashboardStateTest`, `SparkTest`, `BarsTest`, `TextSanitizerTest`,
`DashboardViewTest`) and `tests/tests/unit/console/TelemetryDashboardCommandTest.php`.

Rendering/terminal loops resist end-to-end tests, so push all logic into pure,
testable units and keep the TUI shell thin:

- **`HttpMessageParser`**: headers + `Content-Length` body; short/oversized/
  malformed bodies → error, never hang.
- **`OtlpDecoder`**: feed real serialized `ExportTraceServiceRequest` /
  `ExportMetricsServiceRequest` bytes (built in-test, or captured from the
  existing e2e fixture) → assert `ReceivedSpan`/`ReceivedMetric` values,
  including resource/scope attribute flattening.
- **`DashboardState`**: ingest a scripted sequence → assert req/s, avg/p95,
  error rate, per-route rows, counter-delta rate, ring-buffer windowing and
  bounded growth (no unbounded retention across a long run — same discipline
  Phase 2 of the telemetry plan holds).
- **`Spark`/`Bars`**: series → exact glyph string; edge cases (empty, single
  value, all-equal, NaN/inf guarded).
- **`DashboardView`**: a fixed `DashboardState` snapshot → assert the produced
  widget tree's text (route table alignment, threshold colors, "waiting for
  telemetry" empty state).
- **Command smoke test**: a `--self-test`/`--once` flag that feeds a synthetic
  batch, builds one frame, prints it, and exits `0` — runnable in CI without a
  TTY and without `symfony/tui`'s interactive loop (guarded so it's skipped if
  the package isn't installed, like other optional-dep tests).
- Kept **out of `composer test`'s hot path** only where a real TTY/loop is
  needed; the pure units above run in the normal suite.

---

## Phasing

- **Phase 0 — Spike (de-risk the experimental dep). Done.** Confirmed against
  `symfony/tui` v8.1.1 (a throwaway script, `EventLoop::onReadable` + a real
  socket + `Tui::run()` + SIGINT, not just reading source):
  - **(a) Confirmed.** A `Revolt\EventLoop::onReadable()` watcher registered on
    a plain `stream_socket_server()` *before* `$tui->run()` fires normally
    while the TUI loop is blocking in `run()`'s suspension — no separate
    thread/process needed. This is also exactly the pattern `Terminal::start()`
    itself uses internally for STDIN (`EventLoop::onReadable(\STDIN, ...)`),
    so it's a supported, not incidental, usage.
  - **(b) Confirmed, with one API correction.** Layout/chrome is a `Style`
    object passed to `ContainerWidget::setStyle()`:
    `new Style(direction: Direction::Vertical, gap: 1, border: Border::from([1]), padding: Padding::from([1]))`.
    **`Border::from()` takes an `array` (like `Padding::from()`), not a bare
    `int`** — `Border::from(1)` throws a `TypeError`; `Border::from([1])` is
    correct (this doc's earlier sketches should be read with that correction).
    Colors/bold/etc. are further `Style` constructor params
    (`Quiote/Telemetry` will need `Color::from('red'|'#ff5500'|0-255)`).
    `TextWidget` also passes through raw ANSI codes in its string, so
    hand-rolled sparkline/bar glyphs can carry inline color escapes directly
    when a per-cell (not per-widget) color is needed — see the widget's own
    docblock security note below, though.
  - **(c) Confirmed.** `Tui::scheduleInterval(callable, float $seconds)` fired
    on schedule and `requestRender()` after mutating a `TextWidget` (found via
    `Tui::getById()`) updated the visible frame each tick.
  - **(d) Confirmed.** `pcntl_signal(SIGINT, fn() => $tui->stop())` +
    `pcntl_async_signals(true)` cleanly unwound `Tui::run()`'s `finally` block
    (cursor restored, raw mode undone via `stty`) and the process exited on
    its own — no hang, no leftover raw terminal state.
  - **Security note carried forward from `TextWidget`'s own docblock**: text
    content is rendered ANSI-passthrough, unsanitized. Since dashboard text
    ultimately derives from telemetry data an instrumented app controls
    (route names, exception messages, attribute values), every such string
    **must** go through `Util\StringUtils::stripControlBytes()` (the same
    helper `InputWidget`/`MarkdownWidget` already use) before reaching a
    `TextWidget` — otherwise a hostile/buggy app could inject terminal escape
    sequences via, e.g., a span attribute. This is a concrete Phase 4
    requirement, not a hypothetical.
  - Spike script and this section are the record; the throwaway script itself
    was not committed.
- **Phase 1 — Deps + command skeleton. Done.** `symfony/tui` added to
  `require-dev`/`suggest`; `TelemetryDashboardCommand` (`telemetry:dashboard`)
  registered in `Application::__construct()` behind
  `class_exists(\Symfony\Component\Tui\Tui::class)`. Verified end-to-end with
  a real `curl`-equivalent OTLP/HTTP POST (built from the real protobuf
  message classes) against the running command: `HTTP 200` returned, the
  decoded span printed with correct name/duration/error status.
  - **Installing `symfony/tui` has a sharp edge, worth documenting for
    anyone repeating it**: `open-telemetry/sdk` hard-`require`s the virtual
    `psr/http-client-implementation` package. Composer's `php-http/discovery`
    plugin (already `allow-plugins`-enabled for the OTel packages) treats any
    *full dependency resolution* (`composer require`/`remove`/`update`, not a
    plain `composer install` replaying an existing lock) as license to
    auto-satisfy that virtual requirement by installing a real PSR-18 client
    (`symfony/http-client`, in this case) -- silently reintroducing exactly
    the dependency `docs/OPENTELEMETRY_PLAN.md` deliberately keeps out (see
    that doc's Dependencies section; its absence is load-bearing for
    `testOtlpWithoutPsr18ClientFallsBackToDisabled`). A plain
    `composer remove --dev symfony/http-client` doesn't fix it either -- the
    removal is itself a full resolution, so discovery just reinstalls it
    again during that same command. The only clean fix: temporarily
    `composer config allow-plugins.php-http/discovery false`, edit
    `composer.json` by hand to drop the line, run `composer update --lock`
    (or the targeted remove) while the plugin is disabled, then restore
    `allow-plugins.php-http/discovery` to `true` afterwards. Confirmed clean
    afterwards: `composer.lock` has zero installed-package entries for
    `symfony/http-client`, and the full suite (1599 tests before this
    phase's own additions) passes, including
    `testOtlpWithoutPsr18ClientFallsBackToDisabled`.
  - **SIGINT correction (contradicts this doc's Phase 0 sketch of using
    `Revolt\EventLoop::onSignal()` for the command's own shutdown, though not
    the Phase 0 TUI spike itself, which already used the mechanism below)**:
    `TelemetryDashboardCommand` needed to stop `EventLoop::run()` cleanly on
    Ctrl-C with *no TUI and no timers running* -- just the receiver's single
    readable-socket accept watcher. `EventLoop::onSignal()` only fires because
    Revolt's `StreamSelectDriver` calls `pcntl_signal_dispatch()` *after* its
    underlying `stream_select()` returns -- and a `select()` blocked
    indefinitely on one socket watcher with no timers does not reliably wake
    up early just because a signal arrived (confirmed by hand: the process
    sat unresponsive to SIGINT for 2+ seconds using `onSignal()`). Switched to
    `pcntl_async_signals(true)` + `pcntl_signal(SIGINT, ...)` directly (PHP's
    own async-signal delivery, independent of Revolt's select loop) --
    confirmed stopping the process within ~100ms. This is exactly the
    mechanism the Phase 0 spike already used successfully; the risk was
    reaching for the more "Revolt-native" `onSignal()` API instead without
    re-testing it in a no-timer configuration. **Implication for Phase 4**:
    once the TUI's own `scheduleInterval()` refresh timer is running,
    `stream_select()` will have a bounded timeout on every iteration
    regardless, which may make `onSignal()` viable there -- but keep
    `pcntl_async_signals()` for the standalone/no-TUI receiver path (and
    honestly, there's no reason to maintain two mechanisms; Phase 4 should
    just keep the one already proven to work).
- **Phase 2 — Receiver + decode.** `OtlpReceiver`, `HttpMessageParser`,
  `OtlpDecoder`, value objects; robustness/fuzz coverage.
- **Phase 3 — State store.** `DashboardState`, `RingBuffer`, `RouteStats`,
  histogram avg/p95, counter deltas, bounded feeds; full unit coverage.
- **Phase 4 — TUI. Done.** `Spark` (block-glyph sparklines), `Bars` (fixed-width
  filled/empty gauges), `TextSanitizer` (strips ESC/CSI/C1 bytes from every
  telemetry-derived string before it reaches a `TextWidget` -- see Phase 0's
  security note), and `DashboardView::build()` -- a pure
  `DashboardSnapshot -> ContainerWidget` function, the only file besides
  `TelemetryDashboardCommand` that touches `Symfony\Component\Tui\*`.
  `TelemetryDashboardCommand` now runs the real `Tui` loop: ingestion updates
  a `DashboardState` captured by reference in both the receiver's callbacks
  and a `scheduleInterval()`-driven 0.25s refresh that rebuilds the tree from
  a fresh snapshot; `q` stops the `Tui`, `c` resets to a new `DashboardState`.
  - Verified for real, not just via the pure-function unit tests: ran the
    command inside a `tmux` pane (a real PTY, needed for `Terminal::start()`'s
    `stty raw -echo`), captured the empty "Waiting for telemetry..." frame,
    fired real OTLP/HTTP span batches at it with `curl`-equivalent PHP
    (built from the actual protobuf classes, mixing `/`, `/about`, and
    error `/boom` traffic), and captured the live-updating frame: header
    totals, throughput/latency sparklines, resource bars, the route table,
    and the recent-request feed (errors highlighted, correct status
    message) all updated correctly across refresh ticks. Then confirmed `c`
    resets to the empty state and `q` exits the process cleanly (tmux
    session ended, no orphaned process).
  - **One rendering fix found only by eyeballing the real terminal output**:
    the recent-feed rows initially printed the raw Unix second
    (`1783089323  GET /boom  500  28.0ms  ...`) instead of a clock time --
    obvious once seen, invisible to a text-substring unit test that only
    asserts a route/status/message appears. Fixed with a `gmdate('H:i:s', ...)`
    formatter (`DashboardView::formatClockTime()`; UTC deliberately, so
    output is deterministic and independent of the host's timezone).
  - `DashboardState`'s per-route/per-error keys are already sanitized at
    render time in `DashboardView`, not at ingest time in `DashboardState` --
    ingestion stores raw (but attribute-bounded, see `RouteStats`) telemetry
    values, and sanitization is applied once, at the one place text actually
    reaches a `TextWidget`. `DashboardViewTest::testHostileSpanNameCannotInjectEscapeSequences`
    pins this against a span name containing a real ESC/CSI sequence.
  - **Post-ship refinement, from real user feedback after using it**: the
    throughput/latency charts were single text rows (`Spark::render()`
    produced one line of block glyphs) and the whole dashboard box only grew
    to its natural content height, leaving blank terminal below it rather
    than filling the screen. Fixed by:
    - **`ChartWidget`** (`Quiote\Telemetry\Dashboard\ChartWidget`) -- a leaf
      widget implementing `Symfony\Component\Tui\Widget\VerticallyExpandableInterface`
      directly (that interface isn't restricted to `ContainerWidget` in the
      library, just conventionally only implemented there/`EditorWidget`).
      `render()` reads `$context->getRows()`/`getColumns()` fresh every
      frame and calls the two new `Spark` methods below, so the chart is
      genuinely responsive to terminal resizes, not sized once at startup.
    - **`Spark::renderBars()`** replaces the old single-line `render()`:
      multi-row bar chart using eighth-block glyphs (`▁`–`█`) for sub-row
      resolution, and **`Spark::resample()`** (bucket-averaging downsample)
      so a 120-sample series always exactly fits whatever column width the
      chart panel is actually given.
    - **Deliberate scaling change**: `renderBars()` normalizes against an
      **absolute zero baseline** (`value / max`), not the old relative
      min-max range `Spark::render()` used. These series are non-negative
      counts/durations where zero is a meaningful, distinct reading (a
      quiet second genuinely had zero requests) -- min-max normalization
      would have shown a visible baseline bar even for zero, which reads as
      "the smallest amount of *something* happened" rather than "nothing
      happened." `SparkTest::testAllZeroValuesRenderAsBlankRatherThanAVisibleBaseline`
      pins this.
    - **Fill-ness propagates up through the tree with zero explicit
      `expandVertically()` calls** in `DashboardView::chartPanel()` --
      `ContainerWidget::isVerticallyExpanded()`'s own documented contract is
      "true if explicitly set, OR if any child needs to expand," so marking
      only the leaf `ChartWidget` is enough for every plain `ContainerWidget`
      ancestor (the panel, `seriesRow`, all the way to the dashboard's own
      root box) to report `isVerticallyExpanded() === true` and receive a
      fill-child's share of whatever vertical space is left over after every
      non-expanding sibling (header, resource gauges, route table, recent
      feed, footer) is measured at its natural height.
    - **One thing propagation does NOT cover**: the empty "waiting for
      telemetry" state has no `ChartWidget` descendant at all (nothing to
      chart yet), so nothing propagates fill-ness up on that branch --
      without an explicit `$root->expandVertically(true)` at the top of
      `DashboardView::build()`, the box would only grow to fill the terminal
      *after* the first span arrived, not on startup. Caught by hand in a
      real `tmux` pane (the waiting-state box visibly stopped short of the
      pane's bottom edge) before it was caught by a test;
      `DashboardViewTest::testRootFillsAvailableHeightEvenBeforeAnyDataArrives`
      pins it now.
    - **Resize verified for real**, not assumed from reading `Terminal.php`'s
      `SIGWINCH` handling: resized a live `tmux` pane running the dashboard
      against real traffic from 160×45 down to 100×25 and back up to
      180×50, and captured the chart genuinely shrinking (down to ~1 row at
      the smallest size) and growing back, with column resampling and
      row-count both tracking the new dimensions on every frame -- confirms
      `Tui`'s existing `requestRender()`-on-resize plus this dashboard's own
      periodic refresh are sufficient; no dashboard-specific resize handling
      was needed beyond making the layout itself fill-aware.
- **Phase 5 — Load generator. Done.** `samples/app/bin/loadgen.php` -- a
  dependency-free (`curl` only, no Composer autoload) CLI. Weighted
  endpoints (`/` 50%, `/about` 25%, `/contact` 20%, `/boom` via
  `--error-rate`, default 5%, rescaling the other three proportionally);
  a random per-request pause (`--min-delay-ms`/`--max-delay-ms`, default
  50-400ms); periodic concurrent bursts via `curl_multi_*`
  (`--burst-every`/`--burst-size`, default every 15s / 20 requests);
  `--duration` (0 = forever) and graceful `pcntl_signal(SIGINT)` stop; a
  running `sent=… ok=… err=… connect_err=… rps=…` status line to stderr.
  - Verified for real against the actual sample app served via
    `php -S 127.0.0.1:PORT -t samples/app/pub`: a 6-second run with
    `--burst-every=2 --burst-size=8` produced real mixed 200/500 responses
    at the expected proportions, visible in both the loadgen status line and
    the app's own request log.
  - **Full end-to-end demo run, all three pieces together** (sample app +
    `telemetry:dashboard` + `loadgen.php`, real OTLP over the wire, no
    in-memory shortcuts anywhere): confirmed live throughput/latency
    sparklines climbing during bursts, the route table splitting traffic
    correctly across `/`, `/about`, `/contact`, `/boom`, and the recent-error
    feed showing the sample app's actual `Boom! This is a deliberately
    triggered error.` message in real time.
  - **A real display bug this full run caught, invisible to the pure-function
    `DashboardView` unit tests** (which only ever fed statusCode `500`
    directly): `/boom` requests rendered status `0`, not `500`. Root cause is
    in the framework, not the dashboard -- confirmed against
    `docs/OPENTELEMETRY_PLAN.md`'s own Phase 3 notes: `TelemetryMiddleware`
    records `Error` status via `recordException()`/`setStatusError()` in its
    exception path, but genuinely never sets an `http.response.status_code`
    attribute there (no response object exists yet at that point in the
    pipeline -- `ErrorHandlingMiddleware` builds the actual 500 response
    further out). Fixed on the display side, not the framework side (the
    framework's behavior is intentional, documented, and out of scope to
    change here): `DashboardView::formatStatusCode()` renders `ERR` instead
    of a bare `0` when a span is marked as an error but carries no status
    code, so a genuinely-absent code no longer reads as a fabricated
    "successful" `0`.
- **Phase 6 — Docs + runbook.** This doc's runbook finalized; a short section in
  the telemetry docs; `--self-test` smoke test wired in CI.

---

## What this is NOT

- **Not a production monitoring backend.** It's a live, at-a-glance dev/ops
  window onto one app (or one worker fleet exporting to it), not a store of
  record. For retention/alerting, export to a real Collector + Prometheus/
  Jaeger (the recommended production topology already documented in the
  telemetry plan).
- **Not a general HTTP server.** The receiver parses exactly the OTLP/HTTP
  shape the OTel PHP exporter emits and rejects everything else.
- **Not a hard/runtime dependency.** `symfony/tui` stays in `require-dev` +
  `suggest`; a production install and the CLI without it are unaffected.
- **Not changing the app-side instrumentation.** The app exports OTLP exactly
  as it does for production; only `telemetry.otlp.endpoint` points at the
  dashboard.
- **Not bundling a PSR-18 client** (keeps `testOtlpWithoutPsr18ClientFallsBackToDisabled`
  honest); the demo installs one transiently.
