<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: What an item can do after it has been clarified

- **Plan**: context/changes/item-actions-after-clarify/plan.md
- **Scope**: Phases 1–3 of 3 (full plan)
- **Date**: 2026-09-14
- **Verdict**: NEEDS ATTENTION (all 10 findings fixed — see Decisions)
- **Findings**: 0 critical, 7 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

Gates re-run at review time: Pint clean, PHPStan level 6 = 0 errors, Pest 165/165,
`npm run build` ok, ESLint clean, Vitest 114/114.

The slice's stated central risk is genuinely closed: `refile` writes only `bucket`,
`setCompleted` writes only `completed_at`, both guard-and-write in one statement, and both
invariants redden under deliberate breakage. Every finding below is around that core, not in it.

## Findings

### F1 — Refiling into the Inbox is refused only at the HTTP edge; the domain assertion the plan specified was never wired

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence / Safety & Quality
- **Location**: app/Services/ItemService.php:100-113, app/Domain/Item/GtdBucket.php:37-44, app/Exceptions/ItemActionNotAllowedException.php:26-29
- **Detail**: `GtdBucket`'s own docblock claims the predicate is "read by both the HTTP edge and
  the domain, so a loosened rule at one cannot smuggle a value past the other". For
  `isDestination()` that is false: its only backend caller is `RefileItemRequest.php:33`.
  `RefileItemPayload::fromArray` uses bare `GtdBucket::from`, the service passes it through, and
  `ItemRepository::refile` guards only the *source* row. `ItemActionNotAllowedException::refileToInbox()`
  was written for exactly this and has no caller anywhere in the repo.
  Verified by execution, not by reading: `app(ItemService::class)->refile($id, RefileItemPayload::fromArray(['bucket' => 'inbox']))`
  returned normally and left the row in `inbox`. The item was then clarifiable again over plain
  HTTP, and that clarify wrote `completed_at => null` — erasing a completion, which is the exact
  data-loss the slice was shaped to prevent. Not reachable through the current HTTP surface, so
  this is missing defence-in-depth rather than a live hole; the contrast is `ClarifyDecision.php:71-73`,
  where the same rule *is* enforced in the domain.
- **Fix**: Guard in `ItemService::refile` — `if (! $payload->destination->isDestination()) { throw ItemActionNotAllowedException::refileToInbox(); }` — plus a unit test at the service seam. This also retires the dead factory.
  - Strength: Restores the two-layer guarantee the plan specified and the enum advertises; mirrors the clarify path exactly.
  - Tradeoff: Three lines and one test; no behaviour change at the HTTP edge.
  - Confidence: HIGH — the hole and its consequence were both reproduced in this repo.
  - Blind spot: None significant.
- **Decision**: FIXED — guard wired into ItemService::refile; dead factory now has its caller; unit test at the service seam, mutation-killed.

### F2 — A completed item refiled into a non-action bucket is permanently stuck "done"

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality / Architecture
- **Location**: app/Infrastructure/Item/ItemRepository.php:101-107 and :126-131
- **Detail**: `refile` correctly preserves `completed_at` but places no constraint on the
  destination, while `setCompleted` refuses non-action buckets. Reproduced over plain HTTP, and
  reachable from the shipped UI (complete in Next Actions → Move → Reference):
  complete → 200 `completedAt` set; refile to `reference` → 200, completion carried;
  un-complete → 422 "Only Next Actions, Projects, Calendar and Delegation items can be marked done."
  The item now sits in Reference carrying a completion no endpoint can clear. The UI renders it:
  `InboxList.tsx:65-67` shows `✓ Done` regardless of bucket, and Reference has no checkbox and no
  "Show completed" toggle — so there is no affordance to undo it. This contradicts
  `GtdBucket::isActionBucket()`'s own rationale that "done" is uninterpretable in those buckets.
- **Fix A ⭐ Recommended**: Clear `completed_at` when refiling into a non-action bucket, as a deliberate write with the reasoning stated next to the comment that forbids the opposite.
  - Strength: Keeps every destination reachable, which is the plan's "Trash is an ordinary bucket" position; the state stays interpretable in every bucket.
  - Tradeoff: A move silently discards a completion — the one thing `refile` was written not to do, so the comment must be explicit that this is the non-action case and why it differs.
  - Confidence: MEDIUM — it resolves the contradiction, but it weakens a rule this slice spent its whole risk budget on.
  - Blind spot: Whether the user would rather keep the completion as history than keep the move frictionless.
- **Fix B**: Refuse the move with `ItemActionNotAllowedException` when the item is completed and the destination is not an action bucket.
  - Strength: No data is ever discarded; the refusal is explicit and message-driven.
  - Tradeoff: Introduces a move the UI offers but the server rejects — the "present-and-doomed-to-422" shape this slice explicitly avoided for the checkbox. The picker would have to filter destinations per item.
  - Confidence: MEDIUM — correct but more surface, in both API and UI.
  - Blind spot: Not verified how much picker logic filtering per-item completion would add.
- **Decision**: FIXED via Fix A — refileWrite() clears completed_at when the destination is not an action bucket; two-way test added, mutation-killed.

### F3 — "carries an existing completion across the move" cannot fail for a re-stamping write

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Item/RefileItemTest.php:47
- **Detail**: The assertion is `expect($moved['completedAt'])->not->toBeNull()`, which cannot tell
  *carried* from *re-stamped*. Verified: adding `'completed_at' => now()` to `refile`'s update
  array leaves `RefileItemTest` 9/9 green **and the full suite 165/165 green**. A refile that
  destroyed the original completion timestamp would ship unnoticed. This is the project's own
  `lessons.md` rule — "a test is not coverage until deliberate breakage has reddened it" — and the
  phase-3 breakage pass missed it because it mutated the *null* case, not the *re-stamp* case, then
  ticked Progress row 3.3 as covering "refile-preserves-completion".
- **Fix**: Capture `completedAt` before the move and assert equality rather than non-nullity.
- **Decision**: FIXED — assertion now pins the ORIGINAL timestamp, and the test travels the clock 1 minute between the writes (without that, same-second writes made carried and re-stamped string-identical and the mutation survived).

### F4 — `affected === 0` is read as "the guard rejected it", which is false on MySQL

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Infrastructure/Item/ItemRepository.php:112-116 and :140-144; config/database.php (mysql `options`)
- **Detail**: MySQL's `update()` returns *changed* rows; SQLite returns *matched* rows.
  `config/database.php`'s mysql `options` sets only `ATTR_SSL_CA` — no `MYSQL_ATTR_FOUND_ROWS`.
  Production is MySQL (`.env.example:27`, tech-stack.md); local and test are SQLite (`.env`).
  So a write whose values are all already equal returns 0 in production and 1 under test, and the
  code then throws the wrong exception: a second identical `complete` within the same second would
  answer 422 "Only Next Actions… can be marked done" about an item that *is* a Next Action.
  `updated_at` usually masks this, but timestamps are second-granularity. The same reasoning
  applies to the pre-existing `clarify`. Structurally invisible to the suite.
- **Fix**: Set `PDO::MYSQL_ATTR_FOUND_ROWS => true` in the mysql/mariadb `options` so production matches the semantics the code and tests assume.
  - Strength: One line; makes SQLite and MySQL agree, so the suite regains authority over this branch.
  - Tradeoff: Changes affected-row semantics for any future code reading an update's return value.
  - Confidence: MEDIUM — the config gap and the two drivers' documented behaviour are confirmed; **not** executed against a real MySQL, since none is available here.
  - Blind spot: Not reproduced end to end on MySQL.
- **Decision**: FIXED — Mysql::ATTR_FOUND_ROWS => true on both the mysql and mariadb connections, matching the matched-rows semantics the code and the SQLite suite assume. NOT executed against a real MySQL: no pdo_mysql here (PHP 8.3).

### F5 — Focus is dropped to `<body>` when a completion hides the row

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: frontend/src/items/BucketPage.tsx:86-94
- **Detail**: Phase 3 fixed exactly this defect for the refile picker — `cancelRefile` restores the
  trigger, `handleRefiled` falls back to the heading, and all three paths have tests. The
  completion path never got the same treatment: with `showCompleted` false (the default), ticking
  the box unmounts the checkbox the user is standing on. Verified in the live browser at 1280px —
  after clicking a row's checkbox, `document.activeElement` is `BODY` and the list is empty. The
  `role="status"` message is good compensation for a screen reader, but a keyboard user is returned
  to the top of the document.
- **Fix**: In `handleComplete`'s success branch, when `next && !showCompleted`, call `headingRef.current?.focus()` — the move `handleRefiled` already makes — and assert it.
- **Decision**: FIXED — handleComplete focuses the heading when the row is about to be hidden, the move handleRefiled already made; test added, mutation-killed.

### F6 — Overlapping completions on one item are last-write-wins, and the rollback restores a client-fabricated timestamp

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: frontend/src/items/BucketPage.tsx:73-104
- **Detail**: No in-flight tracking, abort, or sequence guard. Two rapid toggles on one row (easy
  with "Show completed" on, where the row does not disappear) issue two POSTs and whichever
  *resolves* last wins the state write, so the checkbox can settle on stale server state until the
  next reload. Compounding it, `before` is read off the already-optimistically-mutated render, so a
  failed second request restores the client-fabricated `new Date().toISOString()` rather than the
  true prior value.
- **Fix**: Track pending item ids in a `Set<number>` and disable the checkbox while its own request is in flight.
  - Strength: Removes the interleaving entirely rather than papering over it; small and local.
  - Tradeoff: A brief disabled state on a control that is otherwise instant.
  - Confidence: MEDIUM — read from the code and reported by the review agent; the interleaving itself was not reproduced here.
  - Blind spot: How reachable this is in practice for a single-user app on a local network.
- **Decision**: FIXED — a pending-id set drives the checkbox's disabled attribute, so the second of two rapid toggles never lands. The early-return guard was REMOVED rather than kept: no test could redden it (the disabled attribute blocked the click first), and untestable redundancy is what lessons.md calls decoration.

### F7 — `/bucket/inbox` offers a Move button the domain always refuses

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: frontend/src/items/BucketPage.tsx:218
- **Detail**: `isGtdBucket('inbox')` is true, so `/bucket/inbox` renders `BucketPage`, and
  `onRefile` is passed unconditionally — every row gets a Move button whose request the repository
  refuses with `refileAnUnclarifiedItem()`. This contradicts the principle the same slice states one
  line above for the checkbox: "Omitted elsewhere, so the checkbox is absent rather than
  present-and-doomed-to-422" (`InboxList.tsx:20-22`). Only reachable by typing the URL — `BucketNav`
  sends inbox to `/` — and `BucketPage.test.tsx:45` filters `'inbox'` out of its `it.each`, so
  nothing covers it.
- **Fix**: `onRefile={bucket === 'inbox' ? undefined : openRefile}`, and drop `'inbox'` from the test's exclusion list.
- **Decision**: FIXED — onRefile omitted in the Inbox, matching the rule the checkbox already followed; test added, mutation-killed.

### F8 — Three comments still say clarify is the only writer of `completed_at`

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Models/Item.php:16-17, app/Dto/ItemDto.php:16-17, frontend/src/api.ts:202-203
- **Detail**: Each states that `completed_at` is set only by clarify's two-minute timer. `/complete`
  is now a second write path, so all three are false. The `Item.php` one is load-bearing: it is the
  stated reason `completed_at` is safe to omit from `#[Fillable]`. Phase 3 was the stale-comment
  phase and hunted "FR-010 is parked" specifically; this second family of stale claims — created by
  this same slice — was not in its search.
- **Fix**: Update all three to name both write paths.
- **Decision**: FIXED — all three comments now name both write paths.

### F9 — `item.completion.marked` breaks the `domain.action.outcome` naming convention

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Logging/LogEvent.php:130-136
- **Detail**: Siblings end in the outcome — `item.captured.success`, `item.clarified.success`,
  `item.refiled.success` — and this family's own failure is `item.completion.failure`. Only the
  success action omits `.success`, so a filter on actions ending `.success` (the convention in root
  CLAUDE.md) silently misses every completion.
- **Fix**: `item.completion.success` discriminated by the existing `context.completed`, or `item.completion.marked.success` / `.cleared.success`.
- **Decision**: FIXED — item.completion.success discriminated by context.completed; three assertions updated, mutation-killed.

### F10 — Progress rows 3.1–3.4 cite a commit that is not an ancestor of HEAD

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/changes/item-actions-after-clarify/plan.md (Phase 3 Progress rows)
- **Detail**: The rows carry `01710e3`, which `git merge-base --is-ancestor` reports is not reachable
  from HEAD and which `git branch --contains` finds on no branch. It is the pre-amend commit and
  survives only as a dangling object until the next `git gc`. The real phase-3 commit is `66b6d7c`.
  Caused by using `--amend` to fold the SHA write-back into its own commit, which the implement
  skill's ritual forbids.
- **Fix**: Rewrite the four rows to `66b6d7c`.
- **Decision**: FIXED — rows 3.1-3.4 repointed to 66b6d7c, verified an ancestor of HEAD.
