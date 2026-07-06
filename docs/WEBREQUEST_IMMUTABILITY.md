# WebRequest is immutable — and how to use the AssetRegistry

`Quiote\Request\WebRequest` used to be a mutable object: `setParameter()`,
`appendAttribute()`, and friends changed the object in place, and because
the same instance was shared everywhere (`Context::getRequest()`), that
mutation was invisible-but-effective — every reader downstream just saw the
change for free.

That's no longer true. `WebRequest` now follows the same contract as the
PSR-7 request it wraps: **every mutator returns a new instance and leaves the
original untouched.** This document covers what breaks, how to fix it, and
the `AssetRegistry` service that replaces the one pattern (page-level CSS/JS
accumulation) that mutable attributes were being used for.

## The rule

If a method used to be `void` and mutated `$this`, it now returns `static`
instead:

| Method | Old contract | New contract |
|---|---|---|
| `setParameter($name, $value)` | mutates in place, returns `void` | returns a **new** `WebRequest` |
| `appendParameter($name, $value)` | mutates in place | returns a **new** `WebRequest` |
| `removeParameter($name, $source)` | mutates in place | returns a **new** `WebRequest` |
| `declareParameter(s)($names)` | mutates in place | returns a **new** `WebRequest` |
| `enforceValidatedParameters($keys)` | mutates in place | returns a **new** `WebRequest` |
| `clearParameters()` | mutates in place | returns a **new** `WebRequest` |
| `setAttribute($name, $value)` | mutates a private attribute bag | returns a **new** `WebRequest` (thin wrapper over PSR-7 `withAttribute()`) |
| `appendAttribute($name, $value)` | mutates a private attribute bag | returns a **new** `WebRequest` |

This means the following is now a silent no-op — it compiles, runs, throws
nothing, and simply does nothing:

```php
// WRONG — return value discarded, $request (and whatever Context holds) is unchanged
$request->setParameter('total', $total);
$request->appendAttribute('javascript', 'js/chart.js');
```

The fix is always the same shape: capture the return value.

```php
// RIGHT
$request = $request->setParameter('total', $total);
```

## The part that's easy to miss: self-syncing to Context

Reassigning the local variable is necessary but often **not sufficient**.
`Context::getRequest()` is how most of the framework (and your app) gets
"the current request" — templates, later middleware, the next pipeline
stage. If you mutate your own local `$request` variable but nothing tells
`Context` about it, everyone else still sees the old one.

```php
// Still WRONG in most cases — $request is fixed locally, but Context::getRequest()
// still returns the old instance to every other reader.
$request = $request->setParameter('total', $total);
```

If the value needs to be visible beyond the current method, sync it back
explicitly:

```php
$request = $request->setParameter('total', $total);
$this->getContext()->setRequest($request);
```

### When do you actually need the sync?

| Where you're setting the parameter | Does the framework re-fetch for you? |
|---|---|
| Inside a PSR-15 middleware, and `$request` flows directly into the next `$handler->handle($request)` call in the same method | No sync needed — you're already passing the updated value forward. |
| Inside an `Action::validate()` / `validate{Method}()` | Yes — sync required. `ValidationMiddleware` re-fetches `Context::getRequest()` immediately after calling `validate()`, specifically to pick this up. |
| Inside an `Action::execute{Method}()` | Yes — sync required. `ActionExecutor::doExecute()` re-fetches `Context::getRequest()` right after the action runs, before creating the View, specifically to pick this up. |
| Inside an `Action::handle{Method}Error()` | Yes — sync required. `ValidationMiddleware` re-fetches `Context::getRequest()` right after calling the error handler, before creating the error View. |
| Inside a `View::execute{Method}()` | Yes, if a sibling template/layer rendered afterward needs to read it back via `getParameter()`/`getAttribute()`. There's no further re-fetch after a View runs (it's normally the last step), so sync immediately, before anything reads the request again. |

The pattern to reach for whenever you're not sure: **always sync**. An
unnecessary `$this->getContext()->setRequest($request)` is a harmless no-op;
a missing one is a parameter or attribute that silently vanishes.

## Migrating existing code

Grep for call sites that don't reassign. Two passes: one for methods that
are unique to `WebRequest` (no false positives possible), one for the
shared-name methods filtered to request-shaped variables:

```bash
# Pass 1 — WebRequest-only method names
rg -n --type=php -g '!vendor' '\$\w+->(enforceValidatedParameters|declareParameter|declareParameters)\(' \
  | rg -v '= ?\$?\w*->'

# Pass 2 — shared-name methods (setParameter/appendParameter/removeParameter/
# clearParameters/setAttribute/appendAttribute also exist on the unrelated
# Quiote\Util\ParameterHolder and Quiote\Util\AttributeHolder, which stay
# mutable by design — exclude $this-> receivers to drop that noise)
rg -n --type=php -g '!vendor' '\$\w+->(setParameter|appendParameter|removeParameter|clearParameters|setAttribute|appendAttribute)\(' \
  | rg -v '\$this->' \
  | rg -v '= ?\$?\w*->'
```

Also watch for the **fluent-chain** form, which the same-line regex above
won't catch when it's split across lines:

```php
$this->getContext()
    ->getRequest()
    ->appendAttribute('javascript', 'js/chart.js');   // discarded, spans 3 lines
```

```bash
rg -Uzn 'getRequest\(\)\s*->(setParameter|appendAttribute|setAttribute)\(' --type=php -g '!vendor'
```

Every match needs a human look: is the receiver actually a `WebRequest`? If
yes, does the value need to survive past this method (→ sync to `Context`),
or is it consumed later in the very same call chain (→ local reassignment
is enough)?

## Subclassing `WebRequest`

The URL metadata fields (`urlScheme`, `urlHost`, `urlPort`, `urlPath`,
`urlQuery`, `requestUri`, `protocol`) are no longer plain properties on
`WebRequest` — they live in a private `RequestUrl` value object. A subclass
that used to poke `$this->urlScheme = ...` directly will fail to compile.
Use the public setters instead, all present on the base class:
`setUrlScheme()`, `setUrlHost()`, `setUrlPort()`, `setRequestUri()`,
`setUrlPath()`, `setUrlQuery()`, `setProtocol()`. These remain `void` and
mutate the instance directly — they're bootstrap/test helpers, not part of
the request's immutable value-flow, so there's nothing to reassign.

---

## The AssetRegistry

Page-level CSS/JS accumulation (`$request->appendAttribute('css', ...)`) was
one of the most common uses of mutable request attributes, and it's the one
case where "just reassign and sync" isn't the right fix — because the thing
appending an asset is very often **not** the top-level View.

### Why this needs its own service

`View::createSlotContent()` renders a slot's action/view as a **separate**
Action/View instance, each with its own private attribute holder (see
`SlotDispatcher::dispatch()`). If a slot-nested view called
`$this->appendAttribute(...)` (the View's own, `AttributeHolder`-backed
method — a different thing entirely from `WebRequest::appendAttribute()`),
that value would be trapped on the slot's throwaway View instance, invisible
to the page's actual `<head>`. The one object every node in the render tree
(top-level view, every nested slot) reaches identically is `Context` — so
that's where page assets live now: `Context::getAssetRegistry()`.

### API

```php
final class Quiote\Asset\AssetRegistry implements Symfony\Contracts\Service\ResetInterface
{
    public function addCss(string $href): void;
    public function addJavascript(string $src): void;

    /** @return list<string> */
    public function css(): array;
    /** @return list<string> */
    public function javascript(): array;
}
```

It's a plain mutable service (not a value object — there's no reason for it
to be immutable), deduplicating at insertion time and preserving first-seen
order. Appending the same asset twice — e.g. two different slots both
needing `d3.min.js` — renders it once, at the position it was first needed.

It's request-scoped: `Context` lazily creates one (`getAssetRegistry()`) and
nulls it in `reset()`, so nothing leaks between requests in worker mode
(FrankenPHP etc.) — the same pattern `Context` already uses for
`SlotDispatcher`.

### From an Action or View

Don't reach for `Context::getAssetRegistry()` directly in application code —
`View` exposes thin wrappers:

```php
class MyChartSuccessView extends View
{
    public function executeHtml(WebRequest $request): void
    {
        $this->addCss('css/chart.css');
        $this->addJavascript('js/d3.min.js');
        $this->addJavascript('js/my-chart.js');
    }
}
```

These reach `Context` directly, so they work identically whether the view is
the top-level page view or something rendered inside a slot. There is no
immutability footgun here, unlike `WebRequest::appendAttribute()` — no
reassignment, no sync, just call it.

`Action` doesn't currently expose the same helpers (no existing call site
needed them from an Action rather than a View); reach `Context` directly if
you need to register an asset from action code:
`$this->getContext()->getAssetRegistry()->addCss(...)`.

### Reading assets in a template

Wire the registry into the renderer's `assigns` so templates get it the same
way they get `$rq`/`$rd`. This works from **any** config format (XML, YAML,
or a plain PHP array) — `assigns` is a generic parameter map read by
`Quiote\Renderer\Renderer`, which strips underscores from the key and calls
`Context::get<result>()`. For `asset_registry` that's `Context::getassetregistry()`
— not a real method name, but PHP resolves method calls case-insensitively,
so it finds `Context::getAssetRegistry()` anyway. This is exactly how the
existing `request` → `rq` assign already works, regardless of which format
produced the `assigns` array — nothing about this is format-specific.

XML:

```xml
<renderer name="php" class="Quiote\Renderer\PhpRenderer">
    <ae:parameter name="assigns">
        <ae:parameter name="request">rq</ae:parameter>
        <ae:parameter name="asset_registry">assets</ae:parameter>
    </ae:parameter>
</renderer>
```

YAML — same canonical shape, just YAML syntax:

```yaml
renderers:
  php:
    class: Quiote\Renderer\PhpRenderer
    parameters:
      assigns:
        request: rq
        asset_registry: assets
```

PHP array — identical again, since YAML/XML both compile down to this shape:

```php
'renderers' => [
    'php' => [
        'class' => \Quiote\Renderer\PhpRenderer::class,
        'parameters' => [
            'assigns' => [
                'request' => 'rq',
                'asset_registry' => 'assets',
            ],
        ],
    ],
],
```

Then in the layout template:

```php
<?php foreach ($assets->css() as $href): ?>
    <link rel="stylesheet" type="text/css" href="<?= $href ?>">
<?php endforeach; ?>

<?php foreach ($assets->javascript() as $src): ?>
    <script type="text/javascript" src="<?= $src ?>"></script>
<?php endforeach; ?>
```

### What it deliberately doesn't do

- **No priority/ordering system beyond insertion order.** That's a
  faithful match for the old attribute-append behavior; don't build a
  dependency graph unless something actually needs one.
- **No per-asset metadata** (media queries, `defer`/`async`, integrity
  hashes). If that's ever needed, it's a small value object
  (`CssAsset{href, media}`) replacing the plain `string` — not a reason to
  redesign the registry now.
- **No `inline_javascript` support.** The old attribute-based mechanism had
  it; nothing in the codebase ever wrote to it, only read it, so it was
  removed rather than ported. Inline `<script>` blocks are worth avoiding
  anyway (CSP, caching) — if a real need shows up, it can be added as
  `addInlineJavascript()`/`inlineJavascript()` following the same shape.
