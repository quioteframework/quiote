# Micro-benchmarks

Self-contained per-request hot-path micro-benchmarks. No external benchmarking
dependency — each case is warmed, then sampled over several rounds; the minimum
ns/op (least noise-prone for micro-benchmarks) plus median/mean are reported.

## Usage

```sh
php benchmarks/run.php run <label>      # run all, save results/<label>.json
php benchmarks/run.php compare <a> <b>  # print delta between two saved runs
```

Example workflow (before/after a change):

```sh
php benchmarks/run.php run baseline
# ... apply changes ...
php benchmarks/run.php run tier1
php benchmarks/run.php compare baseline tier1
```

## Cases

| benchmark | exercises |
|---|---|
| `config_resolve_format` | `ConfigCache::resolveConfigFormat()` config-file format resolution |
| `webresponse_construct` | `WebResponse` construction (status-code table allocation) |
| `view_render_layers` | `View::renderLayers()` per-layer attribute-map rebuild (4 layers, 25 attrs) |
| `gettext_read_mo` | `GettextMoReader::readFile()` .mo catalog read + unpack (500 entries) |
| `currency_format` | `_c()` currency formatting (ICU NumberFormatter + ResourceBundle) |
| `number_format` | `_n()` decimal formatting (ICU NumberFormatter) |
| `date_format` | `_d()` date formatting (ICU IntlDateFormatter) |
| `webrequest_params_loop` | per-key `setParameter()` loop applying 20 params (old path) |
| `webrequest_params_bulk` | `withParameters()` applying 20 params (new path) |
| `header_normalize` | `WebResponse::normalizeHttpHeaderName()` over 5 header names |
| `templatelayer_call` | two `TemplateLayer` magic accessor calls |
| `locale_parse` | `QuioteLocale::parseLocaleIdentifier()` on a locale with options |
| `apcu_validator_eval_per_call` | `ValidationService`'s old APCu path: `eval()` of a compiled validators.xml snippet on every call |
| `apcu_validator_closure_cached` | new path: same snippet compiled into a `Closure` once, reused via `Closure::call()` |
| `routing_scan_live` | `AttributeRouteScanner::scan()`: recursive `glob()` + `require_once` + `ReflectionClass` per action, over the sandbox's module tree |
| `routing_scan_compiled_ir` | `RoutingIrDumper::load()`: the same routes loaded from a pre-dumped `return [...]` PHP artifact |

## Tier 1 results (PHP 8.5.8, min ns/op)

| benchmark | baseline | tier1 | change |
|---|---:|---:|---:|
| `config_resolve_format` | 4853.5 | 1087.9 | −77.6% |
| `webresponse_construct` | 128.0 | 120.2 | −6.1% |
| `view_render_layers` | 2695.5 | 2179.6 | −19.1% |
| `gettext_read_mo` | 86639.0 | 634.0 | −99.3% |

Notes:
- `config_resolve_format` / `gettext_read_mo` gains reflect worker-lifetime
  memoization eliminating repeated filesystem probes and .mo re-parsing.
- `webresponse_construct` is modest because PHP already shares the literal-array
  zval copy-on-write; the larger benefit there is reduced per-instance memory,
  which a timing benchmark does not capture.
- Absolute numbers are machine-dependent; the *relative* change is the signal.

## Tier 2 results (PHP 8.5.8, min ns/op)

| benchmark | before | after | change |
|---|---:|---:|---:|
| `date_format` | 71159.0 | 11249.4 | −84.2% |
| `currency_format` | 57279.4 | 22424.5 | −60.9% |
| `number_format` | 12891.5 | 7419.5 | −42.4% |
| `webrequest_params` (loop → bulk) | 14410.2 | 8233.6 | −43% |

Notes:
- Formatter gains come from caching the immutable, locale-keyed ICU objects
  (`NumberFormatter` / `IntlDateFormatter` / `ResourceBundle`) for the worker
  lifetime instead of rebuilding them on every `_c()` / `_n()` / `_d()` call.
- `webrequest_params` compares the old per-key `setParameter()` loop against the
  new bulk `withParameters()` path now used by `ActionExecutor`.

## Tier 3 results (PHP 8.5.8, min ns/op)

| benchmark | before | after | change |
|---|---:|---:|---:|
| `locale_parse` | 1565.8 | 127.0 | −91.9% |
| `header_normalize` | 1872.1 | 595.9 | −68.2% |
| `templatelayer_call` | 1479.7 | 1024.7 | −30.7% |

Notes:
- These are micro-operations, but each runs many times per request (header
  normalization on every header access, locale parsing on each locale switch,
  magic accessors during layout construction), so the memoization compounds.
- `Context::setRequest()` also skips building its debug string unless debug
  logging is enabled; not separately benchmarked.

## Item 3 results (PHP 8.5.8, min ns/op)

| benchmark | before | after | change |
|---|---:|---:|---:|
| `apcu_validator` (eval per call → closure cached) | 7270.9 | 518.6 | −92.9% |

Notes:
- `ValidationService`'s APCu path used to `eval()` the raw compiled
  validators.xml source on every single dispatch — `eval()`'d code is never
  opcache-cached, so every request paid a full lex/parse/compile of the same
  source. It's now compiled into a `Closure` once per `(configFile, context)`
  key and reused via `Closure::call()` (which rebinds `$this` per call, so the
  cached closure's `$this->getContext()` still resolves against whichever
  `ValidationService` instance is actually running, not whichever one
  happened to trigger the first, cache-populating `eval()`).
- `bench()`'s warmup already primes the cache before the timed rounds, so
  `apcu_validator_closure_cached`'s number is the steady-state (warmed worker)
  cost, matching how the other cache-driven benchmarks in this suite are
  read.
- The benchmark snippet mirrors `RuntimeArrayEmitter`'s real output shape
  (method-gated block, a few validator instantiations, a
  `$this->getContext()` call) rather than exercising the full framework
  dispatch path, to isolate the eval()-vs-cached-closure mechanism itself.

## Item 6 results (PHP 8.5.8, min ns/op)

| benchmark | before | after | change |
|---|---:|---:|---:|
| `routing_scan` (live scan → compiled IR load) | 378401.8 | 38655.0 | −89.8% |

Notes:
- `AttributeRouting::build()` used to run `AttributeRouteScanner::scan()` on
  every `Routing` construction -- a recursive `glob()` per module `Actions/`
  tree plus a `require_once` + `new ReflectionClass` + attribute read per
  action class. In worker mode that's a one-time boot cost; under classic
  PHP-FPM it's a per-request cost. `core.routing.trust_compiled_ir` (default
  false) lets `build()` load the same routes from a `return [...]` artifact
  dumped by `quiote cache:warmup` instead, skipping the scan (and the
  `require_once`/reflection it forces on every action class, dispatched or
  not) entirely.
- Both cases run against the real sandbox module tree (via the real
  `AttributeRouteScanner`/`RoutingIrDumper`, not a synthetic stand-in), so the
  absolute numbers scale with however many `#[Route]`-attributed action
  classes a given app has.
- `Routing::__construct()` still computes `CompiledMatcherDumper`'s
  signature over the resulting `RouteCollection` afterward either way (to
  locate a compiled-matcher dump) -- that's comparatively cheap in-memory
  hashing over already-built routes, not filesystem I/O, so it's unaffected
  by (and not part of) this benchmark.
