<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Eight bucket views, and emptying the Trash (S-05)

- **Plan**: `context/changes/eight-bucket-views/plan.md`
- **Scope**: Phases 1–2 of 2
- **Commits**: `c02f075`, `8fbda50`, `54c7938`
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Findings**: 3 critical, 6 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | FAIL |

All gates pass: backend 109 tests, frontend 68, Larastan 0, Pint and eslint clean.

**The slice's one stated risk is genuinely closed.** The purge cannot be aimed anywhere but the
Trash — verified four ways (no DELETE-by-id route, interface method, service method or client
function exists anywhere in the repo), and retargeting it to Reference reddens two tests, so the
scoping is pinned rather than merely commented. Scope discipline is 7 for 7. Phase 1's endpoint
is a clean MATCH end to end.

**33 mutations were applied; 14 survived fully green — 42%, worse than the previous slice's
39%.** What survived clusters in three places: the real route tree, the backend failure path,
and the navigation's actual behaviour.

## Findings

### F1 — The real route tree is untested: bucket pages can become public and CI stays green

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/AppRoutes.tsx:22`, `frontend/src/items/BucketPage.test.tsx:20-28`
- **Detail**: Two mutations, both **68/68 green**: deleting the `/bucket/:bucket` route entirely,
  and moving it *outside* `<ProtectedRoute>` so every bucket view is reachable without auth.
  `BucketPage.test.tsx` mounts its own `MemoryRouter`/`Routes` **replica** rather than
  `AppRoutes`. The irony is that `AppRoutes.tsx:8-11`'s own docblock — written in S-02 — says the
  tree was extracted "so tests can mount the real composition rather than a replica". The only
  test that mounts `<AppRoutes />` is `CaptureForm.401.test.tsx`, which never leaves `/`. So a
  build where every bucket URL renders nothing, or renders un-authenticated, ships green.
- **Fix**: Add one test mounting `<AppRoutes />` at `/bucket/reference` with no token and assert
  it lands on `/login`, plus one with a token asserting the bucket renders.
- **Decision**: FIXED — new frontend/src/AppRoutes.test.tsx mounts the REAL tree: an anonymous visit to /bucket/reference lands on the login screen, a signed-in one renders the bucket. Proven able to fail: moving the route outside ProtectedRoute now reddens it (previously 68/68 green).

### F2 — The purge failure path is untested; a swallowed error reports a successful empty

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `app/Infrastructure/Item/ItemRepository.php:102-104`, `app/Services/ItemService.php:90-96`, `tests/Feature/Item/EmptyTrashTest.php`
- **Detail**: Three mutations, all **109/109 green**: swallowing the `QueryException` in
  `emptyTrash`, deleting the service's `try/catch` and its `LogEvent::trashEmptyFailed` call, and
  deleting `trashEmptyFailed` from `LogEvent` altogether. The first is the dangerous one — the
  endpoint then returns `{"deleted": 0}` with HTTP 200, the SPA runs `setItems([])`, and the user
  is told their bin was emptied when nothing was deleted. This is verbatim the failure class the
  sibling slices guard against: `CaptureItemTest.php:148` and `ClarifyItemTest.php:269` both use
  `Schema::drop('items')` → assert 500 → assert the failure log. `EmptyTrashTest` omits it, and
  the third mutation means that if the branch ever fires in production it is a PHP fatal that
  nothing would have flagged.
- **Fix**: Mirror the sibling tests — drop the table, assert 500, assert `trash.emptied.failure`
  carries only the SQLSTATE. Verified by the reviewer to pass on HEAD and redden under the
  swallow mutation.
- **Decision**: FIXED — EmptyTrashTest drops the items table mid-purge and asserts 500, no captured text in the body, and the trash.emptied.failure event. Proven able to fail: swallowing the QueryException now reddens it (previously 109/109 green).

### F3 — Every nav link can point at the Inbox and no test notices

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `frontend/src/items/BucketNav.tsx:15`, `frontend/src/items/BucketPage.test.tsx:61`
- **Detail**: Replacing every link's `to` with `"/"` leaves **68/68 green** — the navigation's
  entire purpose can be destroyed silently. The test asserts
  `getAllByRole('link')).toHaveLength(BUCKETS.length)` with the comment *"a missing one is a list
  you can only reach by typing"*, but a link **count** is not reachability: no `href` is ever
  inspected. Dropping `aria-current` is likewise green, so nothing asserts which bucket is
  current either. FR-009's promise is that all eight lists are reachable; that promise currently
  rests on an assertion that counts anchors.
- **Fix**: In the existing `it.each(BUCKETS…)` loop, assert
  `getByRole('link', { name: label })` carries the expected `href`, and assert `aria-current` on
  the current one.
- **Decision**: FIXED — a new test asserts every nav link's href, and the it.each loop asserts aria-current on the open bucket. Proven able to fail: pointing every link at '/' now reddens (previously green, because the old assertion only counted anchors).

### F4 — The Delegation note is still invisible; the plan promised it twice

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/items/InboxList.tsx:33-52`
- **Detail**: Phase 2 #2's Contract says *"Delegation rows show the `delegatedTo` note"*, and the
  What-We're-NOT-Doing entry restates it while excluding only the write path: *"The Delegation
  view renders the who/what note; marking a waiting-for as done has no FR and belongs to a later
  slice."* Neither shipped. `InboxList` renders title, note, timestamp and the optional Clarify
  button — nothing else. A repo-wide grep for `delegatedTo` in `frontend/src` finds only the
  `Item` interface, the clarify *input* side, and a `null` default in the test fixture. **No read
  surface anywhere.** S-02 added the column with no UI; this slice's plan named that gap in its
  own Current State Analysis and then left it open. FR-007's note is write-only after two slices.
- **Fix A ⭐ Recommended**: Render `delegatedTo` in `InboxList` when present, and assert it in the
  Delegation bucket test.
  - Strength: Closes a Contract clause with a few lines; the data is already in the DTO and the
    Delegation view already loads it.
  - Tradeoff: `InboxList` grows a bucket-specific concern, though it is just another optional
    field beside `note`.
  - Confidence: HIGH — no new plumbing needed.
  - Blind spot: None significant.
- **Fix B**: Record it as deferred and correct the plan.
  - Strength: Honest about what shipped without expanding the slice.
  - Tradeoff: Leaves a documented promise unmet for a third slice, on the one field whose whole
    purpose is being read.
  - Confidence: HIGH.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — InboxList renders 'Waiting on: {delegatedTo}' when present, and the Delegation bucket test asserts it. Proven able to fail: suppressing the render reddens. FR-007's note now has a read surface after two slices.

### F5 — The irreversible confirmation counts stale client state

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/BucketPage.tsx:59`, `:108-109`
- **Detail**: `await emptyTrash()` never binds its result — the server's authoritative `deleted`
  is discarded, and `grep -rn "deleted" frontend/src` finds no consumer outside the declaration.
  The number the user confirms is `items.length` from a list fetched once on mount and never
  refetched. They diverge in both directions: if something reached the Trash after that load
  (a clarify quick-route in another tab, the API directly), the dialog says "2 items" and the
  server deletes 5 — the user authorised a smaller blast radius than fired; if the Trash was
  already emptied elsewhere, the server returns 0 and the UI still reports success. Bounded by
  single-user scope and by the operation being idempotent and bucket-scoped, but the count in an
  irreversible confirmation is exactly the number that should be trustworthy.
- **Fix**: `const { deleted } = await emptyTrash()` and report the server's number; optionally
  refetch before showing the confirmation.
- **Decision**: FIXED — purge() binds the server's `deleted` and reports it ('Discarded N items.'). A test asserts the server's 4 is shown rather than the client's stale 1.

### F6 — No unit test for `emptyTrash`, and the fixture built for it is dead — a repeat of S-02

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `tests/Pest.php:67`, `:109-118`, `tests/Unit/Item/ItemServiceTest.php`
- **Detail**: `fakeItemRepository()` gained `public bool $trashEmptied`, a `failing` branch and a
  distinctive `return 3` — and nothing reads any of it. `grep -rn "trashEmptied" tests/` hits only
  `Pest.php`. `ItemServiceTest` unit-tests capture, list, clarify and *both* clarify failure
  events, but has no `emptyTrash` case. The plan said "Unit Tests: None new", yet the
  implementation still built the unit-test seam — a recorder with no assertion is the tell that a
  test was planned and never written. **The S-02 review raised this exact finding and it was fixed
  there**; the pattern recurred one slice later.
- **Fix**: Add the two `ItemService::emptyTrash` unit tests the recorder was built for (success
  count and failure event), or reduce the fake's method to a bare `return 0;`.
- **Decision**: FIXED — two ItemService::emptyTrash unit tests now read the fixture recorder the slice built: the success count, and the failure event with its SQLSTATE. The dead-recorder pattern from the S-02 review is closed again.

### F7 — A bucket can be labelled as another bucket and nothing fails

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `frontend/src/items/buckets.ts:10-19`, `frontend/src/items/BucketPage.tsx:85`
- **Detail**: Four mutations green — reordering the buckets, renaming `Reference`, renaming
  `Next Actions` to `Trash`, and replacing the page `<h1>` with a constant. The third is the
  alarming one: the Next Actions page is then headed "Trash" and the nav shows two Trash links.
  No data is at risk — the purge gates on the bucket **slug**, not the label — but the UI lies
  about which list you are in, next to the product's only destructive control. The only label
  pinned anywhere is `'Nothing in Trash.'`.
- **Fix**: Assert the `<h1>` matches the expected label inside the existing `it.each` loop.
- **Decision**: FIXED — the it.each loop asserts the <h1> matches the bucket's own label. Proven able to fail: renaming Next Actions to 'Trash' now reddens 3 tests.

### F8 — The destructive button is indistinguishable from cancel, and focus is dropped

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/BucketPage.tsx:111-116`
- **Detail**: "Yes, discard them" and "Keep them" have byte-identical markup — no style, no class,
  no `aria-describedby`. Every other emphasised element on the page uses
  `style={{ color: 'var(--error)' }}`; the one irreversible control does not. Revealing the
  confirmation unmounts the focused button, so focus falls to `<body>` and a keyboard user tabs
  from the document top to reach the discard button. No focus trap, no Escape. The S-02 review
  made the same class of finding about `ClarifyDialog` (F9) and it was fixed there — the lesson
  did not carry across.
- **Fix**: Colour the destructive button with `var(--error)`, move focus to the **safe** button on
  open, and bind Escape to "Keep them".
- **Decision**: FIXED — the destructive button carries var(--error) and bold weight; focus moves to the SAFE button when the confirmation opens, so a reflexive Enter keeps the items; Escape backs out. Two tests pin the focus target and the Escape close.

### F9 — The purge-error live region's role and label are both unverified

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `frontend/src/items/BucketPage.tsx:124`, `frontend/src/items/BucketPage.test.tsx:184`
- **Detail**: Dropping `aria-label="Trash purge error"` is green; dropping `role="alert"`
  entirely — so a screen reader never announces that the purge failed — is also green. The comment
  at `:121-123` says the label exists so tests stop "matching on message text, which was an
  observation from the S-02 review", and then the only test of that region matches on message
  text anyway. The remedy was implemented and never adopted. (The confirmation's own label *is*
  used and *does* redden when dropped, so the mechanism works.) The region also renders empty on
  all eight buckets, and `purgeError` is not cleared when the user picks "Keep them".
- **Fix**: Swap the failure assertion to
  `findByRole('alert', { name: /trash purge error/i })`, and clear `purgeError` on cancel.
- **Decision**: FIXED — the failure assertion now goes through findByRole('alert', {name: /trash purge error/i}), which is what the label was added for; purgeError is cleared on both 'Keep them' and Escape, and a test asserts the region is empty afterwards.

### F10 — Smaller items: bare-array response, unbounded lists, `/bucket/inbox`, and status drift

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Http/Controllers/Api/V1/TrashController.php:29-32`, `app/Infrastructure/Item/ItemRepository.php:83-92`, `frontend/src/items/buckets.ts:27-29`, `context/foundation/roadmap.md:39`
- **Detail**: (a) `TrashController` returns a bare `['deleted' => …]` while its siblings return
  `Arrayable` DTOs — `app/CLAUDE.md`'s Scramble checklist wants a DTO, so the one endpoint whose
  body the SPA parses publishes no schema. (b) `listByBucket` has no limit at any layer
  (`grep` for `paginate|limit|take` over `app/` returns nothing) and this slice took it from one
  screen to eight; Reference and Trash accumulate and nothing ever removes from Reference.
  Deferred per PRD Open Question #2, recorded again now that the caller count grew sevenfold.
  (c) `isGtdBucket('inbox')` is true, so `/bucket/inbox` renders a capture-form-less duplicate
  Inbox that nothing links to and no test covers, contradicting `BucketPage.tsx:11` ("seven
  non-Inbox buckets"). (d) `roadmap.md` still says `planning` for S-05 while `change.md` says
  `implemented` — the `in-progress` flip was skipped when the process was compressed; archiving
  will set `done` regardless. (e) Progress 2.5 ("All eight buckets reachable; clarified items
  appear where routed") is marked complete, but a walk of all eight would have surfaced F4.
- **Fix**: Introduce an `EmptyTrashResultDto`; record the pagination deferral; redirect
  `/bucket/inbox` to `/`; let the archive close the roadmap row; re-walk the eight buckets after
  F4 lands.
- **Decision**: DEFERRED — recorded as debt. The bare-array response, the unbounded listByBucket (PRD Open Question #2), the /bucket/inbox duplicate and the roadmap status drift are all noted; archiving sets the roadmap row to done regardless.

## Verified sound (probed, not assumed)

- **The purge cannot be aimed anywhere but the Trash.** No argument exists at any layer —
  interface, service, controller, route, client. Retargeting it to Reference reddens 2 tests;
  dropping the `where` predicate reddens too. The scoping is pinned, not just commented.
- **Auth and rate limiting hold.** The route is inside `auth:sanctum` + `throttle:api`; moving it
  out reddens the 401 test, which also asserts nothing was destroyed en route.
- **CSRF is a non-issue** — Bearer-only auth, no `statefulApi()`, `supports_credentials: false`,
  origins pinned to `FRONTEND_URL`.
- **Repeats are safe** — the idempotency test pins `{deleted: 0}` + 200, and a hardcoded count
  reddens it.
- **Scope discipline 7/7** — no per-item delete, no re-filing, no clarify from a destination, no
  undo, no pagination, no `delegationDone` write path, no bucket counts.
- **No S-01/S-02 test was weakened** by adding `BucketNav` to the Inbox — verified against the
  diff and the existing queries.
