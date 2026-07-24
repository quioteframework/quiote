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
