# Validator Compiler Plan

## Background

An SQL injection incident traced back to a validator config that a developer
believed restricted input to an allowlist:

```xml
<validator class="string" values="pending,approved,rejected">
    <argument>status</argument>
</validator>
```

`values` is not a parameter `StringValidator` reads. `ValidatorConfigHandler`
merges every XML attribute into the validator's parameter bag with no
whitelist (`ValidatorConfigHandler.php:203-208`), and `ParameterHolder`
stores whatever it's given (`ParameterHolder.php:196-200`). The parameter
was silently absorbed, never checked, and the raw value reached the action.

Two independent fixes follow from this:

1. **Compile-time rejection of unknown validator parameters** — a validator
   declares the parameter names it understands; the config handler rejects
   anything else at compile time instead of silently ignoring it. This
   applies regardless of file format and should ship first, cheaply, against
   the existing XML corpus.
2. **A validator compiler pipeline** (this document) — extract the XML
   parsing logic in `ValidatorConfigHandler` into a format-agnostic
   intermediate representation (IR), so that:
   - XML validators can be pre-compiled into plain, opcacheable PHP that is
     committed to the repo (like today's compiled config cache, but
     source-controlled and hand-readable).
   - Greenfield code can skip XML entirely and hand-author the same PHP
     format directly.
   - A future `quiote compile validators` CLI is a thin `argv → service`
     adapter over this pipeline, not the owner of the logic.

This plan only lays the plumbing (IR, emitters, entrypoints). It does not
implement a CLI framework — there is none in this project yet, and it will
be rewritten separately. The pipeline must be fully usable via plain PHP
function/service calls before any CLI exists.

---

## The pipeline shape

```
front-end (parse)            middle (IR)              back-end (emit)
─────────────────            ───────────              ───────────────
XmlConfigParser::run ─┐                          ┌─► RuntimeArrayEmitter  (today's cache snippets)
                      ├─► ValidatorPlan (IR) ─────┤
PHP-builder file ─────┘   format-agnostic         └─► FluentSourceEmitter (committable PHP)
(future: YAML/array)
```

The runtime only ever loads plain PHP (today's compiled cache, or a
committed hand-written/generated file). XML is just one front-end that
produces the same IR as any other format would. This is the reason
"use XML or don't" falls out for free: the loader doesn't know or care
which front-end produced the file it's including.

---

## Phase 1 — Extract the IR out of `ValidatorConfigHandler`

`ValidatorConfigHandler::getValidatorArray()` (`ValidatorConfigHandler.php:199`)
currently does two jobs in one DOM walk: it interprets the XML *and* emits
`new X(); ->initialize(); ->addChild();` snippet strings. Split them.

```php
namespace Quiote\Validator\Compiler\Ir;

/** Format-independent description of one validator config file. */
final class ValidatorPlan {
    /** @var ValidatorNode[] top-level nodes, in document order */
    public array $nodes;
    public string $sourceRef;      // origin path, for diagnostics + generated-file header
}

final class ValidatorNode {
    public string $validatorClass;        // resolved FQCN (via resolveClass())
    public array  $arguments;             // request param names this reads
    public string $base;                  // <arguments base="...">
    public array  $parameters;            // the checked param bag (unknown-parameter check runs here)
    public array  $errors;
    public array  $methods;               // ['read'|'write'|...] or [''] = always
    public array  $children;              // nested ValidatorNode[] for and/or/not/xor
}
```

`ValidatorPlanBuilder` is the current `getValidatorArray`/
`processValidatorElements` traversal with the string-emission removed — it
returns a `ValidatorPlan` instead of PHP source fragments.

The runtime path is then reassembled as:

```
ValidatorConfigHandler::execute() = ValidatorPlanBuilder → RuntimeArrayEmitter
```

`RuntimeArrayEmitter` must reproduce the exact snippets and
`declareParameters()` whitelist seeds emitted today.

**This must be a pure refactor.** Golden-file the compiled output for the
existing validator config corpus before and after, and assert it is
byte-identical. This de-risks every phase that follows, since all future
back-ends (CLI emitter, future format front-ends) sit on top of the same
`ValidatorPlanBuilder`.

The unknown-parameter check (validator declares `getAcceptedParameters()`;
handler rejects anything else) belongs inside `ValidatorPlanBuilder`,
returning `Diagnostic` objects rather than throwing directly — so a future
CLI can report every problem in a file in one pass instead of dying on the
first.

---

## Phase 2 — Public API surface

Value objects and a facade, independent of any CLI, fully testable via
plain PHP calls.

```php
namespace Quiote\Validator\Compiler;

final class ValidatorCompiler {
    /** find validator sources under given roots (default: %core.module_dir%/*/Validate/*.xml) */
    public function discover(iterable $roots): array;              // ValidatorSource[]
    public function parse(ValidatorSource $src): ValidatorPlan;    // front-end + IR (+ diagnostics)
    public function emit(ValidatorPlan $p, EmitterInterface $e): EmittedArtifact;
    public function compile(ValidatorSource $src, EmitterInterface $e): CompilationResult; // convenience
}

interface EmitterInterface { public function emit(ValidatorPlan $p): EmittedArtifact; }

final class EmittedArtifact   { public string $phpSource; public string $checksum; public string $targetHint; }
final class CompilationResult { public ?EmittedArtifact $artifact; /** @var Diagnostic[] */ public array $diagnostics; }
final class Diagnostic        { public string $severity; public string $code; public string $message; public string $where; }

interface ArtifactWriter { public function write(EmittedArtifact $a, string $target): void; } // filesystem impl kept separate
```

The compiler never writes output itself — it returns `EmittedArtifact` +
`Diagnostic[]`. The eventual CLI decides the destination and exit code.
`--check` (drift detection, gofmt-style) is: emit, compare checksum against
the committed file, nonzero exit on mismatch — a one-liner once
`EmittedArtifact::checksum` exists.

---

## Phase 3 — Committable/opcacheable output format

Emit a pure, side-effect-free file that returns a registrar closure:

```php
<?php
// @generated by `quiote compile validators` from modules/Orders/Validate/orders.xml
// source-sha256: 9f2c…  — regenerate; do not edit by hand
return static function (\Quiote\Validator\ValidatorBuilder $v): void {
    if ($v->method() === 'write') {
        $v->string('username')->trim()->minLength(3)->maxLength(32);
        $v->enum('status', ['pending', 'approved', 'rejected']);
    }
};
```

Opcache caches this like any PHP file; `include` returns the closure with
no work done at include time. A greenfield developer who never touches XML
writes this file by hand (minus the `@generated` header) — same runtime
path either way.

`FluentSourceEmitter` maps `ValidatorNode`s to fluent calls using the same
`getAcceptedParameters()` metadata plus a per-validator param→method table,
e.g.:

```php
StringValidator::class  => ['min' => 'minLength', 'max' => 'maxLength', 'trim' => 'trim', 'utf8' => 'utf8'],
InarrayValidator::class => ['values' => 'oneOf', 'sep' => '__csv', 'case' => 'caseSensitive', 'strict' => 'strict'],
```

**Unmappable parameters are the security payoff of this phase.** When a
parameter has no fluent mapping for its validator class — exactly the
`values`-on-a-`StringValidator` shape from the incident — the emitter
raises `Diagnostic(code: UNMAPPABLE_PARAM)` and either refuses (strict mode)
or emits a `// FIXME:` passthrough. The compile pass becomes a second/third
audit of the same bug class, independent of the config-handler check in
Phase 1.

**Determinism requirement:** no timestamps or other volatile content in the
header (unlike `BaseConfigHandler::generate()`, which stamps a date).
Use `source-sha256` and deterministic node ordering so recompiling
unchanged XML produces a zero-diff file — otherwise every compile run
churns git history.

---

## Phase 4 — Runtime consumption entrypoint

```php
namespace Quiote\Validator\Compiler\Runtime;

final class CompiledValidatorRegistry {
    /** Resolve the compiled/hand-written PHP-builder file for this action+method,
     *  include it (opcache-backed), and apply the returned closure to $builder. */
    public function apply(string $module, string $action, ValidatorBuilder $builder): void;
}
```

Wired into `Action`'s default `registerValidators()` (the existing hook
that runs before `ValidationManager::execute()` and before strict-mode
parameter pruning). Committing the generated file is all that's needed to
activate it — no per-action boilerplate.

`ValidatorBuilder` itself is a thin fluent facade over the same
`$validationManager->addChild($validator)` call the compiled XML path uses
today — no new enforcement path, no new bypass surface. The strict-mode
whitelist is still derived from each registered validator's declared
arguments, so a parameter with no builder call is still pruned before the
action runs.

---

## Phase 5 — Artifact writing / CLI-readiness

`ArtifactWriter` (filesystem implementation) plus the checksum-based
`--check` comparison. After this phase, a CLI is roughly:

```
parse flags → discover/compile → ArtifactWriter (or --check compare) → print Diagnostic[] → exit code
```

No pipeline logic lives in the CLI layer itself.

---

## Relationship to the config system rewrite

`ValidatorPlan` being format-agnostic is the same bet as the `FormatDriver`
abstraction in `CONFIG_SYSTEM_REWRITE_PLAN.md`. When PHP-array/YAML
validator configs land, they build the same IR via a new front-end; the
emitters and runtime loader don't change. This compiler is a concrete
first vertical slice of that rewrite — validators are the right pilot
config type because they're where the SQLi incident happened and where the
safety payoff of getting this right is highest.

---

## Sequencing

1. Extract IR (`ValidatorPlanBuilder` + `RuntimeArrayEmitter`), rewire
   `ValidatorConfigHandler::execute()` through them, golden-file test for
   byte-identical compiled output. Pure refactor, no behavior change.
2. Public API surface (value objects, `ValidatorCompiler` facade,
   `ValidatorSourceLocator`). Callable and tested, no CLI.
3. `FluentSourceEmitter` + `ValidatorBuilder` + per-validator param→method
   maps. Produces the committable format; diagnostics for unmappable
   parameters.
4. `CompiledValidatorRegistry` + `Action::registerValidators()` default
   wiring. Committed PHP now runs, opcache-backed.
5. `ArtifactWriter` + checksum/`--check` plumbing. CLI-ready.

Phase 1 is the load-bearing step — everything else assumes the IR
faithfully represents what the existing XML pipeline does today.
