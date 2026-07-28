# quioteframework/worker-swoole

Serves a Quiote application from an embedded [Swoole](https://swoole.com) HTTP
server, via the `swoole` alias of Quiote's worker-runtime seam
(`Quiote\Runtime\Worker\WorkerRuntimeInterface`).

> Developed in-tree under [quioteframework/quiote](https://github.com/quioteframework/quiote)'s
> `packages/worker-swoole/`. PRs go to the monorepo, not here.

## Install

```sh
pecl install swoole          # 5.1 or newer
composer require quioteframework/worker-swoole
```

`ext-swoole` is a composer `suggest`, not a `require`, so the package installs
and type-checks without it. The runtime raises an actionable error if you try to
serve without the extension.

Activate the plugin in `Config/plugins.xml`:

```xml
<plugin class="Quiote\Runtime\Swoole\WorkerSwoolePlugin"/>
```

## Set up

Swoole's server is started by a PHP script, so the app needs an entrypoint next
to (not inside) its document root. `quiote new --runtime=swoole` generates it;
for an existing app, add `swoole.php` in the application root:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

Quiote\Runtime\Kernel::create([
    'app_dir'        => __DIR__,
    'env'            => getenv('QUIOTE_ENV') ?: 'production',
    'worker_runtime' => 'swoole',
])->run();
```

Then either `php swoole.php` directly, or `bin/quiote swoole:serve`, which is a
thin wrapper around the same script (it sets `$QUIOTE_WORKER_RUNTIME=swoole` for
you). `pub/index.php` stays as it is, so the same codebase still runs under
FrankenPHP or php-fpm unchanged.

Unlike RoadRunner, detection needs an explicit opt-in — either
`'worker_runtime' => 'swoole'` or `$QUIOTE_WORKER_RUNTIME=swoole`. That is
deliberate: `extension_loaded('swoole')` is not evidence that you are running
*under* a Swoole server, since the extension is routinely loaded under php-fpm
too, and auto-claiming on it would hijack every FPM request on such a box.

## Coroutines are off, on purpose

Quiote keeps process-global state — `Config`, `Context`, `PluginManager`,
`RoutingCallbackPool`, `LogContext`, `$_SESSION`, and the per-request hydrated
superglobals. Under coroutines two requests interleave inside one worker and
stomp all of it: log lines attributed to the wrong request, session data leaking
between users.

So the server runs in `SWOOLE_BASE` with `enable_coroutine => false`, giving
exactly the one-request-at-a-time semantics FrankenPHP and RoadRunner have.
**Scale with `worker.swoole.worker_num`, not with coroutines.** Setting
`worker.swoole.enable_coroutine` raises an error naming the unsafe state; the
`worker.swoole.allow_coroutine_unsafe` override exists but you own the outcome.

## What changes when you leave the SAPI

| | Behaviour |
|---|---|
| Superglobals | Hydrated per request from the PSR-7 request and cleared afterwards, so `Routing`, ext/session and other legacy readers keep working. |
| `$_FILES` | Populated but **without `tmp_name`**. Use `$request->getUploadedFiles()`. |
| Uploads | Swoole deletes its temp files when the request ends, so `moveTo()` must happen during the request — an upload cannot be deferred to a queued job. |
| `header()` / `setcookie()` | No-ops. Set headers on the PSR-7 response instead. |
| Native sessions | The `Set-Cookie` PHP would normally emit itself is synthesised onto the response, so the legacy `Quiote\Storage` session path still establishes sessions. |
| `echo` outside a template | Captured and appended to the response body. Configurable via `core.worker.stray_output`. |
| SSE / streaming | Supported, one `write()` per event. A `write()` returning false is the client-disconnect signal, so an endless stream stops when the client goes away — `connection_aborted()` cannot be used off-SAPI, it always reports 0. |
| `SCRIPT_NAME` | Swoole supplies none, so it is synthesised (`worker.swoole.script_name`). `Routing` reads it when generating URLs, so a wrong value produces wrong links rather than an error. |

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `worker.swoole.host` | `0.0.0.0` | Bind address. |
| `worker.swoole.port` | `8080` | Bind port. |
| `worker.swoole.worker_num` | `1` | Worker processes. This is the concurrency knob. |
| `worker.swoole.max_request` | `0` | Requests before Swoole recycles a worker. `0` = never. Keep `core.worker.max_requests` at `0` and use this instead. |
| `worker.swoole.package_max_length` | `8388608` | Max request body. Swoole silently truncates anything larger. |
| `worker.swoole.script_name` | `/index.php` | Value for `$_SERVER['SCRIPT_NAME']`. |
| `worker.swoole.ssl` | `false` | True only when Swoole itself terminates TLS. Behind a TLS-terminating proxy leave it false and let the `X-Forwarded-*` handling do the work. |
| `worker.swoole.enable_coroutine` | `false` | See above. |
| `worker.swoole.allow_coroutine_unsafe` | `false` | Escape valve for the guard above. |

## Not supported

OpenSwoole. It is an API-divergent fork (`OpenSwoole\Http\Server`, a separate
extension). The extension surface here is deliberately confined to
`SwooleRequestSnapshotFactory`, `SwooleResponseWriter` and
`NativeSwooleServerFactory`, so adding it later is three small classes — but it
is not claimed today.
