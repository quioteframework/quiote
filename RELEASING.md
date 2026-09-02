# Releasing

Quiote and the packages under `packages/` version and release independently
of each other. This has been true since `v4.0.0`: before that, every package
moved in lockstep with the framework's own tag.

## The framework

1. Land whatever changes belong in the release on `main`.
2. Prep the changelog:
   ```
   git-cliff --tag-pattern '^v[0-9]' --exclude-path 'packages/**' --unreleased --tag vX.Y.Z --prepend CHANGELOG.md
   ```
   `--exclude-path 'packages/**'` keeps a commit scoped entirely to one or
   more packages out of the framework's own changelog -- it has its own
   `packages/<name>/CHANGELOG.md` for that (see below). A commit that also
   touches a root file (`composer.json`, `.github/**`, ...) is kept either
   way: the exclusion only drops a commit whose *every* changed file is
   under `packages/`.

   Review the diff, then commit it as `doc: prep vX.Y.Z` (or `doc: prep
   vX.Y.Z-RCn` for a release candidate).
3. Tag that commit `vX.Y.Z` (or `vX.Y.Z-RCn`) and push the tag.
4. `.github/workflows/release.yml` triggers on the `v*.*.*` tag: it re-runs
   the full test suite at that exact commit, generates release notes scoped
   to just that tag's commits (`--tag-pattern '^v[0-9]' --exclude-path
   'packages/**' --current`, which deliberately ignores `packages/*/v*`
   tags and package-only commits), and publishes a GitHub Release. A tag
   with a hyphen (`-RC1`, `-beta1`, ...) is marked prerelease automatically.
5. `.github/workflows/split.yml` mirrors the monorepo's `packages/` and root
   source into their own split repos, content-only (it no longer copies
   framework tags onto the split repos).

### RCs accumulate in CHANGELOG.md; fold them into one entry at GA

Step 2's `--unreleased --prepend` run is repeated for every RC, so while a
release is in flight, `CHANGELOG.md` grows one heading per RC --
`## [4.4.0-RC1]`, then `## [4.4.0-RC2]`, each with only the commits new
since the previous one. That's useful mid-flight (it shows exactly what
changed between RCs) but wrong as permanent history: nobody consuming a
stable release wants to reconstruct it from N RC diffs, and per [Keep a
Changelog](https://keepachangelog.com/), the published log should read as
one entry per shipped version.

So the GA tag's changelog prep (step 2, run with the GA `vX.Y.Z`) must also
fold every preceding `-RCn` heading for this version into that one new GA
section before committing: merge each RC's entries into the GA heading, in
the original per-group ordering (oldest first, matching `sort_commits`),
drop the now-redundant `-RCn` headings entirely, and keep the GA tag's own
date. The GitHub Release notes (step 4) don't need this: `--ignore-tags
'-RC[0-9]+$'` already scopes those to the last-GA..this-GA range
automatically, so they're never split across RC headings in the first
place -- only the committed `CHANGELOG.md` needs the manual fold.

## A package

Each package under `packages/<name>/` mints its own `packages/<name>/vX.Y.Z`
tags and keeps its own `packages/<name>/CHANGELOG.md`, scoped to commits that
touch only that package's directory. A framework release does not imply a
package release, and vice versa.

1. Land the change on `main` (normal conventional-commit message, scoped to
   the package, e.g. `fix(db-propulsion): ...`).
2. Regenerate that package's changelog:
   ```
   composer changelog:package -- <name>
   ```
   (wraps `bin/package-changelog.sh <name>`, which runs git-cliff with
   `--include-path packages/<name>/**` and `--tag-pattern
   ^packages/<name>/v`, scoping both the commits considered and the tag
   used to compute "since the last release" to that one package.)

   This writes an `## [unreleased]` section. Review the diff, decide the
   version bump (semver, judged from the commits: `fix` → patch, `feat` →
   minor, a `!`/`BREAKING CHANGE` commit → major), then re-run with the
   version pinned so the heading is correct instead of "unreleased":
   ```
   bin/package-changelog.sh <name> X.Y.Z
   ```
3. Commit the changelog as `doc(<name>): prep packages/<name>/vX.Y.Z`.
4. Tag that commit `packages/<name>/vX.Y.Z` and push the tag.
5. `.github/workflows/release-package.yml` triggers on the
   `packages/*/v*.*.*` tag: it re-runs the full test suite at that commit
   (the tag can point at a commit older than `main`'s tip), generates
   release notes scoped to just that package's commits since its own
   previous tag, tags the package's already-synced split repo
   (`quioteframework/<name>`) with a plain `vX.Y.Z`, and publishes a GitHub
   Release there — not on the monorepo.

### First release of a package

If a package has never been tagged before (everything under `packages/`
was backfilled to a `## [4.0.0]` heading in its `CHANGELOG.md` without a
matching git tag — see `adc91b140`), mint that baseline tag retroactively
at the commit the backfill happened at before cutting the real next
release, so the next `bin/package-changelog.sh` run has a boundary to scope
against instead of re-dumping the package's entire history:
```
git tag packages/<name>/v4.0.0 adc91b140
```

## Why two separate mechanisms

`packages/` are first-party Quiote packages, held to the same PHP 8.5 /
PHPStan level 9 / test bar as the framework itself — but a one-line fix to
`db-propulsion` doesn't need `quiote` to cut a new version, and a framework
release doesn't need every package to bump either. Splitting the tag
namespaces (`v*.*.*` vs `packages/*/v*.*.*`) and the changelog scoping
(`--tag-pattern` + `--include-path` per package) is what makes that
possible without the two ever colliding.
