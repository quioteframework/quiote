# Pending release: framework v4.2.0-RC1 + first package tags

Working checklist for the release being prepared as of 2026-08-19. Delete this file
once the tags are pushed — `RELEASING.md` is the durable process; this is only the
state and the decisions already made for *this* release.

## Decisions already taken

- **Framework: `v4.2.0-RC1`.** 49 commits since `v4.1.0` (`6976c7945`): 20 `feat`,
  21 `fix`, 3 `refactor`, no `!` or `BREAKING CHANGE` markers.
- **It contains an unmarked breaking change, accepted knowingly.** `9b2b1f72c` and
  `a29b90306` moved 14 public classes out of the framework install —
  `Quiote\Filesystem\*` (10), `Quiote\Storage\{ObjectMetadata,ObjectStoreClientInterface,ObjectStoreException}`
  and `Quiote\Session\ObjectStoreSessionPersistence`. Namespaces are preserved and
  every class landed in a package, but the framework has no `require` or `replace`
  for either package, so an app on 4.1.0 that used them gets class-not-found on
  upgrade. Strict semver says 5.0.0; 4.2.0 was chosen because the 4.x line is days
  old (`v4.0.0` 2026-08-11) and the affected apps are known. **An `UPGRADING.md`
  saying "also require `quioteframework/filesystem` / `quioteframework/storage`" is
  owed before this ships.**
  Re-adding a framework `require` is not an option: `packages/filesystem` requires
  `quioteframework/quiote: ^4.0`, so it would be circular. `3194e31e4` added the
  `storage` require for real code reasons; `9b2b1f72c` then moved that code out, so
  reverting it in `b125bb817` was correct.
  `UPGRADING.md` now exists and covers this — including the two failure modes, since a
  `plugins` entry for a missing class is logged and skipped rather than fatal.
- **Replay packages: `4.0.0-RC1`, not stable.** They have never run outside this
  monorepo's own suite and a single audit pass found 43 defects in them. Isolated mode
  has since landed, so they are feature-complete — but "feature-complete and never run
  in anger" is exactly what an RC is for, and the database-isolation caveat below is
  the kind of thing a first real user finds. `^4.0` *is* in range for `4.0.0-RC1` (verified
  against `composer/semver`) — prereleases are gated by install-time stability, not
  by the constraint — so the existing inter-package `^4.0` requirements need no
  change and consumers opt in with `^4.0@RC` or `minimum-stability: RC`.
  `release-package.yml` already marks a hyphenated tag prerelease (`*-*)`, line 95).
- **Extracted packages stay stable at `4.0.0`.** `storage` and `filesystem` are the
  same classes that shipped inside `quioteframework/quiote` at 4.0.0 and 4.1.0, only
  relocated — relocated code keeps its track record. `docs` predates the backfill
  (2026-08-09) and has not changed since.

## Still open

- [x] **Isolated replay mode** — landed in `6fc09d811`. `quiote replay` now defaults
      to it, `--live` opts into the old behaviour behind `replay.allow_live`, and
      `ReplayTestCase` replays in isolation so an emitted test for a recorded write is
      safe on every CI build. One caveat worth knowing before the RC goes out:
      **Doctrine and Propulsion can isolate the database, Eloquent and Cycle cannot.**
      Doctrine's DBAL driver middleware is called instead of the real statement;
      Propulsion's observer fires after the query has run, but `Propulsion::setConnection()`
      lets the connection itself be replaced, so it substitutes a ledger-backed one per
      datasource. Eloquent's `QueryExecuted` event and Cycle's PSR-3 logger are
      after-the-fact with no connection-level equivalent, so `IsolatedReplay` refuses
      outright rather than silently hitting a real database. And the whole thing has
      still only run against this repo's own suite — which is what `-RC1` says.
- [x] **`cloud-azure`: `4.1.0-RC1`.** Its bulk (blob/table client) is in production via
      `session-azure`, but its 3 post-backfill commits include `ed6b8e2a5`, which added
      the AAD/`az login` credential path — and that has never authenticated against real
      Azure. Same reasoning that made replay an RC applies to that half of the package.
      Nothing regresses today — the package has no tag at all, so nobody is being moved
      from a stable release onto a prerelease — but it does put a constraint on the
      *next* round: `session-azure` and `filesystem-azure` both require
      `quioteframework/cloud-azure: ^4.0`, so neither can be tagged **stable** until
      `cloud-azure` is, or an app on `minimum-stability: stable` cannot resolve them.
      Whoever exercises the AAD path against real Azure unblocks all three.
- [x] **`UPGRADING.md`** — written, covering the 14 relocated classes (which packages,
      which requires already pull them in transitively, and both failure modes), the
      `addStateReset()` seam for plugin authors, and the RC-stability flags the replay
      packages and `cloud-azure` need to resolve. Includes the `AzureBlobClient`
      constructor change for anyone tracking `cloud-azure` on `dev-main`.

## Order of operations

Order matters in two places: `split.yml` mirrors package **content** on a push to
`main`, and `release-package.yml` tags the *already-synced* split repo — so `main`
must be pushed before any package tag. And a package tag must not precede a tag it
depends on, or the dependency's `^4.0` will not resolve on Packagist.

1. Land everything on `main`. Changelogs are already committed (`bc91d641f`,
   `54d00dfe8`, `644dbb50a`).
2. Framework changelog prep:
   ```
   git-cliff --tag-pattern '^v[0-9]' --unreleased --tag v4.2.0-RC1 --prepend CHANGELOG.md
   ```
   Review, commit as `doc: prep v4.2.0-RC1`.
3. Push `main`. Wait for CI green (`split.yml` also mirrors package content here).
4. Push package tags **in this order** — dependency order, not alphabetical:

   ```
   packages/storage/v4.0.0            # no intra-monorepo deps
   packages/filesystem/v4.0.0         # needs storage
   packages/docs/v4.0.0               # independent
   packages/db-eloquent/v4.0.0        # 0 post-backfill commits
   packages/db-cycle/v4.0.0           # 0 post-backfill commits
   packages/cloud-azure/v4.1.0-RC1    # needs storage; RC for the AAD credential path
   packages/db-doctrine/v4.1.0
   packages/replay/v4.0.0-RC1
   packages/replay-storage/v4.0.0-RC1 # needs replay + storage
   packages/replay-pdo/v4.0.0-RC1
   packages/replay-propulsion/v4.0.0-RC1
   packages/replay-doctrine/v4.0.0-RC1
   packages/replay-eloquent/v4.0.0-RC1
   packages/replay-cycle/v4.0.0-RC1
   packages/replay-azure/v4.0.0-RC1   # last: needs replay-storage + cloud-azure
   ```
5. Tag and push `v4.2.0-RC1`. The hyphen makes `release.yml` mark it prerelease.

### `cloud-azure` and `db-doctrine` need the baseline dance first

Both have post-backfill `feat` work while their `CHANGELOG.md` still reads
`## [4.0.0]`, so tagging them 4.0.0 would publish a version whose changelog omits
what is in it. Per `RELEASING.md`'s "First release of a package":

```
git tag packages/cloud-azure/v4.0.0 adc91b140   # local only — never pushed
bin/package-changelog.sh cloud-azure 4.1.0-RC1  # now scoped to post-baseline commits
git commit -m 'doc(cloud-azure): prep packages/cloud-azure/v4.1.0-RC1'
git tag packages/cloud-azure/v4.1.0-RC1         # this one gets pushed

git tag packages/db-doctrine/v4.0.0 adc91b140   # local only — never pushed
bin/package-changelog.sh db-doctrine 4.1.0
git commit -m 'doc(db-doctrine): prep packages/db-doctrine/v4.1.0'
git tag packages/db-doctrine/v4.1.0             # this one gets pushed
```

The retroactive `v4.0.0` stays local: it exists only to give the generator a
boundary. Pushing it would make `release-package.yml` re-run the suite against
2026-08-13 code and publish a 4.0.0 release nobody asked for, and `^4.0` resolves
against `4.1.0` anyway.

### Do *not* tag every package

45 of 46 packages are untagged (only `db-propulsion` has any: `v4.0.1`), and ~21 of
them have unreleased work — `queue-db`, `queue-redis`, `filesystem-{azure,gcs,s3}`,
`cloud-{gcs,s3}`, `ratelimit`, `csrf`, `auth-oauth`, `scheduler`, `session-{azure,gcs,s3}`,
`telemetry-dashboard`, `worker-{roadrunner,swoole}` and others. None of it blocks
`v4.2.0-RC1`: packages version independently. Each needs the same baseline dance when
its turn comes. The list above is the minimum that makes 4.2.0-RC1's upgrade story
resolvable.

## Downstream, after the tags land

- `quiote-mcp-assistant`'s `composer.json` path-repos `replay`, `replay-azure`,
  `replay-storage`, `cloud-azure` and `storage`. Once tagged, replace the path
  repositories with real constraints — RC-stability flags while replay is an RC.
