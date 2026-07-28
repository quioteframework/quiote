# Worker-runtime integration tests

Serves a small probe application under **real** RoadRunner and Swoole servers via
`docker compose`, and asserts the behaviours that only change once the app leaves
the SAPI. Run with `composer test:integration` (the whole file is
`#[Group('integration')]`, so it stays out of `composer test`).

The unit suites cover the request converters and loop wiring in isolation. They
cannot tell you whether the CGI server params the framework reads are actually
present, whether a session cookie makes it back to the client, whether repeated
`Set-Cookie` headers survive, whether a stray `echo` corrupts the server's
protocol stream, or whether the worker is still alive after a request failed.
That is what this is for. Every assertion runs against both runtimes from the
same data provider, so the two cannot silently diverge.

## Layout

| Path | What it is |
|---|---|
| `app/` | The probe app. Deliberately separate from `samples/app`, whose job is to be a readable sample rather than to carry endpoints that exist only to be poked at. Uses the legacy `Quiote\Storage\SessionStorage` on purpose — that is the path which depends on PHP emitting `Set-Cookie` itself, which is a no-op off-SAPI. |
| `app/pub/index.php` | Front controller, so the same endpoints can be served under `php -S`. This is the control: when an assertion fails, run it here first to tell "the runtime is wrong" from "the app is wrong". |
| `worker.php` / `.rr.yaml` | RoadRunner entrypoint and server config. |
| `swoole.php` | Swoole entrypoint. |
| `Dockerfile.roadrunner` / `Dockerfile.swoole` | One image per runtime, both serving the same app. |

## Endpoints

| Route | Probes |
|---|---|
| `/` | The app boots and serves at all. |
| `/echoback` | Reports what the app actually saw — URI, superglobals, body, and the runtime alias. Most assertions read this. |
| `/session` | Increments a counter in the legacy session, so cookie round-tripping is observable. |
| `/stray` | `echo`es outside the response body. |
| `/stream` | An SSE endpoint. |
| `/cookies` | Two `Set-Cookie` headers on one response. |
| `/boom` | Throws, to prove a failed request doesn't take the worker down. |

## Running by hand

```sh
cd tests/integration/worker
docker compose up -d --build
curl -s localhost:8281/echoback   # roadrunner
curl -s localhost:8282/echoback   # swoole
docker compose down -v
```
