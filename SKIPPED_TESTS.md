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
