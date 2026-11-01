# quioteframework/dev-overlay

In-browser developer overlay for [Quiote](https://github.com/quioteframework/quiote).

While `core.developer_exceptions` is on, every HTML page the app serves gets a small **Q** badge in
the bottom-left corner. Any exception the app catches — on the page itself, or on a same-origin
`fetch()` or `XMLHttpRequest` the page makes afterwards — shows up on the badge as a count. Click
it to see the exception class and message, the request, a source excerpt around the throw site,
the stack frames (library frames folded away), and the whole `getPrevious()` chain. Exceptions
the app caught and answered itself show up too, once it reports them (see below).

Whoops only helps when the browser renders the error page. A failing JSON call made from
JavaScript gives you a status code and nothing else. The overlay fills that gap.

## Install

```
composer require --dev quioteframework/dev-overlay
```

```php
// Config/plugins.php
return [
    ['class' => \Quiote\DevOverlay\DevOverlayPlugin::class, 'enabled' => true],
];
```

Then turn on `core.developer_exceptions` in development. Nothing happens while it is off: the
middleware passes every request straight through, and the overlay's URLs don't exist.

## Exceptions your code handles

An action that catches an exception to answer usefully, such as a 400 with a message for the user
or a fallback value, ends normally, so `ErrorHandlingMiddleware` never sees the exception. Report
it through `Quiote\Error\ErrorReporting` to have the overlay show it anyway:

```php
use Quiote\Error\ErrorReporting;

public function __construct(private readonly ErrorReporting $errors) {}

// ...
} catch (ConnectException $e) {
    $this->errors->handled($e, 'AuthService unreachable; answered 400');
    // ...answer 400 with a message for the user
}
```

The overlay lists it under the request, marked **handled**, with your note and the status the
response still had. A request can report several. `ErrorReporting` is a singleton, so any service
can inject it.

`handled()` does nothing while `core.developer_exceptions` is off, so it can stay in production
code. It doesn't log, so keep your own log call. It also doesn't mark the request as failed: the
`Throwable` request attribute that `ErrorHandlingMiddleware` publishes means "this request ended in
this exception", and replay recordings classify requests by it. Don't publish that attribute
yourself to reach the overlay.

## How it works

- `DevOverlayMiddleware` runs in the bootstrap phase, just outside `ErrorHandlingMiddleware`. When
  that middleware catches an exception, or the request reported handled exceptions, the overlay
  stores one report for the request and adds an `X-Quiote-Exception: <id>` header to the response,
  whatever its content type.
- For a full HTML document (one with a `</body>`), the middleware also adds
  `<script src="/_quiote/overlay/overlay.js" defer>` before `</body>`. HTML fragments, JSON,
  compressed bodies and `HEAD` responses are left untouched.
- The script wraps `window.fetch` and `XMLHttpRequest`. When a same-origin response carries the
  header, it fetches `/_quiote/overlay/<id>` and adds the report to the badge.
- The UI renders inside a shadow root and is styled with a constructable stylesheet. Your page's
  CSS doesn't affect it, and a `default-src 'self'` Content Security Policy doesn't block it.

## Configuration

| Key | Default | |
|---|---|---|
| `dev_overlay.enabled` | `true` | Turns the overlay off while keeping `core.developer_exceptions` (and Whoops) on. It can't turn the overlay on without `core.developer_exceptions`. |
| `dev_overlay.path` | `/_quiote/overlay` | URL prefix for the script and the report endpoint. |
| `dev_overlay.store_path` | `<core.cache_dir>/dev-overlay` | Directory where reports are kept. |
| `dev_overlay.max_reports` | `50` | How many reports are kept; older ones are deleted. |
| `dev_overlay.editor_url` | `''` | Turns file locations into links. Use `{file}` and `{line}`, for example `vscode://file/{file}:{line}` or `phpstorm://open?file={file}&line={line}`. |

## Multiple hosts

Reports are written to the local filesystem. If several hosts serve one URL, the follow-up request
for a report can land on a different host than the one that wrote it. In that case, bind your own
`Quiote\DevOverlay\Report\ExceptionReportStoreInterface` implementation backed by shared storage.

## Security

The overlay shows exception messages, file paths and source code to anyone who can load a page.
That is exactly what Whoops shows, so it follows the same switch: keep `core.developer_exceptions`
off anywhere other people can reach.
