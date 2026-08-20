# Upgrading Quiote

Only the changes that need you to *do* something: removals, relocations, renames and
behavioural changes that a `composer update` will not sort out by itself. Newest first.
Everything else — features, fixes, refactors — is in [CHANGELOG.md](CHANGELOG.md).

Package versions move independently of the framework's (see [RELEASING.md](RELEASING.md)),
so a section here is titled by the framework release that introduced the change.

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
`minimum-stability: stable` will not resolve them. Opt in per package:

```
composer require quioteframework/replay:^4.0@RC
composer require quioteframework/cloud-azure:^4.1@RC
```

Or set `"minimum-stability": "RC"` with `"prefer-stable": true`, which keeps every other
dependency on stable releases.

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
