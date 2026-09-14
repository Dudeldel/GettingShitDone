# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-09-14

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic visual diff that already catches
   the regression.
2. **User concerns are first-class evidence.** Risks anchored in "the
   builder is worried about X, and the failure would surface somewhere in
   <area>" carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `app`, `frontend/src`,
`routes`, `database`, `tests`, `config`, `bootstrap`. The 30-day window
holds 11 commits — above the usefulness threshold, but all from the S-01
capture slice, so a 120-day window was read alongside it for a fairer view
of sustained churn.

## 2. Risk Map

The top failure scenarios this project must protect against, ordered by
risk = impact × likelihood. Risks are failure scenarios in user / business
terms, not test names. The Source column cites the *evidence that surfaced
this risk* — never a specific file as "where the failure lives" (that is
research's job, see §1 principle #3).

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | Capture confirms the save, but the idea never lands durably in the Inbox — the user sees "saved" and the entry is gone later | High | High | PRD Guardrail "Capture never loses an entry"; PRD NFR (~2s visible confirmation); interview Q1; `context/archive/2026-06-25-capture-to-inbox/` reviews ph3 F1 (CRITICAL), ph3 F5; the fix commit for those findings shipped with zero tests, and `frontend/` has no test runner — so the risk has no automated gate (corrected by Phase 1 research: the original hot-spot citation counted file-touches as commits) |
| 2 | A failure is swallowed — the user is shown an empty or stale state and believes it, and typed text is destroyed on the way | High | High | interview Q4; `context/archive/2026-06-25-capture-to-inbox/` reviews ph3 F2 (CRITICAL), ph3 F3, ph3 F1; two CRITICAL guardrail fixes shipped on this surface with zero tests, and `frontend/` has no test runner — so the risk has no automated gate (corrected by Phase 1 research: the original hot-spot citation counted file-touches as commits, ~44% of them on files since deleted) |
| 3 | Clarify finishes with an item in no bucket, or in two at once | High | Medium | PRD Guardrail "Clarify never leaves an item without a bucket"; PRD FR-003, FR-004, FR-007, FR-008; `context/foundation/roadmap.md` S-02 is the next slice (`proposed`), which raises likelihood |
| 4 | The published HTTP validation contract diverges from what is actually enforced — input that should bounce passes, or bounces under a contract nobody documented | Medium | High | interview Q3; hot-spot dir `app/Http/Requests` (7 commits/120d); `context/archive/2026-06-25-capture-to-inbox/` reviews ph2 F3, ph2 F8, ph1 F1 (CRITICAL) |
| 5 | A style change shifts or hides part of the capture/Inbox screen and ships unnoticed | Medium | Medium | interview Q2; the capture/Inbox screen is the repo's most-edited frontend surface; `context/archive/2026-06-25-capture-to-inbox/` reviews ph3 F7 (same churn-figure correction as Risks #1/#2 — the original "16 commits/120d" counted file-touches) |
| 6 | A second account is created on a product whose single-user model is a permanent non-goal | High | Medium | PRD Access Control ("One account, one user"; multi-user is a permanent non-goal); `context/archive/2026-06-24-email-password-auth/reviews/impl-review.md` F1 (check-then-act race on the first-run gate) |
| 7 | The user's captured free text — the most personal data in the app — reaches logs unredacted | Medium | Medium | PRD Open Questions #1 (data privacy for cloud-stored items, deliberately deferred); `context/archive/2026-06-25-capture-to-inbox/` review ph2 F1; `context/archive/2026-06-24-quality-gates-toolchain/reviews/impl-review.md` F2 (name-based, value-blind redaction) |

Risk #3 describes a flow that is not built yet. It is kept because it is the
PRD's second guardrail and the next roadmap slice, but the phase covering it
(§3 Phase 5) is explicitly gated on S-02 landing.

Considered and deliberately excluded from the map: cross-user data access
(IDOR) — the product is permanently single-user, so there is no second
principal to steal from; the assumption that holds that up is the
account-creation gate, which is Risk #6. Octane/Swoole worker state leakage
is recorded as an Unknown in the roadmap but has not bitten in any archived
slice or the interview; without a concrete leak there is nothing to assert,
so it belongs to observability rather than this plan.

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | After the success confirmation, the item is readable through a **second HTTP request** (a literal fresh session is unreachable — `RefreshDatabase` + SQLite `:memory:` roll the write back and the DB dies with the connection); when the write fails, no confirmation is shown and no row is readable | "HTTP 200 means the row is persisted"; "it appears in the list, so it was saved" | What the capture write path does when persistence fails; how the client sequences the initial list load against a concurrent capture; the response contract on failure | Integration (endpoint test with persistence forced to fail) plus a client component test with controlled async ordering — not e2e | Asserting a mock was called; happy-path only; asserting status 200 instead of observable durability |
| #2 | A backend failure reaches the user as a distinguishable, actionable state, and never destroys text the user already typed | "catch means handled"; "401 means log the user out"; "an empty list means there is no data" | The client's real error-handling path; what the backend returns in the failure body (the failure-path domain event is no longer an open question — it exists and is already covered by `tests/Unit/Item/ItemServiceTest.php`) | Client component/integration tests on the error and 401 paths, plus an endpoint test asserting the failure body | Asserting only that an error was logged; snapshotting an error string; mocking the transport so the real error shape is never exercised |
| #3 | Every path through the decision tree terminates with the item in exactly one bucket, and no answer sequence leaves it in the Inbox | "the happy path through the tree implies every branch terminates"; "the enum guarantees one bucket" — an enum guarantees one *value*, not that the transition happened | Where the routing decision lives once S-02 lands; whether the invariant is also enforced in the data layer; what the timer branch does when abandoned | Unit tests on the pure-PHP entity across the full branch matrix, plus one endpoint test for persistence of the transition | The oracle problem — deriving the expected bucket by reading the routing code instead of from PRD FR-004/005/006/007 |
| #4 | For each documented field, the boundary that should reject does reject, and the published contract matches the enforced one | "the validation rule string is the contract" — they have already diverged once; "a 422 means the right rule fired" | Which constant owns each boundary; where defaults are applied (HTTP edge vs domain); the sanitizer's behaviour on malformed UTF-8 | Endpoint tests on the boundaries plus unit tests on the sanitizer, with limits derived from the owning constant | Hard-coding the limit in the test (a lived finding); testing only the rejecting side; asserting the message text instead of the rejection |
| #5 | A style change that moves or hides a key element of the capture/Inbox screen fails a check before merge | "it looks fine in my browser"; "a snapshot proves the layout is correct" — it proves it is *unchanged* | Which screens and states are worth pinning; whether dark mode is a supported state or half-built; what the dead scaffold CSS currently affects | Deterministic visual snapshot on 2–3 states — not a vision model | Pinning whole pages so every copy change goes red; a snapshot with no meaning; a VLM where a diff suffices |
| #6 | A second registration is refused even when two requests arrive concurrently | "check-then-act is enough"; "there is one user, nobody will try" | How the first-run gate is implemented; whether a database constraint backs it; what the throttle actually limits | Endpoint test on the gate; the race shape most likely asserted through a database-level constraint rather than real parallelism | Testing only the sequential second attempt — which already passes — and calling the race covered |
| #7 | Captured text never appears verbatim in a log record on any path, including the error path | "the redactor covers it" — it is name-based, value-blind, and does not recurse objects; "errors don't carry user input" | Which log calls carry the payload; what the redactor actually traverses; where the error path writes | Unit tests on the redactor with nested and object payloads, plus an endpoint test asserting the log record on a forced failure | Asserting the redactor was invoked instead of asserting the record's content |

## 3. Phased Rollout

Each row is a discrete rollout phase that will open its own change folder
via `/10x-new`. Status moves left-to-right through the values below; the
orchestrator updates Status as artifacts appear on disk.

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | Capture durability and error surfacing | Prove the confirmation never lies and a failure reaches the user without destroying their text | #1, #2 | integration, client component — bootstraps frontend test infrastructure | complete | `context/archive/2026-09-14-testing-capture-durability/` |
| 2 | HTTP edge contract and sensitive data | Prove the published contract is the enforced one and captured text stays out of logs | #4, #7 | integration, unit | not started | — |
| 3 | Single-account invariant | Prove a second account cannot be created, including under concurrent requests | #6 | integration | not started | — |
| 4 | Browser layer and visual regression | One real end-to-end walk of the north star plus a pin against layout drift | #5, browser half of #1 | e2e, deterministic visual diff | not started | — |
| 5 | Clarify routing invariant | Prove every decision-tree path ends in exactly one bucket | #3 | unit, integration | complete | — (covered by roadmap slice S-02, not by its own change folder — see §6.6) |

Phase order follows cost × signal: the two guardrail risks are attacked at
the cheapest layer that can catch them (Phase 1), then the backend edge
where churn and lived findings concentrate (Phase 2), then the small phase
holding up the access model (Phase 3). The browser layer is deliberately
fourth — it is the most expensive layer and only pays once the cheaper ones
are green. Phase 5 was ordered last because it was blocked until roadmap slice
S-02 (`guided-clarify-routing`) shipped; S-02 has since shipped and been
archived, so the block is gone.

**Status audit, 2026-09-14.** The Status column is orchestrator state — it
records whether a *phase* was run, not how much coverage happens to exist. Those
two drifted apart, because feature slices shipped tests against these risks on
their own. An audit of `tests/` against §2 found: Phase 5 fully covered (now
`complete`); Phase 2 covered except for one half of its goal; Phase 3 covered
only on the case §2 names as the anti-pattern; Phase 4 untouched. Phases 2, 3
and 4 stay `not started` because none of them has been *run* and each still has
real work left — §6.6 records exactly what, so whoever opens them scopes the
remainder instead of re-deriving it.

## 4. Stack

The classic test base for this project. AI-native tools (if any) carry a
`checked:` date so future readers can see which lines need re-verification.

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration (backend) | Pest | ^4.7 | with `pest-plugin-laravel` ^4.1; `RefreshDatabase` applies to the Feature suite only, SQLite in-memory |
| static analysis | Larastan | ^3.10 | level 6, enforced in CI |
| format | Pint | ^1.27 | `--test` in CI |
| unit + component (frontend) | Vitest + React Testing Library | vitest 5.0.0, @testing-library/react 16.3.3, jsdom 30.0.1 | landed in §3 Phase 1; one config (`vite.config.ts`), tests colocated in `src`, explicit `vitest` imports rather than `globals: true`; checked: 2026-09-14 |
| API / transport mocking | MSW (node) | 2.15.0 | intercepts at the network layer so real `Response` semantics and the real abort plumbing stay in play; `onUnhandledRequest: 'error'`. A `fetch` stub was rejected as the §2 Risk #2 anti-pattern; checked: 2026-09-14 |
| e2e | none yet — see §3 Phase 4 | — | Pest 4 already ships Playwright-backed browser testing, but the SPA is a separate origin not served by Laravel; standalone Playwright vs the Pest plugin is a Phase 4 research decision |
| visual diff | none yet — see §3 Phase 4 | — | deterministic only, per §1 principle 1 |
| (optional) AI-native | Playwright MCP — checked: 2026-09-14 | n/a | available in-session; use for exploratory diagnosis, not as a test layer — a deterministic diff already covers Risk #5 |

**Stack grounding tools (current session):**
- Docs: none — Context7 or an equivalent framework-docs MCP is not available in current session; stack facts were read from `composer.json`, `frontend/package.json` and `context/foundation/tech-stack.md`; checked: 2026-09-14
- Search: built-in web search (not Exa.ai) — used to confirm Pest 4 ships Playwright-backed browser testing before writing the §3 Phase 4 row; checked: 2026-09-14
- Runtime/browser: Playwright MCP, Chrome DevTools MCP and browser-use all exposed in-session — a real option for Phase 4 exploration; checked: 2026-09-14
- Provider/platform: Atlassian and Google Drive exposed; neither is relevant to this project's quality gates. No GitHub, Railway or database MCP in session, so CI and deploy gates stay CLI-driven; checked: 2026-09-14

## 5. Quality Gates

The full set of gates that must pass before a change reaches production.
"Required for §3 Phase N" means the gate is enforced once that rollout
phase lands; before that, the gate is planned.

| Gate | Where | Required? | Catches |
|---|---|---|---|
| format check (Pint) | local + CI | required | formatting drift |
| static analysis (Larastan level 6) | local + CI | required | type and nullability drift |
| frontend build and lint | CI | required | type errors, lint violations |
| backend unit + integration (Pest) | local + CI | required | logic regressions |
| per-edit lint hook | local (agent loop) | required | formatting and trivial errors at edit time |
| per-edit typecheck hook | local (agent loop) | required | type drift at edit time |
| frontend unit + component | local + CI | **required** (enforced since §3 Phase 1) | client-side error handling and state regressions |
| log-redaction assertion | CI | required after §3 Phase 2 | captured text leaking into log records |
| e2e on the capture-to-Inbox flow | CI on PR | required after §3 Phase 4 | a broken north-star path |
| deterministic visual diff | CI on PR | optional after §3 Phase 4 | rendering and layout regressions |

## 6. Cookbook Patterns

How to add new tests in this project. Each sub-section is filled in once
the relevant rollout phase ships; before that, the sub-section reads
"TBD — see §3 Phase N."

### 6.1 Adding a backend unit test

- **Location**: `tests/Unit/`, mirroring the namespace of the class under test.
- **Naming**: `it('...')` describing observable behaviour, per `tests/CLAUDE.md`.
- **Reference test**: `tests/Unit/Item/GtdBucketTest.php`.
- **Run locally**: `php artisan test --filter=Name`.

### 6.2 Adding a backend integration test

- **Location**: `tests/Feature/`, which is where `RefreshDatabase` applies.
- **Mocking policy**: exercise real persistence; mock only at an external boundary.
- **Reference test**: `tests/Feature/Item/CaptureItemTest.php`.
- **Run locally**: `php artisan test tests/Feature/Item/CaptureItemTest.php`.

### 6.3 Adding a frontend component test

- **Location**: colocated — `src/items/CaptureForm.test.tsx` sits beside
  `CaptureForm.tsx`. `tsconfig.app.json` has `"include": ["src"]`, so `tsc -b`
  type-checks test files under `strict`: a type error in a test fails
  `npm run build`, which is intended.
- **Imports**: import `describe` / `it` / `expect` / `vi` explicitly from
  `vitest`. Do **not** enable `globals: true` — explicit imports are why this
  layer needed no `types` entry in `tsconfig.app.json` and no new block in
  `eslint.config.js`.
- **Run locally**: `cd frontend && npm test` (or `npm run test:watch`).
- **Mocking policy — MSW, never a `fetch` stub.** `src/test/server.ts` owns the
  handlers; `src/test/setup.ts` owns the lifecycle and listens with
  `onUnhandledRequest: 'error'`, so a test that forgets a handler fails loudly
  instead of reaching the network. Override one route per test with
  `server.use(...)`. A hand-rolled `fetch` stub would have to fake `status`,
  `ok` and `json()`, and would assert what we *believe* a `Response` does — the
  §2 Risk #2 anti-pattern.
- **Ordering**: control it with handler resolution (a promise the test releases),
  never a sleep. See the `gatedListHandler` helper in
  `src/items/InboxPage.test.tsx`.
- **Mount the real shell when the behaviour needs it.** The 401 path only
  reproduces when `ProtectedRoute` can actually swap `<Outlet />` for
  `<Navigate />`, so `src/items/CaptureForm.401.test.tsx` renders `AppRoutes`
  inside `AuthProvider` — the same composition `main.tsx` uses. A test that
  mounted `CaptureForm` alone would pass against the broken code.
- **Assert distinguishability, not copy.** For error paths, assert that each
  failure reads differently and is actionable (see the "gives every failure a
  different message" test), not that it equals a particular string. Snapshotting
  an error string is an explicit anti-pattern for Risk #2.
- **Reference tests**: `src/items/CaptureForm.test.tsx` (failure matrix, draft
  survival, confirmation), `src/items/CaptureForm.401.test.tsx` (full app shell),
  `src/items/InboxPage.test.tsx` (async ordering, load-failure states),
  `src/items/InboxList.test.tsx` (simplest possible starting point).
- **Prove it can fail.** Every test added in Phase 1 was verified by deliberate
  breakage before being accepted. This is not optional ceremony: the archived
  `ph2 F2` finding was a test that could not fail.

### 6.4 Adding a test for a new API endpoint

- **Test type**: integration (preferred).
- **Pattern**: assert the request/response contract *and* the persisted
  side-effect; derive boundary values from the constant that owns them
  rather than repeating the literal.
- **Reference test**: `tests/Feature/Item/ListItemsTest.php`.
- **When to add e2e instead**: only when the failure mode needs the full
  deployed shape — two origins, real auth, real browser.

### 6.5 Adding an e2e or visual test

- TBD — see §3 Phase 4. This is where the browser-runner decision, the
  seed-test conventions, the storage-state setup, and the pinned visual
  states will be recorded.

### 6.6 Per-rollout-phase notes

**Phase 1 — Capture durability and error surfacing** (landed 2026-09-14;
`5d6341f`, `42383cb`, `4e3bbcb`, `cc8f5e5`).

- **The durability oracle is a second HTTP request, not a fresh session.** Under
  `RefreshDatabase` + SQLite `:memory:` the write is rolled back and the database
  dies with the connection, so a literal fresh session is unreachable. A second
  request still proves the real read path, a fresh request lifecycle and freshly
  resolved services (`AppServiceProvider` binds, never singletons). It does not
  prove commit-to-disk; that is out of reach at this layer and not worth chasing
  for a single-instance deployment.
- **Force a backend write failure with a unique index, not `Schema::drop`.**
  Dropping the table also breaks the follow-up read, so the "failed write leaves
  nothing readable" assertion cannot run. Adding a unique index and capturing the
  same title twice raises a real `QueryException` through the real repository
  while leaving the table readable.
- **`AbortSignal.timeout` under jsdom: the rejection is named `TimeoutError` but
  is NOT `instanceof DOMException`** — jsdom installs its own `DOMException`
  global while the rejection originates in Node's realm. `api.ts` therefore
  matches on `name`, not `instanceof`; a browser has one realm so its behaviour
  is unchanged. Checked on jsdom 30 / Node 22 local and Node 20 CI: 2026-09-14.
  Re-verify if the DOM environment or Node major changes.
- **The timeout test costs ~10s** because it waits out the real
  `REQUEST_TIMEOUT_MS`. It is paid once, in
  `src/test/abort-signal.probe.test.ts`, rather than per failure case. If the
  frontend suite ever needs to get faster, making that timeout injectable is the
  lever.
- **Known gap, deliberately not widened**: `api.ts`'s `getToken()` reads
  `localStorage` without a `try/catch`, so an environment that blocks site data
  entirely would throw before any request is sent. Narrow (ordinary private mode
  still provides storage) and out of Phase 1's scope.

**Phase 5 — Clarify routing invariant** (covered 2026-09-14; no change folder).

- Coverage landed **inside roadmap slice S-02**, not through this rollout. The
  branch matrix lives in `tests/Unit/Clarify/ClarifyDecisionTest.php`, whose
  `never leaves a clarified item in the Inbox, on any path through the tree` is
  literally Risk #3's oracle; persistence of the transition is covered by
  `tests/Feature/Item/ClarifyItemTest.php`. Both layers §2 asked for exist, so
  the phase is closed rather than re-opened.
- The §2 anti-pattern (deriving the expected bucket by reading the routing code)
  was avoided: the expectations are written against PRD FR-004/005/006/007, and
  `ClarifyDecision`'s own docblock lists the terminating paths in the same terms.

**Phase 2 — HTTP edge contract and sensitive data** (audited 2026-09-14; still
`not started`, but narrower than written).

- **Already covered, incidentally:** boundary tests derive their limits from the
  owning constant rather than hard-coding them (`ItemConst` in
  `tests/Feature/Item/{CaptureItemTest,ClarifyItemTest,UpdateItemAttributesTest}.php`)
  — the lived finding §2 warns about. Risk #7 has
  `tests/Unit/Logging/RedactSensitiveDataTest.php` plus failure-path log-record
  assertions across the Feature suite.
- **What actually remains** is the first half of the goal: *nothing checks that
  the published contract is the enforced one.* There is no test, and no CI step,
  that compares the Scramble output (`api.json`) against the rules the
  FormRequests really apply — so the two can drift silently, which is the exact
  failure Risk #4 names. Scope the phase to that when it opens.

**Phase 3 — Single-account invariant** (audited 2026-09-14; `not started`).

- `tests/Feature/Auth/RegisterTest.php` covers only the **sequential** second
  registration — which is precisely what §2 lists as this risk's anti-pattern
  ("Testing only the sequential second attempt — which already passes — and
  calling the race covered").
- The gap is real, not pedantic: the gate is `lockForUpdate` inside a
  transaction (`UserRepository::createFirstUserOrNull`), **not** a database
  constraint. `users.email UNIQUE` would stop two concurrent registrations using
  the same address, but the invariant is "at most one user *at all*" — two
  concurrent registrations with *different* addresses are the shape that has to
  be proven, and nothing asserts it.

## 7. What We Deliberately Don't Test

Exclusions agreed during the rollout (Phase 2 interview, Q5). Future
contributors should respect these unless the underlying assumption changes.

- **`/health` and the scaffold endpoints** — zero blast radius; they prove
  the process is up, and CI plus the deploy already tell us that.
  Re-evaluate if a health endpoint ever gates traffic or a rollback.
  (Source: Phase 2 interview Q5.)
- **Visual polish as such** — "does this look good" is not a defect and any
  test asserting it would be a false signal. The testable neighbour is
  layout drift, which is Risk #5. Re-evaluate never; this is a category
  boundary, not a budget decision. (Source: Phase 2 interview Q2.)

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-09-14 (§2 Source cells and Risk #1/#2 response
  guidance corrected from `context/changes/testing-capture-durability/research.md`:
  churn figures were file-touches not commits, the "fresh session" oracle is unreachable
  under `RefreshDatabase`, and the failure-path domain event is already covered)
- Stack versions last verified: 2026-09-14 (frontend rows are now installed and running,
  not projected: vitest 5.0.0, jsdom 30.0.1, msw 2.15.0, @testing-library/react 16.3.3)
- AI-native tool references last verified: 2026-09-14
- §3 Status column last audited against `tests/` on disk: 2026-09-14 — Phase 5 moved
  `not started` → `complete` (covered by slice S-02); Phases 2 and 3 left `not started`
  with their remaining scope recorded in §6.6; Phase 4 untouched. Re-audit whenever a
  feature slice ships tests that land on a risk in §2.

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes.
