# Single-Account Invariant — Decision Record

## Overview

Rollout Phase 3 of `context/foundation/test-plan.md` set out to prove a second account cannot be
created "including under concurrent requests" (Risk #6). Planning established that the concurrent
half **cannot be proven by this test suite**, and that writing a test anyway would produce false
coverage. This change closes the phase with that finding recorded instead of with a test.

There is no implementation. The deliverable is the §6.6 note and the §3 status.

## Current State Analysis

- The gate is `lockForUpdate` inside a transaction — `UserRepository::createFirstUserOrNull`.
  `FOR UPDATE` is a MySQL construct; on SQLite it compiles to a no-op and write serialization comes
  from SQLite's own locking instead.
- Tests run on SQLite `:memory:` (`phpunit.xml:26-27`), and CI runs the same
  (`.github/workflows/ci.yml:39`, with no `services:` block at all). Production is MySQL.
- SQLite `:memory:` is **one connection per test**, so two genuinely concurrent transactions against
  the same database are unreachable at this layer.
- `tests/Feature/Auth/RegisterTest.php` covers the **sequential** second registration. The test plan
  names that as this risk's anti-pattern, not its coverage: "Testing only the sequential second
  attempt — which already passes — and calling the race covered".

## Desired End State

The rollout tracker says Phase 3 is closed, and §6.6 says exactly what is and is not proven, so the
next reader neither re-derives this nor mistakes the sequential test for race coverage.

### Key Discoveries

- **A race test written today would pass for the wrong reason.** It would be green because SQLite
  serializes writes, not because the gate holds on the engine that runs in production — the failure
  mode `context/foundation/lessons.md` exists to prevent, on the one risk the PRD calls a permanent
  non-goal to violate.
- **§2 anticipated this.** Its Risk Response Guidance for #6 already says the race shape is "most
  likely asserted through a database-level constraint rather than real parallelism" — the guidance
  pointed at a structural fix, not at a concurrency test.
- Two paths would make the concurrent case provable, and both were considered and declined here:
  a database constraint capping `users` at one row (concurrency then stops mattering, because
  whichever transaction commits second violates it), or a MySQL service in CI plus real
  multi-process parallelism.

## What We're NOT Doing

- **No race test.** It cannot be written truthfully on the configured engine.
- **No production schema change.** The constraint option is recorded, not taken.
- **No MySQL service in CI.** Recorded, not taken.
- **No change to `lockForUpdate`.** It remains the gate; this change neither strengthens nor
  weakens it.
- **No edit to §2.** The risk and its guidance stand; only §6.6 and the §3 status move.

## Phase 1: Record the finding and close the phase

### Overview

One file. The value is in what it says, not in what it does.

### Changes Required:

#### 1. Per-phase note

**File**: `context/foundation/test-plan.md`

**Intent**: Record why the concurrent half of Risk #6 has no automated gate, so the absence reads
as a decision rather than an oversight — and so nobody later writes the green-for-the-wrong-reason
test this change exists to refuse.

**Contract**: §6.6 gains a "Phase 3" note covering: the gate's mechanism and why it is
engine-specific; the SQLite `:memory:` single-connection limit and the absent MySQL service; the
explicit statement that the sequential test is NOT race coverage; the two paths that would make it
provable; and the residual risk accepted. §3's Phase 3 row goes to `complete` with this change
folder.

### Success Criteria:

#### Automated Verification:

- Pest suite passes unchanged: `php composer.phar test`
- `context/foundation/test-plan.md` §3 shows Phase 3 as `complete` with the change folder
- §6.6 carries a Phase 3 note naming the residual risk and both rejected alternatives

#### Manual Verification:

- Reading the §6.6 Phase 3 note alone makes clear why no race test exists, and what would have to
  change for one to be worth writing

## References

- Rollout phase: `context/foundation/test-plan.md` §3 Phase 3, §2 Risk #6, §6.6
- The gate: `app/Infrastructure/Auth/UserRepository.php` (`createFirstUserOrNull`)
- Existing sequential coverage: `tests/Feature/Auth/RegisterTest.php`
- Test discipline: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Record the finding and close the phase

#### Automated

- [x] 1.1 Pest suite passes unchanged
- [x] 1.2 test-plan.md §3 shows Phase 3 complete with its change folder
- [x] 1.3 §6.6 carries a Phase 3 note naming the residual risk and both rejected alternatives

#### Manual

- [x] 1.4 The note alone explains why no race test exists and what would change that
