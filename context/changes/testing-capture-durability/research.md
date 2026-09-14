---
date: 2026-09-14T10:00:55+02:00
researcher: Jakub Dudek
git_commit: 947b3ed4633cb1de612d3033743c464008d6b3d9
branch: main
repository: GettingShitDone
topic: "Test rollout Phase 1 — capture durability and error surfacing (Risks #1, #2)"
tags: [research, codebase, testing, capture, inbox, error-handling, frontend-test-infra, risk-1, risk-2]
status: complete
last_updated: 2026-09-14
last_updated_by: Jakub Dudek
---

# Research: Capture durability and error surfacing (test-plan Phase 1)

**Date**: 2026-09-14T10:00:55+02:00
**Researcher**: Jakub Dudek
**Git Commit**: `947b3ed4633cb1de612d3033743c464008d6b3d9`
**Branch**: `main`
**Repository**: GettingShitDone (`https://github.com/Dudeldel/GettingShitDone`)

> Permalink base for any reference below:
> `https://github.com/Dudeldel/GettingShitDone/blob/947b3ed4633cb1de612d3033743c464008d6b3d9/<path>#L<line>`
> Local `file:line` refs are kept inline because every other artifact under `context/`
> uses them and the downstream `/10x-plan` works against the working tree.

## Research Question

Ground rollout Phase 1 of `context/foundation/test-plan.md` for Risk #1 ("capture
confirms the save, but the idea never lands durably in the Inbox") and Risk #2
("a failure is swallowed — the user is shown an empty or stale state and believes
it, and typed text is destroyed on the way"): find the real failure paths in code,
verify or correct the plan's response guidance, locate existing tests, pick the
cheapest useful test layer, and check whether the archived impl-review findings
were actually closed by `c401365` / `bf0c59b` or only moved.

## Summary

**Eight things this research changes about how Phase 1 should be planned.**

1. **`bf0c59b` is irrelevant to these risks and cannot have closed them.** It is the
   *phase-2 backend* fix, committed ~56 minutes **before** the phase-3 frontend
   existed, and touches zero files under `frontend/`. `c401365` is the sole fix
   commit, and `git diff c401365 HEAD -- frontend/` is empty — what `c401365` left
   is what ships today.
2. **Risk #1's original symptom is genuinely closed; a sibling hole was opened by the
   same fix.** `mergeById` really does stop the initial GET erasing a concurrent
   capture. But both render branches are now gated on `loadError === null`
   (`frontend/src/items/InboxPage.tsx:84-85`), so when the *load* fails the list is
   hidden entirely — including an item that was just captured successfully. Same
   guardrail, new text, and no retry path.
3. **Risk #2's data-loss half is really fixed; its user-facing half is dead code.**
   The `sessionStorage` draft genuinely survives the 401. The "Your session expired"
   message added alongside it (`CaptureForm.tsx:37`) **can never paint** — and the
   project's own contract registry already says so in writing.
4. **Both "must challenge" assumptions for Risk #1 are correct, and one is stronger
   than the plan states.** "It appears in the list, so it was saved" is not merely a
   bad inference here — it is *by design*: `InboxPage.tsx:77` prepends the POST's
   returned DTO to local state and `mergeById` deliberately keeps local-only entries
   leading the server's list.
5. **The response guidance for Risk #1 needs one correction.** "Readable in a fresh
   session" is **not achievable** under this suite's `RefreshDatabase` + SQLite
   `:memory:` setup. The honest, achievable oracle is a **second HTTP request**.
   §"Correcting the Risk #1 oracle" below states exactly what that proves and what it
   does not.
6. **Half of Risk #2's "context to ground" is already done.** A failure-path domain
   event exists *and* is already unit-tested. That budget should move to the response
   **bodies**, which no test pins at all.
7. **The hot-spot evidence in the test plan is mislabelled and partly points at
   deleted files.** Numbers and correction in §"Misleading hot-spot evidence".
8. **Phase 1's real cost is infrastructure, and it has three concrete tripwires**
   (`tsc -b` typechecks tests, ESLint lints them, CI runs neither) plus a cold
   toolchain: `vendor/`, `composer.phar` and `frontend/node_modules` are all absent.

The single sentence that explains why both risks are live: **every automated gate
that ran on the capture UI was `tsc -b` + `eslint`, and neither can express either
risk.** The phase-3 criteria that *could* have caught them ("reload the page — the
item is still listed"; "stop the API and submit") were manual, and the review
recorded that the second one exercised only the transport-failure path
(`context/archive/2026-06-25-capture-to-inbox/plan.md:429-432`).

---

## Detailed Findings

### A. Risk #1 — the capture write path, end to end

#### A.1 A 201 attests that the INSERT call returned, not that the row is readable

`app/Infrastructure/Item/ItemRepository.php:16-32` creates and maps the **same
in-memory instance**. There is no `fresh()`, no `refresh()`, no re-read:

```php
    18	        try {
    19	            $item = Item::query()->create([
    20	                'title' => $payload->title,
    21	                'note' => $payload->note,
    22	                'bucket' => $bucket,
    23	            ]);
    24	        } catch (QueryException $e) {
...
    28	            throw new ItemPersistenceException((string) $e->getCode());
    29	        }
    30	
    31	        return $this->toDto($item);
```

Consequences for the 201 body (`toDto`, `:45-64`):

- `createdAt` / `updatedAt` (`:55-56`) are **Eloquent's PHP-side timestamps**, assigned
  before the INSERT is sent — the app server's clock, never read back from the DB.
- The five dormant fields (`:57-63`) are absent from the insert array, so they
  serialize as `null` **regardless of any DB-level column default**.
- `id` (`:48`) does come from the driver's last-insert-id, so it reflects a
  completed statement.

**No DB transaction exists anywhere in the item path.** Repo-wide, `DB::transaction`
appears exactly twice, both in auth (`app/Infrastructure/Auth/UserRepository.php:10`,
`:28`). `ItemService` (`app/Services/ItemService.php:5-11`) and `ItemRepository`
(`:5-12`) do not even import `DB`, and `ItemRepositoryInterface` exposes no
`transaction(callable)` seam. Harmless for one single-row INSERT; there is simply no
place to extend when a second write joins.

**This verifies the plan's "HTTP 200 means the row is persisted" challenge.** The
precise statement: a 201 means Eloquent's insert call returned under implicit
autocommit. It does not mean a subsequent read will find the row.

#### A.2 The client can show an item it never read back — by design

`frontend/src/items/InboxPage.tsx:77`:

```tsx
    77	      <CaptureForm onCaptured={(item) => setItems((current) => [item, ...current])} />
```

and `:13-17`:

```tsx
    13	function mergeById(fromServer: Item[], local: Item[]): Item[] {
    14	  const known = new Set(fromServer.map((item) => item.id))
    15	
    16	  return [...local.filter((item) => !known.has(item.id)), ...fromServer]
    17	}
```

The comment above it (`:7-12`) is explicit that local entries the server has not
returned are kept and lead the list. The prepend is justified at `:75-76` by the ~2s
capture NFR — one round trip, not two.

**So "it appears in the list, so it was saved" is not a careless inference in this
codebase; it is the documented behaviour.** The plan's challenge is correct and
should be stated more strongly than "an assumption to challenge": the Inbox list is
a *union of server truth and unverified local state*, and no test may use list
membership as a durability oracle.

#### A.3 F1 is closed for its stated race — and a sibling hole was opened

Before (`c524a9d`, `frontend/src/App.tsx:15-17`): `.then(setItems)` — a whole-array
replace. After (`c401365`): a functional update through `mergeById`. The reported
symptom ("Saved to your Inbox." directly above "Your Inbox is empty.") is genuinely
gone. The `ignore` flag is correct React hygiene but contributes nothing to F1 —
`mergeById` is the load-bearing half.

**Residual, introduced by the same commit** — `frontend/src/items/InboxPage.tsx:80-85`:

```tsx
    80	      {loadError !== null && (
    81	        <p style={{ color: 'var(--error)' }}>Could not load your Inbox: {loadError}</p>
    82	      )}
    83	      {/* A capture that already landed must stay visible even while the load is pending. */}
    84	      {loadError === null && loading && items.length === 0 && <p>Loading…</p>}
    85	      {loadError === null && (!loading || items.length > 0) && <InboxList items={items} />}
```

Both render branches require `loadError === null`. If the initial GET **fails** —
now very reachable, because F5 added a 10s timeout to every request — `<InboxList>`
is not rendered at all, including the item the user just captured successfully. The
user sees "Saved to your Inbox." above "Could not load your Inbox: …" with the row
invisible. The effect has `[]` deps and `loadError` is never cleared, so there is
**no retry for the rest of the session**.

This is a different text but the same class of failure as F1, and it is the most
valuable single client-side assertion Phase 1 can add.

#### A.4 The error-translation seam is narrower than the contract suggests

`app/Infrastructure/Item/ItemRepository.php:24` catches **only** `QueryException`.
Any other throwable out of `Item::query()->create()` (a `MassAssignmentException`, a
model-event listener throw, a non-`QueryException` connection failure) bypasses the
`ItemPersistenceException` → fixed-message-500 contract in `bootstrap/app.php:42-47`
and lands on Laravel's default handler — which, with `APP_DEBUG` on, renders the
exception message and trace. Recorded as an observation; see §Open Questions.

---

### B. Risk #2 — what a failure actually looks like to the user

#### B.1 The failure response contract (backend)

| Failure | Status | Body | Pinned by a test? |
|---|---|---|---|
| Persistence failure | `500` | `{"message":"The item could not be saved. Please try again."}` | **No** — only a negative assertion |
| Validation | `422` | Laravel default `{"message": "<first error>", "errors": {...}}` | **No** — status only |
| Unauthenticated | `401` | Laravel default | **No** — status only |
| Over rate limit | `429` | Laravel default | **No** — status only |

`bootstrap/app.php:42-47` — the message is a **hardcoded literal**, deliberately not
`$e->getMessage()` (unlike the auth renders at `:32`, `:37`):

```php
    40	        // Deliberately a fixed message: the exception itself carries only a SQLSTATE code,
    41	        // and the response must not echo anything derived from the failed write.
    42	        $exceptions->render(
    43	            fn (ItemPersistenceException $e) => response()->json(
    44	                ['message' => 'The item could not be saved. Please try again.'],
    45	                Response::HTTP_INTERNAL_SERVER_ERROR,
    46	            ),
    47	        );
```

The 422 shape is **not customised**: grep across `app/` + `bootstrap/` for
`ValidationException|failedValidation|invalidJson|renderable|dontReport` returns
zero hits, and neither FormRequest overrides `messages()`/`attributes()`/
`failedValidation()`. `bootstrap/app.php:25-27` forces JSON for `api/*` regardless of
the `Accept` header.

**Why this matters concretely:** `frontend/src/api.ts:68-78` reads exactly
`body.message`, and `CaptureForm.tsx:38-44` surfaces it verbatim for 422 and for the
`default` branch. **The one field the UI depends on is asserted by no test on any
status.**

#### B.2 The failure-path domain event exists and is already covered — redirect this budget

The plan lists "whether a failure-path domain event exists" as context to ground.
It exists. `app/Services/ItemService.php:29-37`:

```php
    29	        try {
    30	            $item = $this->items->create($payload, GtdBucket::Inbox);
    31	        } catch (ItemPersistenceException $e) {
    32	            // The guardrail outcome worth alerting on. Only the SQLSTATE travels — the
    33	            // captured text must never reach a log line.
    34	            LogEvent::itemCaptureFailed(GtdBucket::Inbox, $e->sqlState());
    35	
    36	            throw $e;
    37	        }
```

`app/Logging/LogEvent.php:40-46` emits `item.captured.failure` at `error` level with
`bucket` + `reason` (SQLSTATE) only — no `item_id`, no title, no note. It is already
asserted by `tests/Unit/Item/ItemServiceTest.php:45-59` (level, action, outcome,
`reason === 'HY000'`, and `! str_contains(json_encode($context), '4711')`).

**Correction to the plan:** this sub-question is answered and covered. Phase 1 should
not spend a test on it. (It is also the plan's own anti-pattern for Risk #2 —
"asserting only that an error was logged" — so re-testing it would be doubly wrong.)

Worth recording, though: on a failed write the captured text is **persisted nowhere
and echoed nowhere**. The server-side reading of "capture never loses an entry" ends
at "don't log the text"; the client's `sessionStorage` draft is the *only* thing
standing between a 500 and a lost idea.

#### B.3 The client error path: real on capture, absent on load

`frontend/src/items/CaptureForm.tsx:30-46` is genuine, distinct branching:

```tsx
    30	function messageFor(err: unknown): string {
    31	  if (!(err instanceof ApiError)) {
    32	    return 'Could not reach the server. Your text is still here — try again.'
    33	  }
    34	
    35	  switch (err.status) {
    36	    case 401:
    37	      return 'Your session expired. Sign in again — your text is saved here.'
    38	    case 422:
    39	      // The backend writes these for the user; retrying unchanged would never work.
    40	      return err.message
    41	    case 429:
    42	      return 'Too many requests. Wait a moment and try again.'
    43	    default:
    44	      return err.message
    45	  }
    46	}
```

But `InboxPage.tsx:34-38` does **not** use it:

```tsx
    34	      .catch((e: unknown) => {
    35	        if (!ignore) {
    36	          setLoadError(e instanceof Error ? e.message : String(e))
    37	        }
    38	      })
```

Raw `e.message` is rendered at `:81`. So a network failure surfaces the browser's
`"Failed to fetch"`, and a non-JSON 500 surfaces the literal string `"HTTP 500"`
(from `api.ts:69`). **Risk #2's "distinguishable, actionable state" holds on the
capture path and does not hold on the list path** — that asymmetry is the second
most valuable client assertion in Phase 1.

#### B.4 The 401 message is unreachable — verified, and already admitted in-repo

Call order, all quoted. `frontend/src/api.ts:62-66` fires the logout handler
**before** throwing:

```ts
    62	  if (res.status === 401) {
    63	    clearToken()
    64	    onUnauthorized?.()
    65	    throw new ApiError(401, 'Unauthorized')
    66	  }
```

`frontend/src/auth/AuthContext.tsx:10-12` makes that `setUser(null)`, and
`isAuthenticated: user !== null` derives from it. `ProtectedRoute.tsx:7` then swaps
the subtree:

```tsx
     7	  return isAuthenticated ? <Outlet /> : <Navigate to="/login" replace />
```

`main.tsx:16-18` puts `InboxPage` (and therefore `CaptureForm`) strictly inside that
`<Outlet />`. `setUser(null)` runs first, `setError(messageFor(err))`
(`CaptureForm.tsx:90-91`) second, both outside a React event handler and in the same
async continuation. React 19 batches them into **one** render pass; in that pass
`isAuthenticated === false`, so `CaptureForm` is not in the committed tree and its
`error` state dies with the fiber. There is no intermediate commit in which the
message could appear.

Three corroborations that this is not a speculative race:

1. **The review that approved the fix already diagnosed it.**
   `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-3.md` F2
   Detail: *"the catch sets the error, but React batches both updates … `CaptureForm`
   unmounts before the error is painted"* — and then its own Decision line claims
   *"401 now renders a session-expired message."* The fix changed neither the ordering
   in `api.ts`, nor `ProtectedRoute`, nor anything that would let the message commit.
2. **The repo's contract registry states it as current behaviour.**
   `docs/reference/contract-surfaces.md`, "Frontend capture draft": *"a 401 clears the
   token, flips the app to logged-out and unmounts the form before an error can paint"*.
3. **Nothing downstream compensates.** `LoginPage.tsx` was not touched by `c401365`;
   its only two possible strings are `'Invalid email or password.'` and
   `'Login failed. Please try again.'`, and it reads no query param, router state or
   storage flag. Repo-wide, `gsd_capture_draft` is read only by `CaptureForm.tsx`
   (lines 8, 12, 21, 23).

**Net behaviour today:** type an idea → silently bounced to a login screen with no
reason given → sign in → the text reappears in the field unannounced.

**So both halves of Risk #2's guidance are verified, with different verdicts:**
*"never destroys text the user already typed"* — **holds**, via the draft.
*"reaches the user as a distinguishable, actionable state"* — **fails on the 401
path**, and this is the highest-signal single client test in Phase 1.

The plan's two challenges are both confirmed: *"catch means handled"* is exactly the
F2 defect, and *"401 means log the user out"* is exactly the mechanism
(`api.ts:64`) that destroys the message.

---

### C. Existing test coverage, and precisely what is missing

#### C.1 What exists

| File | Tests | Relevant to |
|---|---|---|
| `tests/Feature/Item/CaptureItemTest.php` | 14 | Risk #1, #2 (backend) |
| `tests/Feature/Item/ListItemsTest.php` | 6 | Risk #1 (read path) |
| `tests/Feature/Item/ItemRepositoryTest.php` | 7 | persistence + ordering |
| `tests/Feature/Item/ThrottleTest.php` | 1 | 429 |
| `tests/Unit/Item/ItemServiceTest.php` | 4 | capture invariant + failure event |
| `tests/Unit/Item/ItemDtoTest.php` | 4 | wire shape |
| `frontend/**` | **0** | — |

`tests/Pest.php:16` applies the DB trait to the Feature suite only:

```php
    16	uses(TestCase::class, RefreshDatabase::class)->in('Feature');
```

#### C.2 The precise gap for Risk #1: there is no POST → GET round trip anywhere

- `CaptureItemTest` asserts the **POST response** plus, in four cases, an in-request
  Eloquent read (`:22`, `:52`, `:113-115`, `:158`). Same process, same request
  lifecycle.
- `ListItemsTest` reads back over HTTP, but every row is seeded by `seedItem()`
  (`tests/Pest.php:41-44`), which calls `ItemRepositoryInterface::create()`
  **directly, bypassing HTTP entirely**.
- `ItemRepositoryTest:42-50` is the one real write→separate-read round trip in the
  suite — but at repository level, in the same test method, never through the
  endpoint.

So **no test asserts that a 201 from `POST /api/items` is followed by that item
appearing in a subsequent `GET /api/items`** — which is Risk #1's exact shape.
(`assertDatabaseHas` is used nowhere in the suite; the only Laravel DB assertion
anywhere is `tests/Feature/Auth/LogoutTest.php:26`.)

#### C.3 The failure path is already tested with a *real* DB failure — reuse the mechanism

This is better than the plan assumes, and it matters because the plan's Risk #2
anti-pattern is "mocking the transport so the real error shape is never exercised".
`tests/Feature/Item/CaptureItemTest.php:118-126` forces failure by dropping the real
table:

```php
   118	it('reports a write failure as 500 without echoing the payload', function () {
   119	    Sanctum::actingAs(User::factory()->create());
   120	    Schema::drop('items');
   121	
   122	    $response = $this->postJson('/api/items', ['title' => 'my bank pin is 4711']);
   123	
   124	    $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
   125	    expect($response->getContent())->not->toContain('4711');
   126	});
```

`Schema::drop('items')` is a real DDL drop, so the **real** `ItemRepository` throws a
real `QueryException` → `ItemPersistenceException` → the real renderer. Not a mock,
not `fakeItemRepository()`. **Phase 1 should reuse this exact mechanism** rather than
inventing a new one.

What it does *not* do: the body assertion is purely negative
(`->not->toContain('4711')`). Nothing asserts the positive `{"message": …}` the UI
consumes.

By contrast the unit-level failure test uses the hand-rolled fake
(`tests/Pest.php:50-88`, `tests/Unit/Item/ItemServiceTest.php:48`) — correct for a
unit test, but it is why the repository's own `QueryException` → SQLSTATE extraction
(`ItemRepository.php:24-29`) has no direct assertion on the SQLSTATE value.

#### C.4 Other gaps found in passing

- Both 401 tests assert **status only** (`CaptureItemTest.php:90-95`,
  `ListItemsTest.php:56-58`). Across the whole suite, none of the five
  `HTTP_UNAUTHORIZED` assertions is followed by an `assertJson*`.
- `tests/Feature/Item/ThrottleTest.php:7,10,12,16` hard-codes `60` — and its source,
  `app/Providers/AppServiceProvider.php:47`, is **also** a literal. There is no
  constant to derive from, so the two can drift silently. (Risk #4 / Phase 2
  territory — recorded, not for Phase 1.)
- `frontend/src/api.ts:145` declares `export const TITLE_MAX_LENGTH = 255` with the
  comment *"Mirrors App\Const\ItemConst::TITLE_MAX_LENGTH"*. Nothing enforces the
  mirror. (Also Phase 2 territory.)

---

### D. Frontend test infrastructure — what Phase 1 must actually build

#### D.1 Confirmed baseline: nothing exists

`find frontend -path '*/node_modules' -prune -o -type f \( -name '*.test.*' -o -name '*.spec.*' \) -print` → **empty**. No `vitest.config.*`, no `setupTests.*`, no
`test` script, and `grep -niE "vitest|jest|testing-library|jsdom|happy-dom|msw|playwright|cypress|test" frontend/package.json` → **no matches at all**. `vite.config.ts`
has no `test:` block. The lockfile confirms none of these are present transitively.

Resolved versions from `frontend/package-lock.json`: react **19.2.7**, react-dom
19.2.7, react-router-dom 7.18.0, vite **8.0.16**, typescript **6.0.3**, eslint 10.5.0.
`"strict": true` is genuinely set — `frontend/tsconfig.app.json:9`.

#### D.2 Runner compatibility (checked against the npm registry: 2026-09-14)

- `vitest` latest is **5.0.0**; its peer range is `vite: '^6.4.0 || ^7.0.0 || ^8.0.0'`
  → **compatible with this project's Vite 8**. Its `@types/node` peer is
  `^22.0.0 || >=24.0.0`; the project has `^24.12.3` → compatible.
- `@testing-library/react` latest **16.3.3**, peers `react: ^18.0.0 || ^19.0.0` →
  compatible with React 19.2.7.
- Also current at check time: `jsdom` 30.0.1, `happy-dom` 20.14.5, `msw` 2.15.0,
  `@testing-library/jest-dom` 7.0.1, `@testing-library/user-event` 14.6.7.
- **Unverified:** whether Vitest 4.x/3.x accept Vite 8 (not queried — Vitest 5 is the
  obvious choice anyway).

#### D.3 Three tripwires that will break the existing CI gates

1. **`tsc -b` will typecheck test files.** `tsconfig.app.json:25` is
   `"include": ["src"]` and `:7` is `"types": ["vite/client"]`, with `strict`,
   `noUnusedLocals` and `noUnusedParameters` on. A `*.test.tsx` under `src/` is
   therefore compiled by `npm run build` — which **is** the CI gate. Either add
   `"vitest/globals"` to `types` (and the jest-dom types via a setup file), or put
   tests outside `src/` behind their own project reference. Note `tsconfig.node.json`
   covers only `vite.config.ts` and does **not** set `strict`.
2. **ESLint will lint test files.** `frontend/eslint.config.js` matches
   `['**/*.{ts,tsx}']` with `globals.browser` only, so `describe`/`it`/`expect` are
   undefined globals and `npm run lint` fails unless a test-file block is added (or
   tests import from `vitest` explicitly).
3. **CI runs no tests.** `.github/workflows/ci.yml:43-59` is the only workflow's
   frontend job and runs exactly `npm ci`, `npm run lint`, `npm run build` — no test
   step. Phase 1 must add one, and `context/foundation/test-plan.md` §5 already lists
   "frontend unit + component" as *required after Phase 1*.

#### D.4 Transport mocking: MSW over a global `fetch` stub, with one thing to probe first

`fetch` is called exactly once, in `frontend/src/api.ts:50-54`, as the **global** —
no injectable transport, no `httpClient` parameter, no module indirection. `request()`
reads `res.status` four times (`:62`, `:69`, `:78`, `:81`), calls `await res.json()`
twice (`:71`, `:85`), and depends on `fetch` **rejecting with a `DOMException` whose
`.name === 'TimeoutError'`** for the 408 path (`:55-60`).

- A **global stub** (`vi.stubGlobal('fetch', …)`) is cheap and dependency-free, but
  every fake must hand-roll `status`, `ok` and `json()`, and it bypasses
  `AbortSignal.timeout()` entirely — the 408 branch could only be tested by
  *manufacturing* a `DOMException(..., 'TimeoutError')`, which asserts the test
  author's belief about the platform rather than the platform's behaviour. That is
  the plan's own "mocking the transport so the real error shape is never exercised"
  anti-pattern.
- **MSW** intercepts at the network layer, so real `Response` semantics, real
  `res.ok`/`res.status`/`res.json()`, and (with a DOM environment supplying a real
  `AbortSignal.timeout`) the real abort plumbing stay in play. Recommended.
- Either way a DOM environment is required: `api.ts:10,14,18` uses `localStorage` and
  `CaptureForm.tsx:12,21,23` uses `sessionStorage`. Both are already wrapped in
  `try/catch` for private mode, so a throwing-storage case is testable too.
- **Probe before committing:** whether `jsdom` 30 / `happy-dom` 20 ship a
  spec-correct `AbortSignal.timeout()` that rejects with a `DOMException` named
  `TimeoutError` under **Node 20** (`ci.yml:51`; local dev is Node 22.23.2). This
  single assumption decides whether the 408 branch is genuinely coverable. A
  throwaway test should settle it in the first sub-phase.

#### D.5 Cold toolchain

`vendor/`, `composer.phar` and `frontend/node_modules` are **all absent** in this
checkout (PHP 8.3.6 and Node 22.23.2 / npm 10.9.8 are available). Nothing is runnable
until they are installed, and root `CLAUDE.md` points at `php composer.phar setup`
whose phar is not present. Separately, `composer.json`'s `setup` script runs
`npm install` + `npm run build` **from the repo root**, where there is no
`package.json` — unverified whether that currently works.

---

## Correcting the Risk #1 oracle

The plan's guidance says *"After the success confirmation, the item is readable in a
**fresh session**"*. **That is not achievable at the layer the plan picks**, and
planning against it would produce either a false-confidence test or a stalled phase.

`phpunit.xml:26-27` sets `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`, and
`tests/Pest.php:16` applies `RefreshDatabase` to the whole Feature suite. That wraps
each test in a transaction which is rolled back, and an in-memory database dies with
its connection — so a literal second session/connection cannot observe the row at all
without abandoning `RefreshDatabase`.

**The achievable and honest oracle is a second HTTP request inside the same test:**

```
POST /api/items  → 201, capture the returned id
GET  /api/items  → 200, assert the id/title is present in the response body
```

What that **does** prove — and it is exactly what Risk #1 needs:
- the row is readable through the **real read path** (`ListItemsRequest` →
  `ItemService::listByBucket` → `ItemRepository::listByBucket`), not through the
  in-memory model the 201 was built from (§A.1);
- a fresh request lifecycle and fresh service/repository instances — `AppServiceProvider.php:24-25`
  uses `bind`, not `singleton`, so nothing is carried over;
- the capture actually landed in the **Inbox** bucket, since the default listing
  filters on it.

What it **does not** prove, and should be stated in the plan rather than implied
away: commit-to-disk durability, and behaviour under a read-replica lag. Neither is
reachable from this suite, and neither is worth chasing for a single-user app —
production is Railway managed MySQL with one instance and no replica
(`context/foundation/tech-stack.md`).

The complementary half — *"when the write fails, no confirmation is shown"* — splits
cleanly across two layers, and the backend half already has its mechanism (§C.3):
force the failure with `Schema::drop('items')`, assert `500` **and** that a
subsequent `GET /api/items` does not contain the item; assert the positive body
`{"message": "The item could not be saved. Please try again."}` that the UI consumes.

---

## Cheapest useful test layer, per risk

Ordered by cost × signal. Nothing here needs e2e; Phase 4 owns the browser layer.

| # | What to prove | Layer | Cost | Why this layer |
|---|---|---|---|---|
| 1 | A 201 is followed by the item appearing in a **separate** `GET /api/items` | Feature (Pest) | ~free | The file, the suite and the auth helper all exist; §C.2 is a pure gap |
| 2 | A forced write failure returns 500 with the exact `{"message": …}` body, **and** the item is absent from a subsequent GET | Feature (Pest) | ~free | `Schema::drop('items')` already in `CaptureItemTest.php:120` |
| 3 | A failed **list load** does not hide an already-captured item, and offers a way forward | Client component | infra | §A.3 — the live residual from `c401365` |
| 4 | A 401 during capture reaches the user as a distinguishable state **and** the draft survives | Client component | infra | §B.4 — the one defect that survived the fix |
| 5 | 422 / 429 / 5xx / network each render a **different**, non-raw message | Client component | infra | §B.3 — asserts `messageFor` against real `Response` shapes via MSW |

Items 1–2 can land before any frontend infrastructure exists and should — they de-risk
the phase and give it a green first sub-phase.

**Anti-patterns to hold the plan to** (each is already a live temptation here):
- asserting `assertStatus(201)` as the durability oracle — §A.1 shows why it is not one;
- asserting list membership as the durability oracle — §A.2 shows it is *by design* not one;
- asserting `fakeItemRepository(failing: true)` was called, or that the failure event
  was logged — both already covered at `ItemServiceTest.php:45-59`, and the plan lists
  the latter as its own anti-pattern;
- snapshotting an error string — §B.3 shows the raw strings are browser- and
  status-dependent (`"Failed to fetch"`, `"HTTP 500"`); assert *distinguishability and
  actionability*, not the text;
- stubbing global `fetch` for the timeout/JSON-failure branches — §D.4.

---

## Misleading hot-spot evidence (correction for test-plan §2)

The plan cites `frontend/src` as **"16 commits/120d — highest churn in the repo"** and
`frontend/src/items` as **"5 commits/30d"**. Measured at `947b3ed`:

| Claim | Actual commits | Actual file-touches |
|---|---|---|
| `frontend/src`, 120d | **6** | 30 recursive / **16 non-recursive** |
| `frontend/src/items`, 30d | **2** | **5** |

So the figures are **file-touch counts mislabelled as commits** (16 = `App.tsx` 5 +
`api.ts` 4 + `main.tsx` 3 + `index.css` 2 + `App.css` 2, counting only files directly
in `frontend/src`). Two things follow:

1. **7 of the 16 touches are on files that no longer exist.** `App.tsx` (5) and
   `App.css` (2) were deleted in `c401365`. Nearly half the "highest churn in the
   repo" evidence points at deleted code.
2. **`frontend/src/items` has existed for exactly two commits** — `c524a9d` created it,
   `c401365` fixed it. That is churn-since-birth, which is *newness*, not instability,
   and is weak likelihood evidence on its own.

**This does not lower either risk.** It relocates the evidence onto something far
stronger and already in the plan's Source column: two CRITICAL findings were
introduced, shipped and manually signed off on this exact surface, and **`c401365`
added zero tests** while the sibling backend fix `bf0c59b` added six test files
including one written to fail. The honest likelihood argument for Risks #1 and #2 is
*"this surface has a demonstrated history of shipping guardrail defects and has no
automated gate that can express either risk"* — not a churn number.

Suggested §2 Source-column edit (wording only, no file anchors, per plan principle #3):
replace the two hot-spot citations with *"`c401365` shipped two CRITICAL guardrail
fixes with zero tests; `frontend/` has no test runner, so neither risk has an
automated gate."*

---

## Architecture Insights

- **The layering holds on this path.** Controller (`ItemController.php:23-29`) is a
  3-line HTTP↔Service map with no logic; `ItemService` imports no `Illuminate\Http`;
  `ItemRepository` returns DTOs and no Model escapes `app/Infrastructure/`. The
  capture-always-targets-Inbox invariant lives in the service
  (`ItemService.php:30`), which is the right place — and `bucket` **is** fillable on
  the model (`Item.php:28`), so that invariant rests on `CaptureItemPayload` having no
  bucket field plus the service passing the enum. `CaptureItemTest.php:97-116` is the
  test that can fail if either changes.
- **The privacy discipline around the failure path is genuinely well built** and
  triple-guarded (`ItemRepository.php:28` passes only the SQLSTATE and never chains
  `previous`; `ItemPersistenceException.php:20` builds its message from the SQLSTATE;
  `ItemService.php:34` passes only `sqlState()`). This is Risk #7 / Phase 2 territory
  and Phase 1 should not re-cover it.
- **Octane state is handled.** `AssignRequestId::CONTAINER_KEY` is the only
  per-request binding in this path and is listed in `config/octane.php:138-144`;
  services are `bind`, never `singleton` (`AppServiceProvider.php:24-25`); there are
  no jobs. No Octane-specific test is warranted for Phase 1. (Server default is
  RoadRunner — `config/octane.php:42` — not Swoole as the plan's Stack section implies.)
- **The client's capture path is deliberately optimistic** and correctly so for the
  ~2s NFR, but that optimism is precisely why durability must be asserted on the
  server side and *state distinguishability* on the client side — the two halves of
  Phase 1.

## Code References

- `app/Infrastructure/Item/ItemRepository.php:16-32` — create; `QueryException`-only catch; no re-read
- `app/Infrastructure/Item/ItemRepository.php:45-64` — `toDto`; app-clock timestamps, always-null dormant fields
- `app/Services/ItemService.php:27-42` — capture; failure event + rethrow; no transaction
- `bootstrap/app.php:42-47` — the fixed-message 500 the UI consumes
- `bootstrap/app.php:25-27` — JSON rendering forced for `api/*`
- `app/Logging/LogEvent.php:40-46` — `item.captured.failure`, error level, no captured text
- `app/Http/Requests/CaptureItemRequest.php:20-41` — sanitize-then-validate; `is_string()` guards
- `app/Providers/AppServiceProvider.php:24-25` — `bind`, not `singleton`
- `app/Providers/AppServiceProvider.php:41-50` — `api` limiter, 60/min, with the auth-ordering caveat
- `frontend/src/api.ts:50-60` — the single global `fetch` call + 408 mapping
- `frontend/src/api.ts:62-66` — 401 clears the token and flips the app logged-out **before** throwing
- `frontend/src/api.ts:68-78` — the `body.message` read the UI depends on
- `frontend/src/items/InboxPage.tsx:13-17` — `mergeById` (the real F1 fix)
- `frontend/src/items/InboxPage.tsx:34-38`, `:80-85` — raw load error; list hidden when `loadError !== null`
- `frontend/src/items/CaptureForm.tsx:30-46` — `messageFor`, incl. the unreachable 401 arm
- `frontend/src/items/CaptureForm.tsx:49`, `:55-59`, `:82-87` — draft rehydrate / mirror / conditional clear
- `frontend/src/auth/ProtectedRoute.tsx:7` — the swap that unmounts the form
- `tests/Pest.php:16`, `:41-44`, `:50-88` — RefreshDatabase scope, `seedItem`, `fakeItemRepository`
- `tests/Feature/Item/CaptureItemTest.php:118-140` — the real-DB-failure mechanism to reuse
- `tests/Unit/Item/ItemServiceTest.php:45-59` — failure-event coverage that already exists
- `phpunit.xml:26-27` — SQLite `:memory:`
- `.github/workflows/ci.yml:43-59` — frontend gates, no test step
- `frontend/tsconfig.app.json:7`, `:9`, `:25` — `types`, `strict`, `include`

## Historical Context (from prior changes)

- `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-3.md` — F1, F2
  (both CRITICAL), F3, F5. Its **Verification note** predicted this phase almost exactly:
  *"The highest-value first tests, if a runner is added, are exactly: resolve the POST
  before the GET and assert the item survives; simulate a 401 and assert the draft is
  recoverable; assert 422 / 429 / network produce different messages."* This research
  adds two it could not have known: the 401 *message* never paints, and the
  `loadError` branch hides captured items.
- `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-2.md` — F1
  (captured text in logs → `ItemPersistenceException`), F6 (the failure event), F2
  ("a test that cannot fail"). The F2 lesson is directly applicable: a test whose
  subject cannot express the failure is not coverage.
- `context/archive/2026-06-25-capture-to-inbox/plan.md:422-436` — Phase 3 success
  criteria. The two automated ones were `npm run build` and `npm run lint`; the two
  that could have caught these risks were **manual**.
- `context/archive/2026-06-24-quality-gates-toolchain/` — established Pest, Larastan
  level 6, `LogEvent`, and the ECS logging spine this phase builds on.
- `docs/reference/contract-surfaces.md` — "Frontend capture draft" and "Item endpoints"
  are the load-bearing entries Phase 1 must not contradict; the former already records
  the unmount-before-paint behaviour.

## Related Research

No prior `research.md` exists for this change; the archived slices carry `plan.md` and
`reviews/` only. This is the first research artifact under
`context/changes/testing-capture-durability/`.

## Open Questions

1. **`jsdom` vs `happy-dom` `AbortSignal.timeout()` fidelity on Node 20** — decides
   whether `api.ts:55-60`'s 408 branch is genuinely coverable or must be asserted by
   manufacturing a `DOMException`. Settle with a throwaway test in the first
   frontend sub-phase (§D.4).
2. **Where should the 401 explanation surface?** Fixing §B.4 is a product decision,
   not just a test: either `LoginPage` reads a reason (router state / storage flag),
   or `api.ts` defers `onUnauthorized()` until after the caller has painted. Phase 1's
   job is to **write the failing test**; the fix shape belongs to the plan.
3. **Should the `loadError` branch still render the list?** §A.3. Showing a
   locally-captured item beside a load error is arguably the correct behaviour, but it
   changes what the screen asserts. Same split: the test pins the requirement, the
   plan picks the fix.
4. **Non-`QueryException` throwables bypass the 500 contract** (`ItemRepository.php:24`).
   Out of Phase 1 scope; flagged for the Phase 2 HTTP-contract phase or a follow-up change.
5. **No idempotency on capture.** If the 201 is lost in transit (the 10s timeout fires
   after the server committed), the user is shown a failure for a write that succeeded
   and will retry, creating a duplicate. The PRD guardrail covers *losing* entries, not
   duplicating them, so this is deliberately out of scope — recorded so the absence is
   a choice, not an oversight.
6. **`composer.json`'s `setup` script runs npm from the repo root**, where there is no
   `package.json`. Unverified whether it currently works; Phase 1 will be the first to
   need a clean install.
