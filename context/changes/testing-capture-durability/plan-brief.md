# Capture durability and error surfacing — Plan Brief

> Full plan: `context/changes/testing-capture-durability/plan.md`
> Research: `context/changes/testing-capture-durability/research.md`

## What & Why

Rollout Phase 1 of the test plan: prove that a capture which reports success is genuinely
readable, and that a failure reaches the user without destroying the text they typed.
These are the PRD's first guardrail ("capture never loses an entry") and the two risks the
test plan ranks highest — and both have already broken once in production code, shipped
past a manual sign-off because no automated gate could express them.

## Starting Point

The capture slice works. What is missing is any gate that can catch it breaking. `frontend/`
has **zero tests and no runner**; CI runs only `npm ci && npm run lint && npm run build`.
On the backend, no test anywhere does `POST` → `GET` — every list test seeds rows through
the repository, bypassing HTTP — and no test pins any response body. Research also found
two live defects: the 401 "session expired" message can never paint (the form unmounts in
the same React commit), and a failed list load hides the Inbox entirely, including an item
just captured successfully.

## Desired End State

A capture that says "Saved to your Inbox." is provably readable through a separate request.
Every failure — 422, 429, 500, network, timeout, 401 — reaches the user as a distinct,
actionable message, and none of them costs the user their typed text. A blocking CI gate
defends all of it on every push.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Durability oracle | Second HTTP request, not a fresh session | `RefreshDatabase` + SQLite `:memory:` makes a literal fresh connection impossible; a second request still proves the real read path | Research |
| Defects found by tests | Test **and** fix in this phase | Test-plan §5 makes the frontend gate required after Phase 1; a phase that lands red cannot enable its own gate | Plan |
| 401 explanation | Surface it at the login screen | Logging out on a 401 is correct; only the explanation is missing, and `AuthProvider` sits above the router so the reason survives the bounce in plain state | Plan |
| Failed list load | Render the list beside the error | Closes the residual the previous fix opened — but only when there is something to show, or it would claim the Inbox is empty | Plan |
| Test stack | Vitest 5 + jsdom + MSW | `api.ts` depends on real `Response` semantics and a `DOMException` named `TimeoutError`; a `fetch` stub is the test plan's own anti-pattern | Research |
| Test layout | Colocated in `src/`, explicit `vitest` imports | Avoids all config churn — no `tsconfig` `types` entry, no new ESLint block | Plan |
| CI gate | Blocking from this phase | Turned on in Phase 2 while the suite is trivially green, so Phases 3–4 are themselves protected | Plan |
| "Never destroys text" | Scoped to the tab session | Matches what the code guarantees; `localStorage` would persist the app's most personal text indefinitely — a product decision beyond a testing phase | Plan |
| Failure-event coverage | Not re-tested | It exists and is already asserted; re-testing it is the plan's own Risk #2 anti-pattern | Research |

## Scope

**In scope:** POST→GET durability; the 500 failure body the SPA reads; a frontend test
harness from zero; distinct messages per failure status; draft survival across the 401
bounce, logout and refresh; the capture-during-load merge guard; the two fixes above; the
cookbook, contract-surface and test-plan updates.

**Out of scope:** `localStorage` drafts; 422/401/429 body *shapes* (Risk #4, Phase 2);
log-redaction (Risk #7, Phase 2); e2e and visual diff (Phase 4); capture idempotency;
widening `ItemRepository`'s catch; adding a transaction seam; coverage thresholds.

## Architecture / Approach

Two Pest tests join the existing suite and need no new tooling, so the change delivers real
Risk #1 coverage before any frontend work starts. Then Vitest + jsdom + MSW go in, with the
CI gate switched on while the suite is trivially green — so the phases that follow are
protected by the thing they are building. Phases 3 and 4 each pair a failing test with the
minimal fix that turns it green, so every phase ends with all gates passing.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Backend durability & failure contract | POST→GET round trip; 500 body pinned; item absent after a failed write | Forcing a write failure while keeping the table readable for the follow-up GET |
| 2. Frontend test harness | Vitest + jsdom + MSW + RTL, smoke test, blocking CI gate | jsdom's `AbortSignal.timeout` fidelity on Node 20 — probed explicitly, with a documented fallback |
| 3. Capture errors & the 401 fix | Distinct message per failure; draft survives; login explains the expiry | The 401 test must render the real app shell or it passes against broken code |
| 4. Load failure & list visibility | First guard on the merge invariant; captured item visible beside an error | The naive render-gate fix makes an unloaded list claim to be empty |
| 5. Cookbook, contracts, backport | §6.3 written, §2 corrected, contract surfaces updated | Low — documentation only |

**Prerequisites:** `vendor/`, `composer.phar` and `frontend/node_modules` are all absent —
a clean install is step zero. Note `composer.json`'s `setup` script runs npm from the repo
root, where there is no `package.json`.
**Estimated effort:** ~4–5 sessions; Phase 2 is the largest single chunk.

## Open Risks & Assumptions

- **jsdom's `AbortSignal.timeout` on Node 20 is unverified.** Phase 2 probes it directly;
  if it fails, the 408 branch is covered as a *mapping* assertion and the limitation is
  recorded rather than papered over.
- **Extracting `messageFor` into a shared module** (Phase 4) touches the capture path, but
  Phase 3's tests will already pin that behaviour before the move.
- **Tab-close still loses a draft.** Accepted and documented, not silently absent.
- **A 201 lost in transit after the server committed** produces a duplicate on retry. Out
  of scope; the guardrail covers losing entries, not duplicating them.
- **CI runs Node 20, local dev runs Node 22.** The probe result could differ between them;
  Phase 2 should treat the CI result as authoritative.

## Success Criteria (Summary)

- After the success confirmation, the captured idea is readable through a separate request
  — and a write that fails produces no confirmation and no row.
- Every failure path shows the user a different, actionable message, and the text they
  typed is still there afterwards.
- Reverting either fix turns CI red.
