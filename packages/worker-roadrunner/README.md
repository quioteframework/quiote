# quioteframework/worker-roadrunner

Runs a Quiote application as a [RoadRunner](https://roadrunner.dev) PSR-7 worker,
via the `roadrunner` alias of Quiote's worker-runtime seam
(`Quiote\Runtime\Worker\WorkerRuntimeInterface`).

> Developed in-tree under [quioteframework/quiote](https://github.com/quioteframework/quiote)'s
> `packages/worker-roadrunner/`. PRs go to the monorepo, not here.

## Install

```sh
composer require quioteframework/worker-roadrunner
composer require --dev spiral/roadrunner-cli && vendor/bin/rr get-binary
```

Then activate the plugin in `Config/plugins.xml`, like any other Quiote plugin —
installing the package alone does not switch anything on:

```xml
<plugin class="Quiote\Runtime\RoadRunner\WorkerRoadRunnerPlugin"/>
```

## Set up

RoadRunner spawns workers by running a PHP script, so the app needs an entrypoint
next to (not inside) its document root. `quiote new --runtime=roadrunner`
generates both files below; for an existing app, add them by hand.

`worker.php` in the application root — the same shape as `pub/index.php`, with
the runtime pinned:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

Quiote\Runtime\Kernel::create([
    'app_dir'        => __DIR__,
    'env'            => getenv('QUIOTE_ENV') ?: 'production',
    'worker_runtime' => 'roadrunner',
])->run();
```

`.rr.yaml`:

```yaml
version: "3"
server:
  command: "php worker.php"
http:
  address: "0.0.0.0:8080"
  middleware: ["static", "gzip"]
  pool:
    num_workers: 0   # one per CPU
    max_jobs: 1000
logs:
  mode: production
```

Then `rr serve`. Detection is automatic — RoadRunner sets `$RR_MODE=http` for its
workers — so `'worker_runtime' => 'roadrunner'` is belt-and-braces rather than
required. It is worth keeping: an explicit runtime that turns out not to be
hosting the process fails at startup instead of quietly degrading to
one-request-per-process.

`pub/index.php` stays as it is, so the same codebase still runs under
FrankenPHP or php-fpm unchanged.

## What changes when you leave the SAPI

RoadRunner runs workers under the CLI SAPI, which is a bigger shift than "same
app, different server". Quiote absorbs most of it, but not all:

| | Behaviour |
|---|---|
| Superglobals | Hydrated per request from the PSR-7 request (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`) and cleared afterwards, so `Routing`, ext/session and other legacy readers keep working. |
| `$_FILES` | Populated but **without `tmp_name`** — a PSR-7 upload may have no file behind it. Use `$request->getUploadedFiles()`. |
| `header()` / `setcookie()` | No-ops. Set headers on the PSR-7 response instead. |
| Native sessions | The `Set-Cookie` PHP would normally emit itself is synthesised onto the response, so the legacy `Quiote\Storage` session path still establishes sessions. |
| `echo` outside a template | Captured and appended to the response body rather than corrupting RoadRunner's relay. Configurable via `core.worker.stray_output` (`append`, `discard`, `throw`). |
| SSE / streaming | Supported: each `SseEvent` is sent as its own frame. RoadRunner gives no client-disconnect signal to the worker, so an endless stream ends when the server tears the worker down. |
| Logging to stdout | Stdout **is** the relay. Point stream sinks at `php://stderr`, which RoadRunner forwards to its own log. |

## Worker recycling

Leave it to RoadRunner (`http.pool.max_jobs`, `max_worker_memory`) and keep
`core.worker.max_requests` at its default of `0`. Stopping the loop from PHP
mid-pool looks like a crashed worker to the server.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `worker.roadrunner.chunk_size` | `8192` | Upper bound on bytes per streamed frame. Events smaller than this are still sent immediately, one frame each. |
