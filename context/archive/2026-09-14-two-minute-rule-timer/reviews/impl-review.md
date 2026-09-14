<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: 2-minute rule timer in clarify (S-03)

- **Plan**: context/changes/two-minute-rule-timer/plan.md
- **Scope**: Phases 1–3 of 3 (full plan)
- **Date**: 2026-09-14
- **Verdict**: REJECTED → all 10 findings fixed and verified by deliberate breakage (11 mutations, 11 killed)
- **Findings**: 1 critical, 4 warnings, 5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | FAIL → PASS after fixes |
| Architecture | WARNING → PASS after fixes |
| Pattern Consistency | PASS |
| Success Criteria | WARNING → PASS after fixes |

All automated criteria pass (Pint, Larastan L6 0 errors, Pest 130/429, ESLint, build, Vitest 89).
Both manual criteria have observable evidence. The FAIL is a correctness defect the gates cannot see.

## Findings

### F1 — The FR-006 record is derived from the request, not from the decision

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality / Architecture
- **Location**: app/Services/ItemService.php:80-82
- **Detail**: The service decides whether to emit the two-minute event by reading the raw
  payload (`$payload->tookTheTwoMinuteBranch()`), not the `ClarifyOutcome` the domain
  returned. `ClarifyDecision` only consults the timer answers on the `actionable && singleStep`
  path; every other path ignores them, and the FormRequest does not prohibit them there.
  Reproduced against the running API: `{"actionable":true,"singleStep":false,"twoMinutes":true,
  "twoMinuteOutcome":"done","twoMinuteLoops":7}` → item filed in **Projects** with
  `completedAt: null`, while `clarify.two_minute_rule.done` was written to the log. The durable
  state and the record contradict each other, and the one metric this slice exists to produce
  is forgeable by any authenticated caller. Not reachable from the SPA (the TS union forbids
  it) — this is an API-surface defect. It is also a layering smell: the service is inferring a
  domain fact from input instead of reading it off the domain's answer.
- **Fix**: Carry the timer facts on `ClarifyOutcome` — the only object that knows the branch was
  actually walked — and log off `$outcome`, never `$payload`. Add `prohibited_if_declined`
  rules so timer answers cannot ride a path that never asks the question.
  - Strength: Makes the record unforgeable by construction rather than by a guard someone can
    forget, and matches how `completed` already travels.
  - Tradeoff: Threads two more fields through the deferred branch of the decision tree.
  - Confidence: HIGH — reproduced end to end; the fix mirrors the existing `completed` field.
  - Blind spot: None significant.
- **Decision**: FIXED — the timer facts now travel on `ClarifyOutcome` (`completedInTwoMinutes($loops)` / `deferredAfterTwoMinutes($loops)`), the service logs off `$outcome`, `ClarifyItemPayload::tookTheTwoMinuteBranch()` is deleted so the wrong question cannot be asked again, and the FormRequest now refuses timer answers on paths that never ask. Re-probed against the running API: the exact forged payload is a 422 and nothing is logged.

### F2 — A test asserts the opposite of the feature this slice shipped

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: frontend/src/items/ClarifyDialog.test.tsx:77-83
- **Detail**: `it('does not ask about the two-minute rule — that question belongs to S-03')`
  survived the slice unchanged and is still green. It clicks one "Yes", landing on the
  single-step step, then asserts the two-minute wording is absent — trivially true one step
  BEFORE the question, and it stays true if the question is deleted from the dialog entirely.
  Its name now contradicts the shipped feature and the test at line 294 in the same file. A
  direct instance of the accepted rule in lessons.md: it cannot redden for what it names.
- **Fix**: Delete it — the "asks about two minutes before asking about delegation" test covers
  the real intent.
- **Decision**: FIXED — replaced with a test that pins the question's POSITION in the order (absent at "actionable", absent at "single step", present after).

### F3 — An abandoned quick-route target hijacked a later tree submit

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: frontend/src/items/ClarifyDialog.tsx:385-396
- **Detail**: Found independently by both review agents. `quickRouteTarget` was set but never
  cleared, and the new Back branch made it reachable: Skip the questions → Delegation → Back →
  Back → walk the tree → defer a timer → delegate. Reproduced: the posted body was
  `{"quickRouteBucket":"delegation","delegatedTo":"Ania"}` — the server told that the user
  skipped the questions, after they answered every one. The item still landed in Delegation
  with the right note, so nothing looked wrong, while the deferral, the loop count and the
  FR-006 log line were all discarded.
- **Fix**: Clear `quickRouteTarget` when Back returns to the `actionable` fork.
- **Decision**: FIXED — applied during the review, with a regression test that walks the exact path and pins the posted body.
  path and pins the posted body.

### F4 — `ClarifyConst::TWO_MINUTE_SECONDS` binds nothing

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: app/Const/ClarifyConst.php:15 + frontend/src/api.ts:185
- **Detail**: The PHP constant has zero references in `app/`, `tests/`, `routes/`, `database/`.
  Its docblock claims the value is "bound to a single source so the backend, the SPA countdown
  and any test that asserts on the threshold cannot drift apart" — but the SPA holds an
  independently typed `120` and nothing compares them. Change the PHP constant to 90 and every
  gate stays green while the timer still runs 120 seconds. That is `app/CLAUDE.md`'s
  "bind one value to a single source so it can't silently drift" stated but not implemented.
- **Fix**: Add a contract test that reads the SPA constant and fails when the two diverge, and
  reword the docblock to say how the binding is actually enforced.
  - Strength: Makes the stated guarantee real for ~15 lines; the alternative (deleting the
    constant) loses the domain's own statement of the rule, which `app/CLAUDE.md` cites by name.
  - Tradeoff: A PHP test that reads a TypeScript file is unusual.
  - Confidence: HIGH — the drift is demonstrable today.
  - Blind spot: None significant.
- **Decision**: FIXED — `tests/Unit/Clarify/TwoMinuteThresholdContractTest.php` reads the SPA constant and fails on drift in either direction; the docblock now says how the binding is enforced instead of asserting one that did not exist.

### F5 — The service guard this slice added has no unit test

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Unit/Item/ItemServiceTest.php
- **Detail**: `ItemServiceTest` was touched only to add `twoMinutes: false` to an existing call.
  The new emit-guard has no unit coverage, which the root CLAUDE.md CI gate requires ("new
  features have feature + unit tests"). It is also exactly the test that would have caught F1.
- **Fix**: Assert the event is emitted for a walked timer and NOT emitted for a path that never
  asked the question.
- **Decision**: FIXED — three unit tests on the service seam, including the forged multi-step payload that reproduces F1.

### F6 — The countdown assertions sit about one second from flaking

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: frontend/src/items/ClarifyDialog.test.tsx:303-319, 361-385
- **Detail**: `vi.useFakeTimers({ shouldAdvanceTime: true })` installs a real 20 ms interval that
  ticks the fake clock with wall time, so the component's 1 s interval also fires once per real
  second on top of the explicit `advanceTimersByTime` calls. The assertions are exact strings
  with zero tolerance ("0:59", "0:30", "2:00"). Roughly 1000 ms of real time between mounting
  the interval and the assertion flips the expected value. Ran green 3× idle and 3× under heavy
  CPU load, so this is a latent cliff rather than an active flake — but a cold runner or a
  coverage instrumenter can cross it.
- **Fix**: Read the clock immediately before advancing and assert the exact delta, so any drift
  cancels; use a tolerance band for the "fresh clock" assertions, with literal seconds so the
  constant-mutation still reddens them.
- **Decision**: FIXED — a `readClock()` helper reads the countdown either side of each advance and the assertions compare deltas, so drift cancels instead of accumulating. Literal seconds, never the imported constant, so a changed threshold still reddens them.

### F7 — The done-vs-delegation invariant is only half enforced

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Http/Requests/ClarifyItemRequest.php:117-127, app/Domain/Clarify/ClarifyDecision.php:121
- **Detail**: `delegable` is prohibited beside `twoMinuteOutcome: done`, but `delegatedTo` is
  not — that payload passes validation. The domain then discards it, so nothing wrong is
  written. Separately, the domain does not REFUSE the contradiction at all; it short-circuits
  past it. Every other gap in the tree raises `InvalidClarificationException`, so this is the
  one place the edge is the only line of defence — the exact dependence the 0d6f624 breakage
  pass concluded was a hazard.
- **Fix**: Prohibit `delegatedTo` alongside a done outcome, and note in the domain why the done
  branch returns before delegation is read.
- **Decision**: FIXED — `delegatedTo` is now prohibited alongside a done outcome, and the domain says in a comment why the done branch returns before delegation is read.

### F8 — Two tests cannot redden on a single mutation

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Item/ClarifyItemTest.php:410-420, 470-471
- **Detail**: The negative log assertion uses a payload with no outcome at all, so BOTH halves
  of the service guard must break before anything is logged. The quick-route rejection test
  asserts only status 422 and omits `twoMinuteLoops`, so dropping that field from the
  `prohibits` list leaves it green.
- **Fix**: Post the deferred payload and assert `.done` is never logged; add the third field and
  pin the failing key with `assertJsonValidationErrors`.
- **Decision**: FIXED — the negative log assertion matches by message PREFIX via `Mockery::on()`, so any two-minute event reddens it; the quick-route test carries all three fields and names the failing key.

### F9 — `completed_at` is nulled on every clarify

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Infrastructure/Item/ItemRepository.php:58
- **Detail**: `'completed_at' => $outcome->completed ? now() : null` writes null unconditionally.
  Correct today only because the statement is guarded by `where bucket = inbox`, so a completed
  item can never be re-clarified. It becomes silent data loss the moment FR-010 (re-filing)
  relaxes that predicate: re-filing a completed Next Action would erase its completion.
- **Fix**: Tie the null-write to the inbox guard in a comment so the coupling is visible to
  whoever implements FR-010.
- **Decision**: FIXED — the null-write is now tied in a comment to the `bucket = inbox` predicate it depends on, addressed to whoever implements FR-010.

### F10 — Three small dialog nits

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: frontend/src/items/ClarifyDialog.tsx:87-97, 230-232; frontend/src/items/InboxList.test.tsx
- **Detail**: (a) At `secondsLeft === 0` the interval keeps firing forever while the step is
  open, setting state to the same value — React bails out, so noise rather than a leak.
  (b) `error` is never cleared on a step change, so "Say who you are waiting on." and the
  input's `aria-invalid` survive a Back into a different question. (c) The `getByText(/done/i)`
  queries are safe today (RTL reads only direct text children) but fragile by construction.
- **Fix**: Return early from the interval effect at zero; clear the error when the step changes;
  match the exact marker text.
- **Decision**: FIXED — the interval effect keys on an `expired` boolean so it stops rescheduling at zero; a step change clears the error (with a test); the marker queries match the exact text.

## Also checked, clean

- **Plan adherence**: every "Changes required" item verified MATCH, including the three claims
  the plan makes about the loop count, the prohibition and the absence of literals.
- **Not-doing list**: no new bucket, no server timer, no un-complete route, no completed filter,
  no timer on the quick-route. No violations.
- **Layering**: controller ~3 lines and Eloquent-free, service imports no `Illuminate\Http`,
  repository returns DTOs, the three `Domain/Clarify` classes are pure PHP.
- **Security**: route behind `auth:sanctum` + `whereNumber`, all writes bound, every logged
  field an int or enum value, `twoMinuteLoops` bounded before it reaches a log line.
- **Timer cleanup**: correct across Back, Cancel, submit-success unmount and rapid step changes;
  no stale closure; no duplicate POST reachable on a double click.
- **Migration**: nullable, no default, no backfill, reversible `down()`. No data-loss path.
- **Scramble**: picked up every new field automatically, enum cases described, bounds documented.

## Triage outcome

All ten findings fixed. Verified by deliberate breakage — 11 mutations, 11 killed, one only
after an extra test:

| Mutation | Result |
|---|---|
| Service records the timer from the request again | KILLED |
| Timer answers allowed on a multi-step payload | KILLED |
| `delegatedTo` allowed beside a done outcome | KILLED |
| PHP threshold drifts to 90 | KILLED |
| SPA threshold drifts to 90 | KILLED |
| Deferral never stamped on the outcome | KILLED |
| Abandoned quick-route target kept | KILLED |
| Error survives a step change | KILLED |
| Two-minute question moved before single-step | KILLED |
| `completedInTwoMinutes` reports zero loops | SURVIVED → killed after adding loop-count coverage |
| Deferral reports zero loops | KILLED |

The survivor was a real gap: every existing test used a loop count of zero on the done path,
so an implementation that discarded the count looked identical to one that carried it. A user
who looped twice and then finished would have had that fact dropped from the only record
FR-006 asks for. Covered now at both the domain and the HTTP layer.

Gates after the fixes: Pint clean · Larastan L6 0 errors · Pest 141/463 (+11) · ESLint clean ·
build clean · Vitest 91 (+2).
