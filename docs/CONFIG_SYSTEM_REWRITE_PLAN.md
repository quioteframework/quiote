# Config System Rewrite Plan

## Status (as of this implementation pass)

Phases 1, 4, and 5 are implemented and tested in full, generically, for
any config type. Phase 2 is implemented for all 12 remaining DOM-walking
config handlers (everything except `ValidatorConfigHandler`, a deliberate
exception — see below; `FilterConfigHandler` was a 13th but the filter
system itself was later deleted entirely as dead code — see below). Phase
3 is wired into `ConfigCache`'s live dispatch: an app can drop a
`.php`/`.yaml` file next to an existing `.xml` one and it's autodetected
(PHP > YAML > XML), or force a format unconditionally via the
`core.config_format` setting. Phase 6 (migration guide) is not written.
Concretely:

- **`Quiote\Config\Format\FormatDriverInterface`**, `PhpArrayFormatDriver`,
  `YamlFormatDriver` (via `symfony/yaml`, now a real dependency),
  `XmlFormatDriver` (wraps the untouched `XmlConfigParser` pipeline, bound
  to one `IArrayConfigHandler` handler instance — see Phase 2 below for
  why a driver can't be config-type-agnostic), and `FormatDriverRegistry`
  (extension → driver, plus `parent`/`imports` resolution across formats).
- **`ArrayMergeStrategy`** (Phase 4) and **`DirectiveExpander`** (Phase 5,
  `%directive%` expansion + literal-boolean coercion over array values,
  reusing `Toolkit::literalize()`) are both generic — every driver and
  every future migrated handler shares them.
- **`Quiote\Config\IArrayConfigHandler`**: the new handler contract
  (`toCanonicalArray(XmlConfigDomDocument): array` +
  `executeArray(array, ?string): string`), additive to
  `IXmlConfigHandler` — existing handlers that haven't implemented it are
  completely unaffected.
- **Migrated to `IArrayConfigHandler` (12 of 13 remaining handlers), each
  with a golden-file or evaluated-output parity test against its
  pre-refactor behavior, and most with a `.php` (and, for the pilot,
  `.yaml`) format-driver test proving the same handler compiles a non-XML
  source:**
  - `SettingConfigHandler` — the pilot; chosen because its output was
    already effectively a flat, dot-keyed array. Also proven with an XML
    `parent` during a strangler migration
    (`SettingConfigHandlerFormatDriverTest`).
  - `FactoryConfigHandler` — the static factory-ordering/startup-sequence
    logic (`getFactoryDefinitions()`) turned out to have no XML-specific
    content at all; only the per-factory `class`/`params` extraction was
    ever DOM-derived.
  - `RbacDefinitionConfigHandler`, `TestSuitesConfigHandler`,
    `ModuleConfigHandler`, `CachingConfigHandler`,
    `ReturnArrayConfigHandler`, `ConfigHandlersConfigHandler` — each
    handler's existing DOM-walk already produced a canonical-shaped array;
    migration was "stop generating code inline, return the array, generate
    code from it in a second method."
  - `DatabaseConfigHandler`, `OutputTypeConfigHandler`,
    `TranslationConfigHandler` — order-dependent validation (e.g. "a
    default must be declared by the time this block is seen," which
    depends on the sequence `<ae:configuration>` blocks are walked in, not
    just the final data) stayed in `toCanonicalArray()`; validation that's
    a pure function of the finished array (e.g. "is the default database
    actually defined") moved to `executeArray()`. Getting this split wrong
    in `ModuleConfigHandler` initially double-prefixed setting keys — caught
    by a test before it shipped, not by inspection.
  - `CompileConfigHandler` — inherently about resolving and reading the
    files a `<compiles>` list points at, so most of the "extraction" work
    unavoidably stays in `toCanonicalArray()`; `executeArray()` is nearly a
    passthrough to `generate()`.
  - `ReturnArrayConfigHandler` — this handler's entire purpose is "turn a
    config file into a plain array," so for a PHP/YAML source the
    canonical array *is* the source; only XML needs the recursive
    `convertToArray()` walk.
- **`ValidatorConfigHandler`** is the one handler NOT migrated to
  `IArrayConfigHandler` — deliberately. It already has its own IR
  (`Quiote\Validator\Compiler\ValidatorPlanBuilder`/`ValidatorPlan`, see
  docs/VALIDATOR_COMPILER_PLAN.md) that predates and is more specific than
  the generic array contract, and should stay on it rather than also
  adopting this one.
- **`FilterConfigHandler` was migrated in an earlier pass, then deleted
  entirely** (not just left unmigrated) once it was confirmed to be dead
  code: the Agavi-style filter chain it configured (`Quiote\Filter\*`,
  `action_filters.xml`/`global_filters.xml`) was fully superseded by the
  PSR-15 middleware pipeline, and every filter class left behind was
  either an empty legacy stub or genuinely unreachable at runtime — see
  `git log` for the removal commit. `config_handlers.xml` no longer
  registers any filter-related handler pattern.
- **Phase 3 is now wired into the live dispatch path, not just the opt-in
  `FormatAwareConfigCache`.** `ConfigCache::checkConfig()` resolves the
  physical file to read via a new `resolveConfigFormat()` step, inserted
  before the existing `is_readable()` check:
  - **`core.config_format`** (unset by default): when set to `'php'`,
    `'yaml'`, or `'xml'`, that format is used deterministically for every
    config — e.g. with it set to `'php'`, a directive like
    `%core.config_dir%/settings.xml` resolves to `settings.php` regardless
    of whether `settings.xml` also exists. If the forced format's file is
    missing for a given config, that's a hard `UnreadableException`
    (matching this codebase's existing "ambiguity throws" philosophy —
    e.g. an undefined default database) rather than a silent fallback.
  - **Unset**: autodetect, first match wins, priority PHP > YAML > YML >
    XML. A codebase that is 100% XML today sees zero behavior change —
    `resolveConfigFormat()` only ever finds the one `.xml` file that
    already existed, so the resolved path is identical to before and the
    change is a pure no-op until a `.php`/`.yaml` sibling actually appears
    or `core.config_format` is set.
  - The **logical name** used for `config_handlers.xml` pattern matching
    (e.g. still literally `settings.xml`) is untouched — only the
    *physical* file `executeHandler()` reads changes. This is what let the
    wiring avoid touching `config_handlers.xml` or `Controller.php`'s
    directive definitions at all: patterns keep matching by their
    original, XML-shaped name; resolution happens one layer below that.
  - `executeHandler()` branches on the resolved file's extension: `.xml`
    goes through the completely unchanged `XmlConfigParser`/`execute()`
    path; anything else requires the handler to implement
    `IArrayConfigHandler` (13 of 14 do — see above) and goes through
    `FormatDriverRegistry::forHandler()` + `executeArray()`. A resolved
    non-XML file for the one handler that doesn't implement it
    (`ValidatorConfigHandler`) is a clear `ConfigurationException`, not a
    silent misparse.
  - **Cache-key/APCu correctness:** the compiled-cache filename
    (`ConfigCache::getCacheName()`) and the APCu cache key
    (`APCuConfigCache::getConfigKey()`) are both derived from the
    *resolved* physical path, not the logical one — otherwise switching
    which format supplies a config (a new sibling file appearing, or
    `core.config_format` changing) could silently keep serving a cache
    entry compiled from a different source file, especially under APCu,
    whose warm-cache-hit fast path never re-checks the filesystem at all.
  - `Quiote\Config\Format\FormatAwareConfigCache::checkConfig()` still
    exists as a separate, explicit-base-path entrypoint (useful when a
    caller wants to resolve+compile a config without an extension already
    baked into a directive) but is no longer the only way to get
    extension-agnostic behavior — the main dispatch path now has it too.

## Background

The current config pipeline is:

```
*.xml → XmlConfigParser (XInclude + XSD validate + XSL normalize)
      → XmlConfigHandler::execute(DOMDocument)
      → compiled PHP string
      → cached file
```

There are 16 XSL transforms and 17 XSD schemas — one per config type. The XSL transforms normalize different XML dialects into a canonical form before the handlers process them. This is the real complexity to abstract away.

The goal is to support multiple config formats while keeping XML working for existing projects.

---

## Format Options

**PHP arrays** — the best fit for a PHP framework and the recommended primary format. Laravel/Lumen use this approach. Pros: zero parsing (opcache'd), full IDE completion, composable with `require`/`include`, comments via `//`, type-safe values, no new dependencies. Cons: code in config (a feature, not a bug). `parent` chains become `array_replace_recursive(require 'base.php', [...overrides])`.

**YAML** — popular, should be supported. Pros: familiar to anyone coming from Kubernetes/Docker/Symfony/Rails, readable, supports comments. Cons: the Norway problem (`no` → `false`), implicit typing gotchas, multiline strings are awful, anchors/aliases obscure what's happening. Use `symfony/yaml` or the `yaml` PECL extension. Worth supporting but not worth recommending.

**JSON** — skip. No comments is a dealbreaker for config files. JSONC has no standard PHP parser.

**TOML** — no.

**Neon** (Nette's format) — worth considering as a third option. YAML-like but fixes most of YAML's irritants: unambiguous types, no Norway problem, PHP-native syntax for values. Has a good PHP parser (`nette/neon`). Niche but genuinely better than YAML.

**Recommendation: PHP arrays + YAML + XML (legacy).** Optionally Neon as a bonus.

---

## Phase 1 — Abstract the config loading layer

Introduce a `FormatDriver` interface that decouples format parsing from config handler logic:

```php
interface FormatDriver {
    public function load(string $path, string $environment, string $context): array;
    public function supports(string $path): bool;
}
```

Each driver is responsible for:
- Reading the file
- Resolving the **parent chain** (see Phase 4)
- Returning a **normalized PHP array** in a canonical schema per config type

Create three drivers:
- `PhpArrayFormatDriver` — requires the file, expects it to return an array
- `YamlFormatDriver` — parses YAML via `symfony/yaml`, same canonical schema
- `XmlFormatDriver` — wraps the existing `XmlConfigParser` + XSL pipeline, outputs same canonical schema

`FormatDriverRegistry` maps file extension → driver. `XmlConfigParser`/`XmlConfigHandler` stay untouched in this phase.

---

## Phase 2 — Change ConfigHandler contract

Currently: `execute(XmlConfigDomDocument): string`
New: `execute(array $config): string`

Rewrite each of the 14 ConfigHandlers to consume a plain array instead of DOM/XPath. The canonical array schema per config type is defined by what the XSL transforms currently produce — just represented as a PHP array instead of a DOM. Work through them one at a time (settings → factories → routing etc.), keeping tests green throughout.

Keep `XmlConfigHandler` as a thin adapter that runs the XSL pipeline and converts DOM output to the canonical array, so XML files keep working for free.

---

## Phase 3 — Extension-agnostic handler discovery

Currently `config_handlers.xml` maps `settings.xml` → `SettingConfigHandler`. Change pattern matching so:

- Patterns without extensions match any supported format: `settings` matches `settings.php`, `settings.yaml`, `settings.xml`
- Explicit extension in a pattern still works as an override
- Priority: PHP > YAML > XML when multiple exist (or configurable)

Migrate the framework's own internal configs (`config_handlers.xml`, `validators.xml`, `compile.xml`) to PHP arrays as part of this phase.

---

## Phase 4 — Parent/child inheritance (format-agnostic)

The XML `parent` attribute chains config files with deep merge semantics. Implement this in each driver:

```php
// PHP array format:
return [
    'parent' => __DIR__ . '/base.php',  // absolute or relative path
    'settings' => [ /* overrides only */ ],
];
```

```yaml
# YAML format:
parent: ../base.yaml
settings:
  foo: override
```

The driver resolves the parent chain recursively and applies `array_replace_recursive` with a configurable deep-merge strategy (so arrays can be replaced OR merged depending on a marker). The merge logic lives in an `ArrayMergeStrategy` class shared by all drivers.

---

## Phase 5 — Variable substitution and includes

`%core.quiote_dir%` substitution already works via `Toolkit::literalize()`. Extend it to run on array values after loading. YAML/PHP configs can use the same `%key%` syntax in string values.

For PHP configs, the file already has `require` available so includes are native. For YAML, support an `imports:` key (like Symfony) that merges additional files before parent resolution.

---

## Phase 6 — Documentation and migration guide

Write a short migration guide showing XML config alongside the PHP and YAML equivalents for each config type. Include the recommended PHP array format as the default in any new project scaffolding.

---

## What this is NOT

- Not removing XSL/XSD immediately — they stay as the XML path's implementation detail until all handlers are ported
- Not breaking existing XML configs — XML keeps working throughout
- Not adding TOML or JSON
- Not changing the caching layer — compiled PHP cache stays as-is
