#!/bin/sh
# Generates packages/<name>/CHANGELOG.md, scoped to commits touching only
# that package's directory and to that package's own packages/<name>/vX.Y.Z
# tags. Run locally, review the diff, and commit before tagging a package
# release -- mirrors how the root CHANGELOG.md is maintained (see
# git-cliff invocation in .github/workflows/release.yml, and the
# "doc: prep vX.Y.Z" commits that precede every framework tag).
#
# An optional second argument overrides the label for whatever would
# otherwise land in "[unreleased]" -- used once, to backfill each package's
# CHANGELOG.md with everything through v4.0.0 (the last lockstep tag) under
# a "## [4.0.0]" heading instead of leaving it as unreleased, since that
# history has in fact already shipped.
set -eu
NAME="${1:?usage: bin/package-changelog.sh <package-name> [backfill-tag]}"
DIR="packages/${NAME}"
[ -d "$DIR" ] || { echo "no such package: ${DIR}" >&2; exit 1; }
TAG_ARGS=""
if [ "${2:-}" != "" ]; then
  TAG_ARGS="--tag ${2}"
fi
# shellcheck disable=SC2086
git-cliff \
  --include-path "${DIR}/**" \
  --tag-pattern "^packages/${NAME}/v" \
  $TAG_ARGS \
  -o "${DIR}/CHANGELOG.md"
echo "wrote ${DIR}/CHANGELOG.md"
