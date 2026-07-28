# Skipped tests

Tests listed here are permitted to skip under specific, documented conditions.
Any other skip in the suite is a bug and must be fixed, not added to this file.

## `ValidationManager` duplicate-name mutual-exclusion pair

Exactly one of these two tests skips on any given run — never both, never
neither — depending on which one PHPUnit happens to execute first in the
shared process:

- `ValidationManagerQueryApiTest::testAddChildRejectsDuplicateNameOutsideTesting`
  (`tests/tests/unit/validator/ValidationManagerQueryApiTest.php`) — skips
  when `QUIOTE_TESTING` is **already defined**.
- `ValidationManagerDuplicateNameTest::testDuplicateNameOverwrite`
  (`tests/tests/unit/validator/ValidationManagerDuplicateNameTest.php`) —
  skips when `QUIOTE_TESTING` is **not yet defined**.

**Rationale:** the two tests exercise `ValidationManager`'s duplicate-name
handling on opposite sides of the `QUIOTE_TESTING` constant — rejection
outside testing mode vs. overwrite-allowed inside it. `QUIOTE_TESTING`, once
defined by any test in the shared PHPUnit process, can never be undefined
again for the rest of that process, so whichever of the two tests runs
*second* can no longer observe its required starting condition and skips
itself rather than asserting against the wrong precondition. This is a real
ordering dependency, not a flaky assertion — the skipped one flips depending
on suite composition and `executionOrder`, but one of the pair skipping is
expected on every run. Splitting these into separate process invocations
(e.g. a dedicated `@runInSeparateProcess` group or process-isolated suite)
would let both run unconditionally every time; until that's worth the cost,
this entry documents the known, self-explaining gap instead of leaving an
unexplained `markTestSkipped()` in the suite.

## Redis "no client available" guards

- `CacheManagerTest::testRedisBackendWithNoRedisClientAvailableThrows`
  (`tests/tests/unit/cache/CacheManagerTest.php`)
- `RateLimitPluginTest::testMissingRedisClientThrowsWhenStorageIsRedis`
  (`packages/ratelimit/tests/RateLimitPluginTest.php`)

Both assert that selecting the `redis` backend/storage throws an actionable
"no Redis client is available" error when neither `ext-redis`, `ext-relay`,
nor `predis/predis` is installed. Each test guards itself with
`if (extension_loaded('redis') || extension_loaded('relay') ||
interface_exists(\Predis\ClientInterface::class)) markTestSkipped(...)` and
skips whenever any of those is present.

**Rationale:** this repo's own dev dependencies (`queue-redis`,
`session-redis`, and this task's own `filesystem-*` packages all exercise
real Redis-backed code paths in their own tests) require `predis/predis` to
be installed for the rest of the suite to run at all — so the "no client
installed" precondition these two tests need can never hold in this
repo's own CI/dev environment. The guard is correct and meaningful for a
consumer app that installs Quiote without any Redis client; it is
structurally unsatisfiable here. Removing predis to let these two run would
break every other Redis-backed test in the suite, so both are expected to
skip on every run in this repository.
