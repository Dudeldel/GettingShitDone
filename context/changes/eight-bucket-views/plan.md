# Eight bucket views, and emptying the Trash — Implementation Plan

## Overview

Roadmap slice **S-05**. Navigation across all eight GTD buckets (FR-009), plus the product's
first and only destructive write: emptying the Trash. Until now clarify routed items to
destinations the user could not see; this slice closes that loop.

## Current State Analysis

- **The read side already exists.** `GET /api/items?bucket=<value>` has accepted all eight
  `GtdBucket` values since S-01. `ListItemsRequest` validates with `Rule::enum` and falls back
  to `GtdBucket::default()`. No new read endpoint is needed.
- **Nothing in the product ever removes a row.** As of S-02, routing to Trash is a clarify
  outcome, not a removal. `ItemRepositoryInterface` has `create`, `listByBucket` and `clarify`.
- **`InboxList` already takes an optional `onClarify`** and was built so bucket views can omit
  it — you do not re-clarify from a destination (FR-010, parked).
- **Delegation items carry `delegatedTo`/`delegationDone`** from S-02 with no UI anywhere.
- **`listByBucket` is unbounded** — deferred per PRD Open Question #2. Reference and Trash are
  append-only in GTD, so they are where that bites first.
- **The router exists** (`react-router-dom` 7, `AppRoutes`, `ProtectedRoute`) with one route.

## Desired End State

The user navigates to any of the eight buckets by URL, sees its items, and can permanently
empty the Trash from the Trash view — and from nowhere else.

Verify: `php artisan test` green including a purge test proving the rows are gone and other
buckets untouched; `cd frontend && npm test` green including one test per bucket route and the
purge confirmation flow.

### Key Discoveries:

- **The purge must be reachable only from the Trash view.** The roadmap's Risk note is explicit:
  a generic "delete item" verb hanging off every bucket would quietly ship FR-010's re-filing
  semantics, which are parked. Chosen shape: `DELETE /api/trash` — the resource is the Trash
  itself, so the URL structurally cannot address a single item.
- **Whole-Trash only, no per-item discard** (decided). One destructive path is easier to guard
  and to test than N, and it keeps the endpoint bucket-scoped rather than item-scoped.
- **Routes, not tabs** (decided). Each bucket gets `/bucket/:bucket`, so refresh and bookmarking
  work, tests can enter a view directly through `MemoryRouter`, and "only from the Trash view"
  is a fact about the route tree rather than a render condition someone can loosen.
- **`context/foundation/lessons.md`**: a test is not coverage until deliberate breakage has
  reddened it. The S-02 review found 12 of 31 mutations surviving; that rule applies from the
  first test here, and most of all to the irreversible path.

## What We're NOT Doing

- **No per-item permanent delete.** Decided. There is no `DELETE /api/items/{id}` in this slice
  and must not be one — that is the generic verb the roadmap warns against.
- **No re-filing between buckets** (FR-010, v2). Bucket views are read-only apart from the purge.
- **No clarify action from a destination view** — `onClarify` stays omitted outside the Inbox.
- **No undo for the purge.** It is irreversible by design; the confirmation is the safeguard.
- **No pagination or limit**, per PRD Open Question #2 — recorded again now that seven more
  views reach the same unbounded query.
- **No `delegationDone` write path.** The Delegation view renders the who/what note; marking a
  waiting-for as done has no FR and belongs to a later slice.
- **No bucket counts or summary endpoint** — that would need eight queries or a new aggregate.

## Implementation Approach

Backend first and small: one repository method, one service method, one controller, one route,
one domain event. Then the frontend: a bucket route, a shared view, navigation, and the purge
with its confirmation.

## Critical Implementation Details

**The purge deletes by bucket, never by id.** `Item::query()->where('bucket', Trash)->delete()`
is one statement and is safe to repeat. Any signature that accepts an item id — even
internally — reintroduces the generic delete this slice is shaped to avoid, so the repository
method takes no arguments at all.

---

## Phase 1: Emptying the Trash

### Overview

The one destructive write, end to end.

### Changes Required:

#### 1. Repository and interface

**File**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Infrastructure/Item/ItemRepository.php`

**Intent**: Delete every item in the Trash and report how many went.

**Contract**: `emptyTrash(): int` — no parameters, so it cannot be aimed at one row. Returns the
number of deleted rows. Confine `QueryException` to `ItemPersistenceException` as `create` and
`clarify` already do.

#### 2. Service and domain event

**File**: `app/Services/ItemService.php`, `app/Logging/LogEvent.php`

**Intent**: Orchestrate the purge and record it — the only irreversible operation in the
product deserves a durable trace.

**Contract**: `ItemService::emptyTrash(): int`. `LogEvent::trashEmptied(int $count)` emits
`trash.emptied.success` (category `database`, outcome `success`) at info level with the count
only — never titles. A failure counterpart mirrors `itemCaptureFailed`.

#### 3. Endpoint

**File**: `app/Http/Controllers/Api/V1/TrashController.php`, `routes/api.php`

**Intent**: Expose the purge as a bucket-scoped resource.

**Contract**: `DELETE /api/trash` inside the existing
`['auth:sanctum', 'throttle:api', LogContextMiddleware]` group. Returns
`Response::HTTP_OK` with `{"deleted": <int>}`. Scramble summary PHPDoc, no `@return` generic.
No route parameter anywhere.

#### 4. Feature tests

**File**: `tests/Feature/Item/EmptyTrashTest.php`

**Intent**: Prove the rows are gone, that nothing else is, and that the endpoint is guarded.

**Contract**: Seed items across several buckets including Trash; `DELETE /api/trash` returns the
count; a separate `GET /api/items?bucket=trash` is empty **and** the other buckets still hold
their items. Plus: an empty Trash returns 0 and succeeds (idempotent); unauthenticated returns
401; the domain event fires with the count and no item text.

### Success Criteria:

#### Automated Verification:

- Full backend quality gate passes: `php composer.phar quality`
- Scramble export succeeds: `php artisan scramble:export`
- Purge test proven able to fail: make `emptyTrash` delete nothing, confirm red, revert
- Blast-radius test proven able to fail: make it delete every bucket, confirm red, revert

#### Manual Verification:

- No route or repository signature anywhere accepts an item id for deletion

---

## Phase 2: The eight bucket views

### Overview

Navigation, a shared list view, and the purge behind a confirmation.

### Changes Required:

#### 1. Bucket metadata

**File**: `frontend/src/items/buckets.ts`

**Intent**: One source for the eight buckets' labels and order, so navigation and the view
cannot disagree about what exists.

**Contract**: An ordered list of `{ value: GtdBucket, label: string }` covering all eight,
typed so that omitting one is a compile error. Inbox first, matching the capture-to-clarify flow.

#### 2. Bucket route and view

**File**: `frontend/src/items/BucketPage.tsx`, `frontend/src/AppRoutes.tsx`

**Intent**: One screen that renders any bucket, reached by URL.

**Contract**: Route `/bucket/:bucket` inside `ProtectedRoute`. An unknown or missing `:bucket`
redirects to the Inbox rather than rendering an empty list — a typo in the URL must not look
like an empty bucket. Reuses `InboxList` **without** `onClarify` (no clarify from a
destination). Load errors go through the shared `messageFor`. Delegation rows show the
`delegatedTo` note.

#### 3. Navigation

**File**: `frontend/src/items/BucketNav.tsx`, used by `BucketPage` and `InboxPage`

**Intent**: Reach every bucket from every bucket — the eight lists are the "GTD out-of-the-box"
promise, so none may be unreachable.

**Contract**: Links to all eight, marking the current one (`aria-current="page"`). Rendered on
both the Inbox screen and the bucket screen.

#### 4. The purge, with confirmation

**File**: `frontend/src/items/BucketPage.tsx`, `frontend/src/api.ts`

**Intent**: Let the user empty the Trash, and make the irreversibility explicit before they do.

**Contract**: `emptyTrash(): Promise<{ deleted: number }>` in the client. The control renders
**only** when the current bucket is Trash, and only when it holds items. It asks for explicit
confirmation naming the count and saying it cannot be undone; on success the list empties.
Failures go through `messageFor` and leave the list untouched.

#### 5. Component tests

**File**: `frontend/src/items/BucketPage.test.tsx`

**Intent**: Pin the navigation, the read-only-ness of destinations, and the purge guard.

**Contract**: Each bucket route renders its items; an unknown bucket redirects to the Inbox;
**no bucket view offers a Clarify action**; the empty-Trash control is absent on all seven other
buckets and present on Trash; confirming purges and empties the list; declining does nothing;
a failed purge leaves the items and shows an actionable message. Every test verified by
deliberate breakage.

### Success Criteria:

#### Automated Verification:

- Frontend tests pass: `cd frontend && npm test`
- Frontend build and lint pass: `cd frontend && npm run build && npm run lint`
- Backend suite still green: `php artisan test`
- Purge-visibility test proven able to fail: render the control on every bucket, confirm red, revert

#### Manual Verification:

- Walk all eight buckets from the UI; clarified items appear where they were routed
- Empty the Trash and confirm the other seven buckets are untouched

---

## Testing Strategy

### Unit Tests:

- None new — the purge has no branching logic worth isolating; its risk is blast radius, which
  only a real query can demonstrate.

### Integration Tests:

- `DELETE /api/trash` removes Trash rows, leaves the other buckets intact, is idempotent on an
  empty Trash, requires auth, and emits `trash.emptied.success` with a count and no item text.

### Manual Testing Steps:

1. Capture and clarify items into several buckets; visit each of the eight views
2. Confirm no bucket view offers Clarify
3. Empty a non-empty Trash and confirm the count, the emptied list, and the other buckets
4. Reload `/bucket/trash` directly and confirm the view loads from the URL alone

## Performance Considerations

Seven more views now reach the same unbounded `listByBucket`. PRD Open Question #2 defers
pagination; Reference and Trash are the append-only buckets where that will bite first.

## Migration Notes

No schema change. The purge is irreversible by design and has no undo.

## References

- Change identity: `context/changes/eight-bucket-views/change.md`
- Item-layer grounding: `context/archive/2026-09-14-testing-capture-durability/research.md`
- Clarify slice: `context/archive/2026-09-14-guided-clarify-routing/`
- Conventions: `app/CLAUDE.md`, `tests/CLAUDE.md`, `context/foundation/test-plan.md` §6.3
- Accepted rules: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Emptying the Trash

#### Automated

- [x] 1.1 Full backend quality gate passes — c02f075
- [x] 1.2 Scramble export succeeds — c02f075
- [x] 1.3 Purge test proven able to fail by deliberate breakage — c02f075
- [x] 1.4 Blast-radius test proven able to fail by deliberate breakage — c02f075

#### Manual

- [x] 1.5 No route or repository signature accepts an item id for deletion — c02f075

### Phase 2: The eight bucket views

#### Automated

- [x] 2.1 Frontend tests pass
- [x] 2.2 Frontend build and lint pass
- [x] 2.3 Backend suite still green
- [x] 2.4 Purge-visibility test proven able to fail by deliberate breakage

#### Manual

- [x] 2.5 All eight buckets reachable; clarified items appear where routed
- [x] 2.6 Emptying the Trash leaves the other seven buckets untouched
