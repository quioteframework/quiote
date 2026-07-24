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
