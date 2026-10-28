# Upgrading Quiote

Only the changes that need you to *do* something: removals, relocations, renames and
behavioural changes that a `composer update` will not sort out by itself. Newest first.
Everything else — features, fixes, refactors — is in [CHANGELOG.md](CHANGELOG.md).

Package versions move independently of the framework's (see [RELEASING.md](RELEASING.md)),
so a section here is titled by the framework release that introduced the change.

## 4.5.0 → 4.5.1

### Array parameters now carry validated values, and strict mode prunes inside them

**Action required if your validators use `<arguments base="…">` or your actions read array
parameters.** Four behaviours change together.

**Results are written back into the element.** A coercing or sanitizing validator under a
base (`StringValidator`, `NumberValidator`, `BooleanValidator`, `JsonValidator`) writes its
result to the argument's full path in the current element, for example `rows[0][Qty]` (or
`items[0]` for an empty argument under `items[]`), not to a top-level parameter named after
the argument. Actions that read `getParameter('rows')` now get native types (`int` for a
number, the trimmed string, decoded JSON) where they used to get the raw submitted strings.
Check code that relies on the strings under `strict_types`. Code that read the top-level
`Qty` written by the last row gets nothing now; read the row's own field instead.

**Nested writes keep their siblings.** A bracketed export (`rows[%2$s][QtyInt]`) or
`setParameter('rows[0][X]', …)` adds that one key to the submitted array. Previously it
replaced the whole root with only the exported keys.

**Strict mode prunes inside arrays.** A nested key that failed validation, or that no
validator covers, is removed from the array before the action sees it. A posted
`rows[0][Comment]` with no validator, or an injected `rows[0][IsAdmin]`, is gone. Add a
validator for every nested field your action reads.

**A validator on the array itself keeps only the container.** `IsNotEmptyValidator`,
`ArraylengthValidator` or `IssetValidator` on `rows` checks the array's shape, not its
contents, so on its own it leaves `rows` as an empty array. Give the fields their own
validators under `base="rows[]"`. A custom validator that checks every element itself can
override `Validator::coversArgumentSubtree()` to return `true`, and the contents then survive
as submitted.

An `export` to a plain name (no `%`, no brackets) from inside a wildcard base keeps its
meaning: one top-level parameter, which each element overwrites in turn. Quiote now logs a
warning for it. Export to `rows[%2$s][Name]` to keep one value per element.

`StringValidator` has a new `normalize(string): string` hook. A subclass that rewrites the
submitted string (stripping markup, for instance) overrides it, rather than calling
`export()` itself; the hook runs before the length checks, and its result is what the action
receives.

## 4.1.0 → 4.2.0-RC1

### Filesystem and object-store classes moved into their own packages

**Action required if you use any of them.** Fourteen public classes that shipped *inside*
`quioteframework/quiote` at 4.0.0 and 4.1.0 now live in two separate packages. **Every
namespace is unchanged**, so there is no code to edit — but the framework has no `require`
on either package, so upgrading alone does not install them.

`quioteframework/filesystem`:

| Class | Was |
| --- | --- |
| `Quiote\Filesystem\FilesystemManager` | `Quiote/Filesystem/` in the framework install |
| `Quiote\Filesystem\FilesystemPlugin` | " |
| `Quiote\Filesystem\FilesystemConfig` | " |
| `Quiote\Filesystem\FilesystemAdapterInterface` | " |
| `Quiote\Filesystem\ListableFilesystemInterface` | " |
| `Quiote\Filesystem\FilesystemDriverRegistry` | " |
| `Quiote\Filesystem\LocalFilesystemAdapter` | " |
| `Quiote\Filesystem\ObjectStoreFilesystemAdapter` | " |
| `Quiote\Filesystem\FilesystemStorageException` | " |
| `Quiote\Filesystem\FileNotFoundStorageException` | " |
| `Quiote\Session\ObjectStoreSessionPersistence` | `Quiote/Session/` in the framework install |

`quioteframework/storage`:

| Class | Was |
| --- | --- |
| `Quiote\Storage\ObjectStoreClientInterface` | `Quiote/Storage/` in the framework install |
| `Quiote\Storage\ObjectMetadata` | " |
| `Quiote\Storage\ObjectStoreException` | " |

`Quiote\Session\ObjectStoreSessionPersistence` is in `quioteframework/filesystem`, not in a
`session-*` package: it is the shared base the object-store session backends extend, and it
belongs with the object-store adapters it mirrors.

#### Whether this affects you

You already have these classes, transitively, if your `composer.json` requires any of:

| You require | Which requires |
| --- | --- |
| `quioteframework/cloud-azure`, `cloud-s3`, `cloud-gcs` | `quioteframework/storage` |
| `quioteframework/session-azure`, `session-s3`, `session-gcs` | `quioteframework/filesystem` (and so `storage`) |
| `quioteframework/replay-storage` | `quioteframework/storage` |

`quioteframework/filesystem-azure`, `-s3` and `-gcs` only **suggest**
`quioteframework/filesystem` — their clients are usable without it, and only their
`FilesystemManager` disk drivers need it — so having one of those installed is *not* enough.

#### The fix

```
composer require quioteframework/filesystem
```

`quioteframework/filesystem` requires `quioteframework/storage`, so that covers both. If all
you ever touched was `Quiote\Storage\ObjectStoreClientInterface` and friends — writing your
own object-store client, say — then `composer require quioteframework/storage` on its own is
enough.

Nothing else changes: no namespace edits, no config changes, and the `plugins` entry stays
exactly as it was.

```php
'plugins' => [
    \Quiote\Filesystem\FilesystemPlugin::class,
],
```

#### How it fails if you miss it

Two different symptoms, neither of them a clear "package missing" message, which is the
reason this section exists:

- **A `plugins` entry for `Quiote\Filesystem\FilesystemPlugin` is skipped, not fatal.**
  `PluginManager` logs `configured plugin "..." is not a Quiote\Plugin\PluginInterface` at
  `error` and carries on booting, because a `::class` constant still evaluates to a string
  for a class that no longer exists. The app comes up with no `FilesystemManager` in the
  container, and the first `$container->get(FilesystemManager::class)` fails there instead.
- **Direct use is a plain autoload failure**: `Class "Quiote\Filesystem\FilesystemManager"
  not found`.

If you are upgrading a running app, grep your logs for that `[PluginManager]` line before
concluding the upgrade was clean.

#### Why this is 4.2.0 and not 5.0.0

Removing classes from an install is a breaking change and strict semver says major. 4.2.0 was
chosen deliberately: the 4.x line is days old (`v4.0.0` was 2026-08-11), the classes are all
still present under their original namespaces, and the affected consumers are known and
few — one `composer require` is the whole migration. Re-adding a framework `require` was not
an option, because `quioteframework/filesystem` requires `quioteframework/quiote` and the
dependency would be circular.

### Plugin-owned static registries now clear themselves

**No action for applications. Relevant if you maintain a plugin with a static registry.**

`PluginManager::reset()` used to clear `FilesystemDriverRegistry` by name — core reaching
into one optional subsystem it happened to know about. It now runs whatever cleanups plugins
have contributed:

```php
PluginManager::addStateReset('my-driver-registry', static function (): void {
    MyDriverRegistry::reset();
});
```

Callbacks are keyed by label, so two plugins touching the same registry collapse into one
call. A plugin that keeps static state and does not register a reset will leak it between
tests in the same process — which is the same contract as before, just no longer with an
exception carved out for the framework's own filesystem registry.

### Some packages ship as release candidates, which affects how they resolve

The eight `quioteframework/replay*` packages are tagged `4.0.0-RC1` and
`quioteframework/cloud-azure` is tagged `4.1.0-RC1`. A prerelease is *in range* for a `^4.0`
constraint, but stability is filtered separately, so a project on the default
`minimum-stability: stable` will not resolve them.

**A `@RC` flag covers only the package it is written on, not that package's own
requirements.** `quioteframework/replay` has no RC dependencies, so pinning it alone works:

```
composer require quioteframework/replay:^4.0@RC
```

Every other replay package requires `quioteframework/replay` in turn, which is RC-only, so the
same command for one of those fails — `found quioteframework/replay[...] but it does not match
your minimum-stability`. Two ways through, both verified against these published versions:

```jsonc
// 1. Raise the floor, keep everything else stable.
{
    "require": { "quioteframework/replay-azure": "^4.0" },
    "minimum-stability": "RC",
    "prefer-stable": true
}
```

```jsonc
// 2. Stay on stable and name each RC package explicitly.
{
    "require": {
        "quioteframework/replay-azure": "^4.0@RC",
        "quioteframework/replay": "^4.0@RC",
        "quioteframework/replay-storage": "^4.0@RC",
        "quioteframework/cloud-azure": "^4.1@RC"
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

**Option 1 does not give you `cloud-azure` 4.1.0-RC1.** `prefer-stable` picks that package's
older stable `v4.0.0` — which predates the Azure AD credential path — and the build succeeds,
quietly, without it. If you need workload identity or `az login`, pin `cloud-azure` the way
option 2 does even when the rest of your config follows option 1. Dropping `prefer-stable`
instead would pull *every* dependency to its newest prerelease, which is worse.

- **`replay*`** is new, so there is nothing to migrate. It is an RC because it has never run
  outside this monorepo's own test suite. Recording is off unless configured; `quiote replay`
  replays in isolation by default and wants `replay.allow_live` plus `--live` before it will
  touch a real dependency.
- **`cloud-azure`** is an RC for one half of itself: the blob and table clients are in
  production, but the Azure AD credential path added in `ed6b8e2a5` — workload identity,
  `az login`, and the chain of the two — has never authenticated against real Azure.

#### If you tracked `cloud-azure` on `dev-main`

`AzureBlobClient`'s third constructor argument changed from a `string $accountKey` to an
`AzureCredential`. Wrap what you were passing:

```php
new AzureBlobClient($httpClient, $accountName, new SharedKeyCredential($accountKey));
```

That is the same Shared Key signing as before, byte for byte. Apps that build the client from
config through `AzureCredentialFactory` (which `session-azure` and `filesystem-azure` both
do) need no change — set `auth` to `shared_key`, `workload_identity`, `cli` or `chain`.
`shared_key` cannot authenticate the AAD-only APIs, so a container using one of the token
providers must use a non-`shared_key` value.
