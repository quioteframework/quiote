# Releasing

Quiote and the packages under `packages/` version and release independently
of each other. This has been true since `v4.0.0`: before that, every package
moved in lockstep with the framework's own tag.

## The framework

1. Land whatever changes belong in the release on `main`.
2. Prep the changelog:
   ```
   git-cliff --tag-pattern '^v[0-9]' --unreleased --tag vX.Y.Z --prepend CHANGELOG.md
   ```
   Review the diff, then commit it as `doc: prep vX.Y.Z` (or `doc: prep
   vX.Y.Z-RCn` for a release candidate).
3. Tag that commit `vX.Y.Z` (or `vX.Y.Z-RCn`) and push the tag.
4. `.github/workflows/release.yml` triggers on the `v*.*.*` tag: it re-runs
   the full test suite at that exact commit, generates release notes scoped
   to just that tag's commits (`--tag-pattern '^v[0-9]' --current`, which
   deliberately ignores `packages/*/v*` tags), and publishes a GitHub
   Release. A tag with a hyphen (`-RC1`, `-beta1`, ...) is marked
   prerelease automatically.
5. `.github/workflows/split.yml` mirrors the monorepo's `packages/` and root
   source into their own split repos, content-only (it no longer copies
   framework tags onto the split repos).

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
