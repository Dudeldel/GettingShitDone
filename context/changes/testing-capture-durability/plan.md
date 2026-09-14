# Capture durability and error surfacing — Implementation Plan

## Overview

Rollout Phase 1 of `context/foundation/test-plan.md`: close Risk #1 ("capture confirms
the save, but the idea never lands durably in the Inbox") and Risk #2 ("a failure is
swallowed — the user is shown an empty or stale state and believes it, and typed text is
destroyed on the way") at the cheapest layer that can catch each.

Two tests need no new tooling and land first. The rest requires bootstrapping a frontend
test toolchain that does not exist today. Two of the five target behaviours are currently
defects rather than requirements, so each ships with the minimal fix that turns its test
green — the phase gate (`test-plan` §5, "frontend unit + component — required after
Phase 1") cannot be honest otherwise.

## Current State Analysis

Grounded in `context/changes/testing-capture-durability/research.md`; the findings that
drive this plan:

- **A 201 attests that the INSERT call returned, not that the row is readable.**
  `app/Infrastructure/Item/ItemRepository.php:31` maps the same in-memory instance it
  just created — no `fresh()`, no re-read, and no DB transaction anywhere in the item
  path. `createdAt`/`updatedAt` are the app's clock; the five dormant fields serialize as
  `null` regardless of any DB default.
- **The client shows items it never read back, by design.** `InboxPage.tsx:77` prepends
  the POST's returned DTO to local state and `mergeById` (`:13-17`) deliberately keeps
  local-only entries leading the server's list, to satisfy the ~2s capture NFR with one
  round trip. List membership is therefore *not* a durability oracle.
- **No test anywhere does POST → GET.** `ListItemsTest` seeds every row via `seedItem()`
  (`tests/Pest.php:41-44`), which calls the repository directly and bypasses HTTP.
- **The failure path already has a real-DB-failure mechanism**:
  `Schema::drop('items')` at `tests/Feature/Item/CaptureItemTest.php:120` and `:130`.
  Not a mock — the real repository throws a real `QueryException`.
- **No test pins any response body.** The 500's `{"message": …}`
  (`bootstrap/app.php:42-47`) is the one field the SPA reads (`api.ts:68-78`), and the
  only existing assertion on it is negative (`->not->toContain('4711')`).
- **The 401 "session expired" message is unreachable.** `api.ts:62-66` calls
  `onUnauthorized()` before throwing; React batches that with the catch's `setError`, and
  `ProtectedRoute.tsx:7` swaps `<Outlet/>` for `<Navigate/>` in the same render.
  `docs/reference/contract-surfaces.md` already records this as current behaviour.
- **`InboxPage.tsx:80-85` hides the list whenever `loadError !== null`** — including an
  item just captured successfully — and `loadError` is never cleared (`[]` deps).
- **`frontend/` has zero tests and no runner.** `.github/workflows/ci.yml:43-59` runs
  `npm ci`, `npm run lint`, `npm run build` and nothing else.
- **The failure-path domain event already exists and is already covered**
  (`ItemService.php:34`, asserted at `tests/Unit/Item/ItemServiceTest.php:45-59`). Not
  re-tested here; re-asserting it is the test plan's own Risk #2 anti-pattern.

## Desired End State

A capture that reports success is provably readable through a separate request; a capture
that fails reports a specific, documented failure and never destroys the user's text; and
both properties are defended by CI on every push.

Verify by: `php artisan test tests/Feature/Item` green; `cd frontend && npm test` green;
`npm run build` and `npm run lint` green; the CI frontend job failing when either fix from
Phase 3 or Phase 4 is reverted.

### Key Discoveries:

- The durability oracle in the test plan ("readable in a fresh session") is **unachievable**
  here — `phpunit.xml:26-27` is SQLite `:memory:` and `tests/Pest.php:16` applies
  `RefreshDatabase` to the whole Feature suite, so the wrapping transaction rolls back and
  the database dies with its connection. The achievable oracle is a **second HTTP request**;
  it proves the real read path, a fresh request lifecycle and fresh service instances
  (`AppServiceProvider.php:24-25` uses `bind`, not `singleton`). It does not prove
  commit-to-disk, and that is out of reach at this layer and not worth chasing for a
  single-instance managed MySQL deployment.
- Vitest 5's peer range is `vite: ^6.4.0 || ^7.0.0 || ^8.0.0` — compatible with this
  project's Vite 8.0.16. `@testing-library/react` 16.3.3 peers `react: ^18 || ^19`.
  (Checked against the npm registry 2026-09-14.)
- **Explicit `vitest` imports in test files avoid all config churn.** With
  `import { describe, it, expect } from 'vitest'` there is no need for `globals: true`, no
  `vitest/globals` entry in `tsconfig.app.json`, and no new block in `eslint.config.js` —
  `typescript-eslint` already disables `no-undef`, and test files export nothing so
  `reactRefresh.configs.vite` (`eslint.config.js:16`) stays quiet.
- The 500 message has **no owning constant**; `bootstrap/app.php:44` is its only
  declaration and the SPA reads it dynamically. Pinning the literal in the test is
  therefore correct — the test *is* the contract. This is **not** the `ph2 F7` anti-pattern,
  which was a test literal restating a constant that already existed.

## What We're NOT Doing

- **Not** moving the draft to `localStorage`. "Never destroys typed text" is scoped to the
  tab session: survives the 401 bounce, explicit logout, refresh and remount; dies on tab
  close. Recorded as the deliberate edge, consistent with the archived plan's exclusion of
  "offline capture, draft queue, or client-side retry".
- **Not** pinning the 422 / 401 / 429 body *shapes*. Those belong to Risk #4 (test-plan
  §3 Phase 2, "HTTP edge contract"). Phase 1 asserts only that each status produces a
  *distinct and actionable* message in the UI, plus the 500 body the SPA consumes on the
  capture failure path.
- **Not** re-testing the failure-path domain event or the log-redaction discipline — Risk
  #7, already covered (`ItemServiceTest.php:45-59`, `CaptureItemTest.php:128-140`).
- **Not** adding e2e, a browser runner, or any visual diff. That is test-plan §3 Phase 4.
- **Not** adding idempotency to capture. A 201 lost in transit after the server committed
  produces a duplicate on retry; the PRD guardrail covers losing entries, not duplicating
  them. Recorded so the absence is a choice.
- **Not** changing `api.ts`'s 401 semantics. Clearing the token and logging out on a 401 is
  correct; only the *explanation* is missing.
- **Not** widening `ItemRepository`'s `catch (QueryException)` to other throwables, and not
  adding a transaction seam. Both flagged in research; both belong to a later change.
- **Not** adding coverage thresholds or a coverage reporter.

## Implementation Approach

Order is cost × signal. Phase 1 buys real Risk #1 coverage with zero new tooling, so the
change delivers value even if the frontend work stalls. Phase 2 stands the harness up and
turns the CI gate on while the suite is trivially green, so Phases 3 and 4 are themselves
protected. Phases 3 and 4 each pair a failing test with the minimal fix that turns it
green, so every phase ends with all gates passing. Phase 5 records what shipped.

## Critical Implementation Details

**State sequencing — the load-error render gate (Phase 4).** The obvious fix to
`InboxPage.tsx:85` is wrong. Simply dropping the `loadError === null` guard makes the
`items.length === 0` case render `InboxList`'s "Your Inbox is empty." *underneath* a load
error — which is precisely Risk #2's "an empty list means there is no data". The list must
render beside an error only when there is something to show:

```tsx
{loadError === null
  ? (!loading || items.length > 0) && <InboxList items={items} />
  : items.length > 0 && <InboxList items={items} />}
```

**Timing — the 401 test needs the real app shell (Phase 3).** The defect only reproduces
when `ProtectedRoute` can actually unmount the form, so the test must render the router +
`AuthProvider` tree from `main.tsx`, not `CaptureForm` in isolation. A test that mounts
`CaptureForm` alone will pass against the broken code and prove nothing — the same class
of defect as the archived `ph2 F2` finding ("a test that cannot fail").

**Ordering — MSW must control resolution order (Phase 4).** The capture-during-load
regression guard requires the POST to settle *before* the GET. Use deferred/controlled
handler resolution, not `waitForTimeout`-style sleeps, so the ordering is deterministic
under `--parallel` and on CI.

---

## Phase 1: Backend durability and failure contract

### Overview

Prove observable durability and the failure contract using the existing Pest suite. No new
dependencies; both tests join the file that already owns the capture endpoint.

### Changes Required:

#### 1. Durability round trip

**File**: `tests/Feature/Item/CaptureItemTest.php`

**Intent**: Prove that a 201 from `POST /api/items` is followed by that item being
readable through a *separate* `GET /api/items` — the gap research found across the whole
suite. This is the assertion that distinguishes "the insert call returned" from "the row
is readable through the real read path".

**Contract**: A new `it(...)` that POSTs a title, reads the `id` from the 201 body, then
issues `GET /api/items` and asserts the item is present in the response with that `id`,
that `title`, and `bucket` `inbox`. Assert against the returned id — do not assume list
position beyond what `ListItemsTest` already pins. Must fail if `ItemRepository::create`
is changed to skip persistence while still returning a DTO.

#### 2. Failure contract: no confirmation, and the body the SPA reads

**File**: `tests/Feature/Item/CaptureItemTest.php`

**Intent**: Extend the existing forced-failure coverage from "does not echo the payload"
to "reports the documented failure, and the item is genuinely not there". Today the only
body assertion is negative, so the one field the SPA consumes is unpinned.

**Contract**: Reuse the existing `Schema::drop('items')` mechanism (`:120`). Two additions:
(a) assert `assertJsonPath('message', 'The item could not be saved. Please try again.')`
on the 500 — the literal from `bootstrap/app.php:44`, pinned here because that line is its
only declaration and the SPA reads it dynamically; (b) a companion test that does **not**
drop the table but forces the failure, then asserts a subsequent `GET /api/items` does not
contain the title. Where a dropped table makes the follow-up GET impossible, restore the
schema before the read (`artisan migrate` on the test connection) or force the failure by
a means that leaves the table readable — the assertion that matters is "failed write ⇒
absent from the read path", not the mechanism.

### Success Criteria:

#### Automated Verification:

- Item feature tests pass: `php artisan test tests/Feature/Item`
- Full backend suite passes: `php artisan test`
- Larastan level 6 clean: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint clean: `./vendor/bin/pint --test`
- Durability test proven able to fail: temporarily make `ItemRepository::create` return a
  DTO without persisting, confirm the new test goes red, revert

#### Manual Verification:

- The failure test's mechanism exercises the real repository, not a mock — confirmed by
  reading the test body

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding.

---

## Phase 2: Frontend test harness

### Overview

Stand up Vitest 5 + jsdom + MSW + React Testing Library, prove the harness works with one
smoke test, settle the `AbortSignal.timeout` question, and turn the CI gate on while the
suite is trivially green.

### Changes Required:

#### 1. Dependencies

**File**: `frontend/package.json`

**Intent**: Add the test toolchain and the scripts that run it.

**Contract**: devDependencies gain `vitest`, `jsdom`, `msw`, `@testing-library/react`,
`@testing-library/dom`, `@testing-library/jest-dom`, `@testing-library/user-event`.
Scripts gain `"test": "vitest run"` and `"test:watch": "vitest"`. Pin majors compatible
with Vite 8 / React 19 per Key Discoveries. No coverage reporter.

#### 2. Vitest configuration

**File**: `frontend/vite.config.ts`

**Intent**: Add the test block. Keep one config file rather than a second `vitest.config.ts`
so the app's plugin/alias setup cannot drift from the test environment's.

**Contract**: Import `defineConfig` from `vitest/config` instead of `vite`. Add `test` with
`environment: 'jsdom'`, `setupFiles` pointing at the new setup module, and `restoreMocks`
enabled. `tsconfig.node.json` already scopes this file, so no tsconfig change is needed here.

#### 3. Test setup and MSW server

**File**: `frontend/src/test/setup.ts`, `frontend/src/test/server.ts`

**Intent**: Register jest-dom matchers and own the MSW server lifecycle in one place, so
individual tests declare only the handlers they care about.

**Contract**: `setup.ts` imports `@testing-library/jest-dom/vitest` (which also supplies
the matcher types, so no `types` entry is needed in `tsconfig.app.json`) and wires
`beforeAll` → listen, `afterEach` → reset handlers + RTL cleanup, `afterAll` → close.
Listen with `onUnhandledRequest: 'error'` so a test that forgets a handler fails loudly
instead of hitting the network. `server.ts` exports the `setupServer` instance plus the
default happy-path handlers for `GET /api/items`, `POST /api/items` and `GET /api/me`.

#### 4. Harness smoke test

**File**: `frontend/src/items/InboxList.test.tsx`

**Intent**: Prove jsdom, RTL, jest-dom and the TypeScript/lint path all work end to end
before any behaviour test depends on them. `InboxList` is pure and prop-driven, so this
tests the harness and nothing else.

**Contract**: Explicit `vitest` imports (no globals). Render `InboxList` with two items,
assert both titles are present and that the empty-state copy is not; render with an empty
array, assert the empty-state copy is present. Queries use `getByRole`/`getByText`, never
CSS selectors.

#### 5. `AbortSignal.timeout` probe

**File**: `frontend/src/test/abort-signal.probe.test.ts`

**Intent**: Settle research Open Question #1 — whether jsdom 30 on Node 20 rejects a
timed-out `fetch` with a `DOMException` named `TimeoutError`. The 408 branch of
`api.ts:55-60` is only genuinely testable if it does; everything downstream in Phase 3
depends on the answer.

**Contract**: One test that issues a request against an MSW handler which never resolves,
with a very short timeout, and asserts the rejection is a `DOMException` with
`name === 'TimeoutError'`. If it passes, keep it as a standing guard on the assumption and
Phase 3 asserts the real 408 path. If it fails, delete it, record the limitation in the
plan's Phase 5 cookbook notes, and Phase 3 covers the 408 mapping by injecting a
manufactured rejection instead — explicitly labelled in the test as asserting the *mapping*,
not the platform.

#### 6. CI gate

**File**: `.github/workflows/ci.yml`

**Intent**: Make the frontend suite blocking, as `test-plan` §5 commits to for this phase.

**Contract**: In the `frontend` job, add `npm test` after `npm run build`, so a type error
in a test file surfaces as a build failure rather than a confusing runtime error. Reuses
the existing `npm ci`; no new job.

### Success Criteria:

#### Automated Verification:

- Frontend tests pass: `cd frontend && npm test`
- Frontend build passes (incl. `tsc -b` over the new test files): `cd frontend && npm run build`
- Frontend lint passes with no new config: `cd frontend && npm run lint`
- Backend suite still green: `php artisan test`
- An unhandled request fails the run — confirmed by temporarily removing a handler

#### Manual Verification:

- The `AbortSignal.timeout` probe outcome is recorded, and Phase 3's approach to the 408
  branch is chosen accordingly
- `npm test` in a clean clone after `npm ci` works with no extra setup steps

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding.

---

## Phase 3: Capture error surfacing and the 401 explanation

### Overview

Prove every backend failure reaches the user as a distinguishable, actionable state and
never costs them their text — then fix the one path where that currently fails.

### Changes Required:

#### 1. Distinct, actionable messages per failure

**File**: `frontend/src/items/CaptureForm.test.tsx`

**Intent**: Pin `messageFor` (`CaptureForm.tsx:30-46`) against real transport responses, so
a future edit cannot collapse the branches back to one message — the original F3 defect.

**Contract**: One test per failure, each driving a real MSW response: 422 (with Laravel's
`{message, errors}` shape) surfaces the backend's own message; 429 says to wait; 500
surfaces the fixed backend message; a network error says the server is unreachable; and —
gated on the Phase 2 probe — a timeout produces the 408 message. Each test asserts the
message is *distinct from the others* and that the typed text is still in the input
afterwards. Assert distinguishability and actionability, never exact copy beyond what the
backend owns — a snapshot of the error string is explicitly out.

#### 2. Success path and the empty-submit guard

**File**: `frontend/src/items/CaptureForm.test.tsx`

**Intent**: Pin the confirmation half of Risk #1 at the client: a success is announced, the
field is cleared, and the draft is dropped only after a confirmed 201.

**Contract**: On 201 — the `role="status"` node announces the save, the input is cleared,
and `sessionStorage` no longer holds the draft. Submitting whitespace only — the
`role="alert"` node says so, focus returns to the field, and **no request is made**
(assert via an MSW handler that would fail the test if called). Text typed *during* a
successful round trip is not eaten (the conditional clear at `CaptureForm.tsx:82-87`).

#### 3. Draft survival within the tab session

**File**: `frontend/src/items/CaptureForm.test.tsx`

**Intent**: Pin the guarantee that actually holds today, across all three paths that
reach it — not just the 401 the risk names.

**Contract**: After a failed capture, an explicit logout, and a plain remount, the typed
text is recoverable from the mounted form. Plus the private-mode case: with
`sessionStorage` throwing on read and write, the component still mounts and submits
normally (the `try/catch` at `CaptureForm.tsx:10-28`). Tab-close is out of scope by
decision — note it in the test file so the boundary is explicit.

#### 4. The 401 path, end to end

**File**: `frontend/src/items/CaptureForm.401.test.tsx` (or a clearly-named block in the
same file)

**Intent**: Reproduce the real defect. This is the test that must render the full shell.

**Contract**: Render the router + `AuthProvider` tree as `main.tsx` composes it, seeded
with a token so `ProtectedRoute` admits the user. Type a title, have MSW answer the POST
with 401, then assert: the app lands on `/login`; a session-expired explanation is visible
*to the user*; and on returning to the capture screen the text is restored. Must fail
against today's code — verify by running it before the fix in change 5 lands.

#### 5. Fix: surface the expired session at the login screen

**File**: `frontend/src/auth/AuthContext.tsx`, `frontend/src/auth/context.ts`,
`frontend/src/auth/LoginPage.tsx`

**Intent**: Give the bounce an explanation without changing 401 semantics. The provider
already owns the unauthorized handler and sits above the router, so the reason survives the
navigation in ordinary React state — no router state, no storage flag.

**Contract**: `AuthContextValue` gains a `sessionExpired: boolean`. The handler registered
at `AuthContext.tsx:12` sets it alongside `setUser(null)`; a successful `login` clears it.
`LoginPage` reads it via `useAuth()` and renders an explanation when true, in the same
position as its existing error paragraph. `api.ts` and `ProtectedRoute.tsx` are not
touched. Note that `AuthProvider` returns `null` until `ready`
(`AuthContext.tsx:50-52`) — the flag must not be clobbered by that path.

#### 6. Remove the unreachable branch

**File**: `frontend/src/items/CaptureForm.tsx`

**Intent**: Delete the `case 401` arm of `messageFor` once the explanation lives where the
user can see it. Leaving provably-dead code that claims to handle the case is how the
original review came to record a fix that had not happened.

**Contract**: Drop `case 401` from the switch (`:36-37`); it falls through to `default`.
Update the comment block at `:4-7` to describe what now actually happens.

### Success Criteria:

#### Automated Verification:

- Frontend tests pass: `cd frontend && npm test`
- Frontend build and lint pass: `cd frontend && npm run build && npm run lint`
- The 401 test proven able to fail: stash change 5, confirm red, restore
- No `waitForTimeout`-style sleeps in any test — grep confirms only state-based waits

#### Manual Verification:

- Sign in, stop the API, submit — a distinct, actionable message appears and the text stays
- Revoke the token server-side, submit — the login screen explains the expiry and the text
  is still there after signing back in

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding.

---

## Phase 4: Inbox load failure and list visibility

### Overview

Give the capture-during-load invariant its first automated guard, and stop a load failure
from hiding an item the user was just told was saved.

### Changes Required:

#### 1. The capture-during-load regression guard

**File**: `frontend/src/items/InboxPage.test.tsx`

**Intent**: Pin `mergeById` (`InboxPage.tsx:13-17`) — the fix for the original F1 CRITICAL,
which has never had a test. This is the highest-value regression guard in the change.

**Contract**: With MSW holding the initial `GET /api/items` unresolved, submit a capture;
resolve the POST; then resolve the GET with a **pre-capture snapshot**. Assert the captured
item is still visible and that "Your Inbox is empty." is absent. A second test: resolve the
GET with a snapshot that *does* include the captured item, and assert it appears exactly
once. Ordering must be controlled by handler resolution, not timers.

#### 2. A failed load must not hide a captured item

**File**: `frontend/src/items/InboxPage.test.tsx`

**Intent**: Cover the residual `c401365` introduced — the live Risk #1 symptom with
different wording.

**Contract**: With the initial GET failing, submit a capture and assert the item is visible
alongside the load error. A companion test: with the initial GET failing and no capture,
assert the error is shown and **"Your Inbox is empty." is not** — an unloaded list must be
distinguishable from an empty one. Also assert the load error is rendered as an actionable
message rather than a raw transport string.

#### 3. Fix: render the list beside a load error, and route the error through `messageFor`

**File**: `frontend/src/items/InboxPage.tsx`, `frontend/src/items/CaptureForm.tsx` or a
shared module

**Intent**: Two coupled fixes. The render gate must show a captured item beside an error
without asserting the Inbox is empty (see Critical Implementation Details). And the load
error currently renders raw `e.message` (`:36`) — `"Failed to fetch"`, `"HTTP 500"` —
which is distinguishable but not actionable, failing the same half of Risk #2 the capture
path already satisfies.

**Contract**: Replace the two render branches at `:84-85` with the form given in Critical
Implementation Details. Extract `messageFor` out of `CaptureForm.tsx` into a shared module
(both components now need it) and use it at `:36` in place of the raw message. Extraction
is mechanical — no behaviour change to the capture path, which Phase 3's tests already pin.

### Success Criteria:

#### Automated Verification:

- Frontend tests pass: `cd frontend && npm test`
- Frontend build and lint pass: `cd frontend && npm run build && npm run lint`
- Backend suite still green: `php artisan test`
- The merge guard proven able to fail: revert `mergeById` to `.then(setItems)`, confirm red,
  restore
- The visibility test proven able to fail: restore the `loadError === null` guard on the
  list branch, confirm red, restore

#### Manual Verification:

- Stop the API, reload the page, then capture — the item appears beside an actionable error
- With the API stopped and nothing captured, the screen does not claim the Inbox is empty

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding.

---

## Phase 5: Cookbook, contracts and test-plan backport

### Overview

Record what shipped where the next contributor and the next rollout phase will look for it.

### Changes Required:

#### 1. Cookbook and stack rows

**File**: `context/foundation/test-plan.md`

**Intent**: Fill §6.3 (currently "TBD — see §3 Phase 1"), which is the section this phase
exists to be able to write.

**Contract**: §6.3 gains location (colocated `src/**/*.test.tsx`), the explicit-`vitest`-
imports convention and why it avoids config churn, the MSW-over-stub transport policy with
its rationale, the app-shell-vs-isolated-component rule for tests of unmount behaviour, and
the reference tests shipped here. §4 Stack rows for "unit + component (frontend)" and
"API / transport mocking" get the chosen tools, versions and a `checked:` date. §5 marks
the frontend gate enforced. §6.6 records the `AbortSignal.timeout` probe outcome. §3 Status
for Phase 1 advances.

#### 2. Backport the §2 corrections

**File**: `context/foundation/test-plan.md`

**Intent**: Apply the three research corrections the user approved. Wording only — Source
column, risk wording and Risk Response Guidance cells; never a file anchor (§1 principle #3).

**Contract**: (a) Replace the hot-spot citations in Risks #1 and #2 with the evidence that
actually justifies them — `c401365` shipped two CRITICAL guardrail fixes with zero tests,
and `frontend/` has no runner, so neither risk has an automated gate. (b) Risk #1's "what
would prove protection" cell: replace "readable in a fresh session" with the second-HTTP-
request oracle, noting `RefreshDatabase` + SQLite `:memory:` as the reason. (c) Risk #2's
"context to ground" cell: drop "whether a failure-path domain event exists" — it exists and
is covered. Bump §8 Freshness Ledger.

#### 3. Contract surfaces

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Register the new load-bearing names, and correct the entry that this change
makes stale.

**Contract**: A new "Frontend test harness" H2 covering the runner, the DOM environment,
the MSW server module and the `onUnhandledRequest: 'error'` policy. Update "Frontend
capture draft" — the "unmounts the form before an error can paint" sentence stays true but
must now say where the explanation surfaces instead, and record the tab-session boundary.
Update "Frontend auth (AuthContext / token)" for `sessionExpired`.

### Success Criteria:

#### Automated Verification:

- Full backend quality gate passes: `php composer.phar quality`
- Frontend gates pass: `cd frontend && npm run lint && npm run build && npm test`
- No "TBD" left in `test-plan` §6.3

#### Manual Verification:

- A reader who was not part of this change can add a frontend component test from §6.3 alone
- §2's Source cells no longer cite churn figures that point at deleted files

**Implementation Note**: Final phase — confirm the whole change is green before archiving.

---

## Testing Strategy

### Unit Tests:

- `InboxList` rendering (harness smoke): populated and empty states
- `messageFor` via the component: one case per failure status, each distinct
- `AbortSignal.timeout` platform behaviour (standing guard, if the probe passes)

### Integration Tests:

- Backend: `POST /api/items` → `GET /api/items` durability round trip
- Backend: forced write failure → 500 with the documented body, item absent from the read path
- Client: 401 during capture through the real router + `AuthProvider` shell
- Client: capture-during-load merge, with controlled handler resolution
- Client: capture beside a failed load

### Manual Testing Steps:

1. Sign in, capture an idea, reload — the item is still listed
2. Stop the API, submit — a distinct actionable message, text preserved
3. Revoke the token, submit — login screen explains the expiry; text restored after re-login
4. Stop the API, reload, then capture — the item shows beside the error, not hidden
5. With the API stopped and nothing captured — the screen does not claim the Inbox is empty

## Performance Considerations

The suite is small and runs in-process. Two things to keep it that way: MSW handler
resolution rather than timers (a sleeping test is both slow and flaky), and jsdom only
where a DOM is genuinely needed. If the frontend suite ever exceeds a few seconds,
`happy-dom` is the documented escape hatch — but only after re-running the
`AbortSignal.timeout` probe against it.

## Migration Notes

No data migration. Two behavioural changes ship: `LoginPage` gains a session-expired notice,
and `InboxPage` renders the list beside a load error. Both are additive to the user-visible
surface. Rollback is per-phase — reverting Phase 3 or Phase 4 restores the previous
behaviour and its own tests go with it.

## References

- Research: `context/changes/testing-capture-durability/research.md`
- Test plan: `context/foundation/test-plan.md` (§2 Risks #1/#2, §3 Phase 1, §5, §6.3)
- Prior findings: `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-3.md`
  (F1, F2, F3, F5) and `…/impl-review-phase-2.md` (F2 "a test that cannot fail")
- Reference tests: `tests/Feature/Item/CaptureItemTest.php`, `tests/Feature/Item/ListItemsTest.php`
- Contract registry: `docs/reference/contract-surfaces.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend durability and failure contract

#### Automated

- [x] 1.1 Item feature tests pass: `php artisan test tests/Feature/Item`
- [x] 1.2 Full backend suite passes: `php artisan test`
- [x] 1.3 Larastan level 6 clean
- [x] 1.4 Pint clean
- [x] 1.5 Durability test proven able to fail by deliberate breakage

#### Manual

- [x] 1.6 Failure test exercises the real repository, not a mock

### Phase 2: Frontend test harness

#### Automated

- [ ] 2.1 Frontend tests pass: `cd frontend && npm test`
- [ ] 2.2 Frontend build passes incl. `tsc -b` over test files
- [ ] 2.3 Frontend lint passes with no new config
- [ ] 2.4 Backend suite still green
- [ ] 2.5 An unhandled request fails the run

#### Manual

- [ ] 2.6 `AbortSignal.timeout` probe outcome recorded and Phase 3 approach chosen
- [ ] 2.7 `npm test` works in a clean clone after `npm ci`

### Phase 3: Capture error surfacing and the 401 explanation

#### Automated

- [ ] 3.1 Frontend tests pass
- [ ] 3.2 Frontend build and lint pass
- [ ] 3.3 401 test proven able to fail without the fix
- [ ] 3.4 No sleep-based waits — grep confirms state-based waits only

#### Manual

- [ ] 3.5 Stop the API and submit — distinct actionable message, text preserved
- [ ] 3.6 Revoke the token and submit — login explains the expiry, text restored

### Phase 4: Inbox load failure and list visibility

#### Automated

- [ ] 4.1 Frontend tests pass
- [ ] 4.2 Frontend build and lint pass
- [ ] 4.3 Backend suite still green
- [ ] 4.4 Merge guard proven able to fail by deliberate breakage
- [ ] 4.5 Visibility test proven able to fail by deliberate breakage

#### Manual

- [ ] 4.6 Stop the API, reload, capture — item appears beside an actionable error
- [ ] 4.7 API stopped, nothing captured — screen does not claim the Inbox is empty

### Phase 5: Cookbook, contracts and test-plan backport

#### Automated

- [ ] 5.1 Full backend quality gate passes: `php composer.phar quality`
- [ ] 5.2 Frontend lint, build and test pass
- [ ] 5.3 No "TBD" left in test-plan §6.3

#### Manual

- [ ] 5.4 A fresh reader can add a frontend component test from §6.3 alone
- [ ] 5.5 §2 Source cells no longer cite churn figures pointing at deleted files
