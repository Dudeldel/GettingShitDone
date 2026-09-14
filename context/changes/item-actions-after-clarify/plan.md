# What an item can do after it has been clarified — Implementation Plan

## Overview

Today an item that leaves the Inbox is frozen: there is no way to move it and no way to
finish it unless you did the work inside a two-minute timer. This slice gives a clarified
item two actions — **refile** it into a different destination bucket, and **complete** it —
as two separate business verbs. `clarify` is not touched and stays strictly Inbox-only.

Roadmap S-11, with S-12 absorbed.

## Current State Analysis

- **The backend actively forbids this, and says so.** `ItemRepository::clarify` guards on
  `where bucket = inbox` **inside the same statement that writes**
  (`app/Infrastructure/Item/ItemRepository.php:44-59`), and a second attempt answers 409
  "That item has already been clarified." That guard is what closed the check-then-act race
  found in S-02's review; **loosening it is not an option**, which is why this slice adds
  verbs rather than relaxing one.
- **A trap is already documented, addressed to this slice.**
  `ItemRepository.php:60-66` carries the note added by S-03's review finding F9: `clarify`
  writes `completed_at => null` unconditionally, safe *only* because the Inbox guard means a
  completed item can never be re-clarified. Any new write path that reuses that statement
  erases completions silently.
- **A generic per-item verb is explicitly warned against.**
  `app/Domain/Item/ItemRepositoryInterface.php:44-52` records why `emptyTrash()` takes no
  item id: a "delete this row" verb becomes reachable from every bucket the moment it exists,
  which is "FR-010's re-filing semantics arriving by the back door".
- **One test encodes the boundary**: `frontend/src/items/BucketPage.test.tsx:95`, "never
  offers a Clarify action from a destination". It **stays true** under this plan — we add
  refile/complete, not clarify. Only its comment needs updating.
- **Four assertions pin the 409** (`tests/Feature/Item/ClarifyItemTest.php:114`,
  `frontend/src/items/InboxPage.test.tsx:212`, `ClarifyDialog.test.tsx:183`). All survive.
- **`delegation_done` has never done anything.** Verified: written in exactly one place
  (`ItemRepository.php:54`, always `false` or `null`), read nowhere but passed through to the
  DTO. No endpoint, no UI, no test asserting it ever changes.
- **Bucket views have no per-row actions at all** — `BucketPage` reuses `InboxList` *without*
  `onClarify` (`frontend/src/items/BucketPage.tsx:13`), deliberately.

## Desired End State

From any of the four action buckets a row carries a checkbox that marks the item done and
un-marks it, and a button that opens a destination picker and moves it. Completed items are
hidden by default behind a "show completed" toggle, and checking the box says where the item
went rather than letting it vanish. Trash is an ordinary bucket you can move things out of;
emptying it is still the only irreversible act in the product.

Verified by the six CI gates plus a browser walk of every affected state.

### Key Discoveries

- `GtdBucket` has 8 cases (`app/Domain/Item/GtdBucket.php:14-35`): Inbox plus seven
  destinations. Refile targets are the seven; completing is allowed in four.
- `ClarifyDialog`'s `QUICK_ROUTES` (`frontend/src/items/ClarifyDialog.tsx:35-43`) is already
  a seven-destination picker with Inbox deliberately absent — the shape to reuse.
- `listByBucket` orders `created_at desc, id desc` and a test pins that the client does not
  re-sort (`frontend/src/items/InboxList.tsx:31`).

## What We're NOT Doing

- **Not touching `clarify`.** Not its guard, not its 409, not its Inbox refusal. Refile is a
  separate verb with its own route, payload, validation and log event.
- **No `PATCH /items/{id}`.** A generic update verb is what `ItemRepositoryInterface:44-52`
  warns about and would re-open the "client chooses where an item lands" hole that both
  payload classes are shaped to close.
- **Inbox is not a refile destination.** An item in the Inbox is unclarified by definition;
  the way in is `clarify`. Consequence, accepted: a mis-clarified item can be corrected by
  moving it, but cannot be put back through the guided tree.
- **No tree re-run on refile** — direct destination pick only.
- **No completing outside the four action buckets.** Reference, Someday/Maybe and Trash are
  non-actionable by FR-004's own split; "done" there means nothing.
- **No per-item delete.** The Trash purge stays the only destructive path and keeps taking no
  item id.
- **No bulk actions, no undo history, no "completed" archive view.**

## Implementation Approach

Two verbs, each mirroring the shape `clarify` already proved: a Payload built from a
FormRequest, a Service method that derives nothing from the client, and a repository method
that **guards and writes in one statement** so the guard cannot be raced.

The completion rule and the destination rule are pure-domain predicates on `GtdBucket`
itself — they are facts about the buckets, not about a request, and putting them on the enum
keeps both the edge and the domain reading from one source.

## Critical Implementation Details

**The `completed_at` trap is the whole reason this slice is risky.** `clarify` writes
`completed_at => null` in the same statement as the bucket. **Refile must not reuse that
write** — moving a finished Next Action to Calendar has to carry its completion across.
Equally, **complete must not touch `bucket`**. Each verb writes exactly its own column plus
`updated_at`, and the tests must prove the other column survives — an assertion that only
checks the column it changed would pass against a write that clobbers the other.

**The "show completed" toggle reverses a shipped decision.** S-03 chose to keep completed
items visible because "vanishing is indistinguishable from being lost". Hiding them is now
the default, so the compensation is not optional: checking the box must leave a `role="status"`
message saying the item is done and where it went. Without it this slice re-introduces exactly
the confusion S-03 avoided.

---

## Phase 1: Two verbs on the backend

### Overview

`refile` and `complete` end to end: domain predicates, payloads, requests, repository writes,
service methods, routes, log events — plus retiring `delegation_done`.

### Changes Required

#### 1. The bucket predicates

**File**: `app/Domain/Item/GtdBucket.php`

**Intent**: the two rules this slice needs are facts about buckets, not about requests, so
they live on the enum where the edge and the domain can both read them.

**Contract**: `isDestination(): bool` — true for the seven non-Inbox cases, the legal refile
targets. `isActionBucket(): bool` — true for Next Actions, Projects, Calendar and Delegation,
the four where completing means something. Both used by the FormRequests *and* asserted in the
domain, so a loosened rule at the edge cannot smuggle a bad value through.

#### 2. Repository writes

**File**: `app/Infrastructure/Item/ItemRepository.php`, `app/Domain/Item/ItemRepositoryInterface.php`

**Intent**: add `refile` and `setCompleted`, each guarding and writing in one statement the way
`clarify` does, so the guard cannot be raced.

**Contract**: `refile(int $itemId, GtdBucket $destination): ItemDto` — guarded on
`where bucket <> 'inbox'`; writes `bucket` and `updated_at` and **nothing else**.
`setCompleted(int $itemId, bool $completed): ItemDto` — guarded on the item being in an action
bucket; writes `completed_at` (now() or null) and `updated_at` and **nothing else**. Both
disambiguate a zero-row result into the existing `ItemNotFoundException` vs a new
`ItemActionNotAllowedException` by one read on the failure path, mirroring `clarify`.

Neither may reuse `clarify`'s update array. That array sets `completed_at => null`, which is
the F9 trap (`ItemRepository.php:60-66`).

#### 3. Payloads, requests, controllers, routes

**Files**: `app/Dto/Payload/RefileItemPayload.php`, `app/Dto/Payload/CompleteItemPayload.php`,
`app/Http/Requests/RefileItemRequest.php`, `app/Http/Requests/CompleteItemRequest.php`,
`app/Http/Controllers/Api/V1/RefileController.php`,
`app/Http/Controllers/Api/V1/CompleteController.php`, `routes/api.php`

**Intent**: one business operation per controller, following the block map's reasoning for why
`ClarifyController` is separate from `ItemController`.

**Contract**: `POST /api/items/{itemId}/refile` takes `{ bucket }`, validated with
`Rule::enum(GtdBucket::class)` plus a rule rejecting any bucket where `isDestination()` is
false. `POST /api/items/{itemId}/complete` takes `{ completed: bool }` — a toggle, not two
routes. Both `whereNumber('itemId')`, both inside the existing `auth:sanctum` group.
Controllers stay ~3 lines. New domain exceptions render to 422 (rule violation) and 404, with
`Response::HTTP_*` constants.

#### 4. Service and log events

**Files**: `app/Services/ItemService.php`, `app/Logging/LogEvent.php`

**Intent**: orchestration only, and a durable trace for both operations — a refile changes
where an item lives, which is exactly the kind of fact worth being able to reconstruct.

**Contract**: `refile()` and `setCompleted()` on `ItemService`, each catching
`ItemPersistenceException` to emit a failure event before rethrowing, as `clarify` does.
`LogEvent::itemRefiled(int $itemId, GtdBucket $from, GtdBucket $to)` and
`LogEvent::itemCompletionChanged(int $itemId, bool $completed)`. No item text in any field.

#### 5. Retire `delegation_done`

**Files**: new migration, `app/Models/Item.php`, `app/Dto/ItemDto.php`,
`app/Infrastructure/Item/ItemRepository.php`, `frontend/src/api.ts`, `frontend/src/test/server.ts`

**Intent**: one concept of "done" for the whole product. The column is written once as `false`
and never changed — there is no endpoint, no UI and no test asserting it ever moves.

**Contract**: a migration dropping `delegation_done` with a reversible `down()`; the property,
cast, DTO field and TypeScript field removed; `clarify` stops writing it. FR-007's "done flag"
is now `completed_at`, which Delegation reaches through the same verb as every other action
bucket. `ClarifyItemTest`'s `assertJsonPath('delegationDone', false)` becomes an assertion that
a freshly delegated item has `completedAt: null`.

### Success Criteria

#### Automated Verification

- Migration applies and rolls back: `php artisan migrate` / `migrate:rollback`
- Backend tests pass: `php artisan test`
- Static analysis passes: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Format check passes: `./vendor/bin/pint --test`
- A refile carries an existing completion across, proven by reading the item back
- A complete leaves the bucket untouched, proven by reading the item back
- Refiling to the Inbox, and completing in a non-action bucket, are both refused

#### Manual Verification

- Drive both verbs against the running API and read each item back through a separate request

---

## Phase 2: Per-row actions in the bucket views

### Overview

The first actions this product has ever put on a list row, on the screens S-05 deliberately
left read-only.

### Changes Required

#### 1. The row controls

**File**: `frontend/src/items/InboxList.tsx`

**Intent**: a checkbox for a state and a button for an action, because that is what each one
is. The checkbox gives keyboard semantics and state announcement for free; a button would
need `aria-pressed` maintained by hand.

**Contract**: two new optional props alongside `onClarify`, so every existing caller is
unaffected: `onComplete?: (item: Item, next: boolean) => void` and
`onRefile?: (item: Item) => void`. The checkbox renders only when `onComplete` is passed, its
accessible name naming the item ("Mark 'ring the dentist' done"), and `checked` reflects
`completedAt !== null`. **The `✓ Done` text marker stays** — it is asserted by two tests and is
the non-visual signal. No new element may wrap the item title, `✓ Done`, `Waiting on:` or the
timestamp: three tests match those as direct text children.

#### 2. Destination picker

**File**: `frontend/src/items/RefileDialog.tsx` (new)

**Intent**: pick one of the seven destinations and move. Deliberately not the clarify wizard —
there is no tree here.

**Contract**: the shape `ClarifyDialog`'s `quickRoute` step already uses — one button per
destination, Inbox absent, the current bucket absent or disabled. Escape cancels; focus enters
the picker on open; an always-mounted `role="alert"` for failures, rendering zero child nodes
when empty (the ph3 F6a rule, asserted by `toBeEmptyDOMElement()` elsewhere).

#### 3. Bucket page wiring and the completed toggle

**File**: `frontend/src/items/BucketPage.tsx`

**Intent**: wire both actions, and keep completed items out of the default view without
letting them vanish silently.

**Contract**: `onComplete` and `onRefile` passed only where they apply — `onComplete` only in
the four action buckets. A "Show completed" control filters rows with `completedAt !== null`
out of the default view; it appears only where completing is possible. **Checking the box
emits a `role="status"` message** naming what happened and that the item is now hidden — the
compensation for reversing S-03's visibility decision. Optimistic update with rollback on
failure, matching how clarify already removes a row and how the purge restores state on error.

### Success Criteria

#### Automated Verification

- Frontend tests pass: `cd frontend && npm run test`
- Build and types pass: `cd frontend && npm run build`
- Lint passes: `cd frontend && npm run lint`
- A completed item disappears from the default view and returns under "show completed"
- A failed refile leaves the row where it was and explains itself

#### Manual Verification

- Walk an action bucket: check a box, read the status message, toggle "show completed", uncheck
- Move an item out of Trash and confirm it lands in the chosen bucket
- Confirm no bucket offers Clarify, and that the Inbox is absent from every destination picker

---

## Phase 3: Close out

### Overview

Gates, a browser walk, and correcting the comments that currently tell the next reader FR-010
is parked.

### Changes Required

#### 1. Stale comments

**Files**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Exceptions/ItemNotInInboxException.php`,
`frontend/src/items/BucketPage.tsx`, `frontend/src/items/BucketPage.test.tsx`

**Intent**: four comments say re-filing is parked for v2. It is not any more, and a stale
comment is worse than none — the next reader would conclude the boundary still holds.

**Contract**: each updated to say refile is a separate verb and that `clarify` remains
Inbox-only. `BucketPage.test.tsx:95`'s assertion is unchanged — only its comment moves from
"FR-010, parked for v2" to "clarify stays Inbox-only; refiling has its own verb".

### Success Criteria

#### Automated Verification

- All six gates green
- No comment in `app/` or `frontend/src` still describes FR-010 as parked
- Deliberate-breakage pass on the new tests, in particular that refile preserves `completed_at`
  and complete preserves `bucket`

#### Manual Verification

- Full walk: complete and un-complete in each of the four action buckets, refile between all
  seven destinations including into and out of Trash, at 1280px and 400px

---

## Testing Strategy

### Unit tests

The two `GtdBucket` predicates over every case; the repository guards through the fake; the
service emitting each log event and not emitting the other.

### Integration tests

Both endpoints end to end, each transition read back through a **separate request** — the
durability oracle this project has used since S-01. Specifically: refile preserves
`completed_at`, complete preserves `bucket`, refile to Inbox is 422, complete in a
non-action bucket is 422, both are 404 for an unknown id, both are 401 unauthenticated.

### Manual testing steps

Per phase above. The states are the eight bucket views plus the picker and the completed
toggle.

## Migration Notes

Dropping `delegation_done` is the only schema change. It is reversible, and no data is lost
that anything reads: the column holds `false` or `null` and has never had a write path.

## References

- Roadmap item: `context/foundation/roadmap.md` S-11 (S-12 absorbed)
- The trap this slice must not fall into: `context/archive/2026-09-14-two-minute-rule-timer/reviews/impl-review.md` F9
- Why `emptyTrash` takes no id: `app/Domain/Item/ItemRepositoryInterface.php:44-52`
- The picker shape to reuse: `frontend/src/items/ClarifyDialog.tsx:35-43`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.
> Do not rename step titles.

### Phase 1: Two verbs on the backend

#### Automated

- [x] 1.1 Migration applies and rolls back — 71b8372
- [x] 1.2 Backend tests pass — 71b8372
- [x] 1.3 Static analysis passes — 71b8372
- [x] 1.4 Format check passes — 71b8372
- [x] 1.5 A refile carries an existing completion across, read back separately — 71b8372
- [x] 1.6 A complete leaves the bucket untouched, read back separately — 71b8372
- [x] 1.7 Refiling to the Inbox and completing in a non-action bucket are both refused — 71b8372

#### Manual

- [x] 1.8 Drive both verbs against the running API and read each item back — 71b8372

### Phase 2: Per-row actions in the bucket views

#### Automated

- [x] 2.1 Frontend tests pass — 1c12558
- [x] 2.2 Build and types pass — 1c12558
- [x] 2.3 Lint passes — 1c12558
- [x] 2.4 A completed item leaves the default view and returns under "show completed" — 1c12558
- [x] 2.5 A failed refile leaves the row in place and explains itself — 1c12558

#### Manual

- [x] 2.6 Walk an action bucket: check, read the status message, toggle, uncheck — 1c12558
- [x] 2.7 Move an item out of Trash into a chosen bucket — 1c12558
- [x] 2.8 No bucket offers Clarify; the Inbox is absent from every destination picker — 1c12558

### Phase 3: Close out

#### Automated

- [x] 3.1 All six gates green — 01710e3
- [x] 3.2 No comment still describes FR-010 as parked — 01710e3
- [x] 3.3 Deliberate-breakage pass, including refile-preserves-completion and complete-preserves-bucket — 01710e3

#### Manual

- [x] 3.4 Full walk across the four action buckets and all seven destinations, at 1280px and 400px — 01710e3
