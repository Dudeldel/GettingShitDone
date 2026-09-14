# Item Attributes & the Derived Calendar View — Implementation Plan

## Overview

Build the first write path for the five dormant item columns (`due_date`, `tags`, `context`,
`important`, `urgent`) and make a dated item appear in the Calendar/Dates view — without ever
rewriting the item's `bucket`. This is S-06 (FR-011 + FR-013, with S-07 absorbed), and it is the
product's first edit-an-item surface.

## Current State Analysis

The five columns exist, are cast, and are deliberately excluded from `#[Fillable]`
(`app/Models/Item.php:36`). The **read half is already finished**: `ItemDto` carries all five
(`app/Dto/ItemDto.php:26-40`), round-trips them with real values
(`tests/Unit/Item/ItemDtoTest.php:50-68`), the repository's `toDto()` reads them
(`app/Infrastructure/Item/ItemRepository.php:220-242`), and the SPA's `Item` interface declares
them (`frontend/src/api.ts:187-208`). Nothing writes them, and nothing renders them.

Calendar/Dates is **not** an empty bucket. It is already a live membership destination: a
labelled quick-route button (`frontend/src/items/ClarifyDialog.tsx:38`), a legal refile target,
and an action bucket whose items can be completed (`app/Domain/Item/GtdBucket.php:58-64`), pinned
by `tests/Feature/Item/RefileItemTest.php:47-51` and `tests/Feature/Item/CompleteItemTest.php:59`.
So this slice adds *dates* to a bucket that already means something, rather than filling an empty
one.

Every read in the repository is `where bucket = ?` (`app/Infrastructure/Item/ItemRepository.php:177-186`).
This slice introduces the codebase's **first derived query**, which S-08 (Eisenhower) and S-09
(weekly review) will follow.

## Desired End State

A user can open any item outside the Trash, set a due date, tags, a context and the
important/urgent flags, and save. The item keeps its bucket. If it carries a date and is an
actionable commitment — or was filed to Calendar by hand — it appears in the Calendar/Dates view,
dated items first in chronological order, undated hand-filed items below them.

Verify by: setting a date on a Next Actions item, seeing it appear in Calendar while *also*
remaining in Next Actions, and confirming its stored `bucket` is still `next_actions`.

### Key Discoveries

- Writes bypass `#[Fillable]` via query-builder `update()`, not `forceFill` — guard and write in
  one statement (`app/Infrastructure/Item/ItemRepository.php:38-85`). `#[Fillable]` never needs
  loosening.
- `Mysql::ATTR_FOUND_ROWS => true` is already set (`config/database.php:70,97`), so
  `$affected === 0` means the same on MySQL as on SQLite. A new verb inherits this — do not
  introduce a read-then-write path that sidesteps it.
- **`ClarifyConst::DATE_FORMAT` does not exist.** It appears only as an example in
  `app/CLAUDE.md:220,230,240,269`; `context/foundation/roadmap.md:233` wrongly calls it "already
  present". This slice creates it.
- `app/Validators/` does not exist — this slice may be its first inhabitant.
- **The `tags` cast has no shape guarantee.** S-01's review (F6,
  `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-1.md:131-145`) left this
  open and named S-06/S-07 as "the first code to discover this".
- `.field` already styles `[aria-invalid='true']` (`frontend/src/index.css`), so the design pass
  anticipated field-level errors even though no form uses them yet.
- FR-008's "two buckets at once" clause was already ruled to constrain the *column*, not the
  view: `context/archive/2026-09-14-guided-clarify-routing/plan.md:46-48`. A derived Calendar view
  does not violate it.
- **Correction to `research.md`:** that document states
  `tests/Feature/Item/CaptureItemTest.php:127-146` "will need deliberate revision". It will not.
  That test asserts *capture* ignores client-supplied metadata, which stays true — capture's
  FormRequest never accepts these fields and `#[Fillable]` is unchanged. The test stays green and
  keeps guarding the right thing; the real work is **extending** it to the three fields it does
  not currently cover (`tags`, `context`, `urgent`).

## What We're NOT Doing

- **No generic `PATCH /items/{id}`.** The recorded hole is "client chooses where an item lands"
  (`routes/api.php:28-32`); the new verb structurally cannot touch `bucket`.
- **No change to `#[Fillable]`.** The dormant columns stay unreachable from any request array.
- **No re-routing on date.** Setting a date never changes `bucket`. A dated Project stays a
  Project.
- **No removal of Calendar as a quick-route or refile destination.** Shipped behaviour and its
  tests stay exactly as they are.
- **No Eisenhower view** (S-08) — this slice only supplies the `important`/`urgent` inputs.
- **No pagination** — `listByBucket` stays unbounded, per PRD Open Question #2.
- **No tag vocabulary management** — no tag CRUD, no tag list endpoint, no rename/merge.
- **No retrofit of field-level errors** onto the existing capture/clarify/refile forms.
- **No date-grid / month-view calendar UI.** Calendar stays a list, ordered by date.
- **No reminders or notifications** — an explicit PRD Non-Goal.

## Implementation Approach

Backend first, in two independently verifiable halves: the write path (Phase 1), then the derived
read (Phase 2). The HTTP listing contract (`GET /api/items?bucket=calendar`) is deliberately left
unchanged, so the Calendar view lands entirely behind the existing endpoint and the frontend needs
no API change to benefit from it.

Frontend then splits read before write (Phases 3 and 4): rendering the attributes first gives
Phase 3 its own manual verification against data seeded through the Phase 1 API, instead of an
unverifiable "you can edit but nothing displays" state.

## Critical Implementation Details

**NULL ordering must be explicit and engine-agnostic.** Both SQLite and MySQL sort NULL as the
lowest value in `ASC`, so a plain `orderBy('due_date')` puts *undated* items above every dated
commitment — the opposite of what the Calendar view needs. The ordering must lead with a computed
null-rank column. Tests run on SQLite and production is MySQL
(`phpunit.xml:26`, `config/database.php:20`), so the expression has to behave identically on both;
`due_date IS NULL` evaluates to 0/1 on each, which is why it is used rather than a
`NULLS LAST` clause (SQLite supports that syntax only from 3.30, MySQL not at all).

**Attribute writes must not disturb sibling state.** S-11's F2 finding was a write that silently
clobbered a sibling column. The attributes update writes exactly the five columns plus
`updated_at`, and must never appear in the same statement as `bucket`, `completed_at`, `title`,
`note` or `delegated_to`.

## Phase 1: The attributes write path

### Overview

`POST /items/{itemId}/attributes` writes all five columns as one full replacement, guarded to
everything except the Trash, through the established five-layer stack.

### Changes Required:

#### 1. Domain constants

**File**: `app/Const/ItemConst.php`

**Intent**: Bind the new validation limits to a single source, as the existing three constants
already are, so schema and validation cannot drift.

**Contract**: Add `DATE_FORMAT = 'Y-m-d'` (the format the `date` cast and `toDateString()` already
produce de facto), `CONTEXT_MAX_LENGTH = 64` (mirroring the migration's `string('context', 64)`),
`TAGS_MAX_COUNT`, and `TAG_MAX_LENGTH`. Each carries a PHPDoc "why", matching the file's existing
style.

> `DATE_FORMAT` is placed on `ItemConst`, not `ClarifyConst`, because `due_date` is an item
> attribute and has nothing to do with the clarify workflow — despite `app/CLAUDE.md`'s example
> showing it on `ClarifyConst`. Update that example and the stale `roadmap.md:233` claim as part
> of this phase.

#### 2. Tag normalization

**File**: `app/Filters/TagListNormalizer.php`

**Intent**: Close S-01's F6 at the source so the column can only ever hold a packed list of
strings, making `ItemDto`'s declared `list<string>|null` true rather than aspirational.

**Contract**: `public static function normalize(array $tags): array` — trims each entry, drops
empties, de-duplicates case-insensitively while preserving the first-seen casing, and returns a
re-packed (`array_values`) list. Lives in `Filters/` rather than `Validators/` because it is a
technical transformation, not a domain rule (per `app/CLAUDE.md`'s Filters-vs-Validators split).
Applies `FreeTextSanitizer::sanitize` per element.

#### 3. Request validation

**File**: `app/Http/Requests/UpdateItemAttributesRequest.php`

**Intent**: Validate and sanitize the five fields, with all five always present (full-replacement
semantics) so `null` unambiguously means "clear".

**Contract**: `rules()` returns arrays, never pipe-strings. `dueDate` is
`['present', 'nullable', 'date_format:' . ItemConst::DATE_FORMAT]` — **no min/max**: a past due
date is a legitimate overdue state in GTD. `tags` is `['present','nullable','array','max:'.TAGS_MAX_COUNT]`
with `tags.*` as `['string','max:'.TAG_MAX_LENGTH]`. `context` is
`['present','nullable','string','max:'.CONTEXT_MAX_LENGTH]`. `important` and `urgent` are
`['present','nullable','boolean']` — two independent nullable booleans, because S-08 derives four
quadrants from them and a collapsed single scale would make that underivable; `null` means "not
yet judged", which is distinct from "judged not important".

`prepareForValidation()` sanitizes `context` via `FreeTextSanitizer` and normalizes `tags` via
`TagListNormalizer`, each behind an `is_string()` / `is_array()` guard so a payload like
`{"context": []}` falls through to the `string` rule (422) rather than raising a `TypeError` (500).
Each field carries a Scramble description comment.

#### 4. Payload

**File**: `app/Dto/Payload/ItemAttributesPayload.php`

**Intent**: Carry the complete new attribute state as a command into the service.

**Contract**: Private constructor + `fromArray()`, matching the other payloads. Five readonly
properties, all nullable. No `toArray()`, never returned from a service.

#### 5. Domain guard

**File**: `app/Exceptions/ItemActionNotAllowedException.php`

**Intent**: Name the one illegal case — editing attributes on an item in the Trash.

**Contract**: A new named static factory `editAttributesInTrash()` with a hardcoded message, per
the class's existing private-constructor convention (messages must be safe to echo to the client).
Maps to 422 through the existing `bootstrap/app.php:69-73` entry — no new mapping needed.

#### 6. Service

**File**: `app/Services/ItemService.php`

**Intent**: Orchestrate the write and emit the domain events.

**Contract**: `updateAttributes(int $itemId, ItemAttributesPayload $payload): ItemDto`. Follows the
established `try { repo } catch (ItemPersistenceException) { LogEvent::…Failed(); throw; }` shape
with a success event after. No `Illuminate\Http` import.

#### 7. Repository

**Files**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Infrastructure/Item/ItemRepository.php`

**Intent**: Persist the five columns in one guarded statement that cannot reach any other column.

**Contract**: `updateAttributes(int $itemId, ItemAttributesPayload $payload): ItemDto`, documented
`@throws ItemNotFoundException|ItemActionNotAllowedException|ItemPersistenceException`. The
implementation guards with `where('bucket', '!=', GtdBucket::Trash->value)` and writes exactly
`due_date, tags, context, important, urgent, updated_at`. On `$affected === 0`, re-check existence
to disambiguate 404 from 422, then `readBack($itemId)` as the other verbs do.

#### 8. Controller and route

**Files**: `app/Http/Controllers/Api/V1/ItemAttributesController.php`, `routes/api.php`

**Intent**: Map HTTP to the service in ~3 lines and register the verb alongside the others.

**Contract**: `store(UpdateItemAttributesRequest $request, int $itemId): JsonResponse` returning
`Response::HTTP_OK`. Route `POST /items/{itemId}/attributes` with `->whereNumber('itemId')`, inside
the existing auth group, with a comment explaining why it is a named verb and not a PATCH.

#### 9. Domain event logging

**File**: `app/Logging/LogEvent.php`

**Intent**: Record the write under the established naming.

**Contract**: `itemAttributesUpdated(int $itemId)` → `item.attributes.success`, and
`itemAttributesUpdateFailed(int $itemId, string $reason)` → `item.attributes.failure` at `error`
level. **Never log the attribute values** — tags and context are free user text; failures carry
SQLSTATE only, per every other method in the file.

#### 10. Documentation corrections

**Files**: `app/CLAUDE.md`, `context/foundation/roadmap.md`

**Intent**: Stop pointing future implementers at a constant that lives somewhere else.

**Contract**: Update `app/CLAUDE.md`'s four `ClarifyConst::DATE_FORMAT` examples to
`ItemConst::DATE_FORMAT`, and correct `roadmap.md:233`'s "already present" claim.

### Success Criteria:

#### Automated Verification:

- Pest suite passes: `php composer.phar test`
- Larastan clean at level 6: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint clean: `./vendor/bin/pint --test`
- New feature tests cover: successful write of all five fields; clearing a field with `null`; 422
  on an item in the Trash; 404 on a missing item; 401 unauthenticated; 422 on each invalid field
  shape (bad date format, over-long context, too many tags, non-array tags, non-boolean flag)
- New unit tests cover: `TagListNormalizer` (trim, drop-empty, case-insensitive dedupe preserving
  first casing, re-pack); `ItemAttributesPayload::fromArray`; `ItemService::updateAttributes`
  against the fake repository, including the failure-event path
- `tests/Feature/Item/CaptureItemTest.php`'s client-injection test is **extended** to cover `tags`,
  `context` and `urgent` alongside the existing `important` and `dueDate`, and still passes
- **Deliberate breakage pass** (per `context/foundation/lessons.md`): loosening `#[Fillable]` to
  include the five columns must redden the extended capture test; removing the Trash guard must
  redden the 422 test; dropping the tag dedupe must redden the normalizer test.

  > Note added during implementation: the first of those three was wrong as written. Loosening
  > `#[Fillable]` alone leaves the capture test green, because `ItemRepository::create()` builds
  > an explicit three-key array from a payload carrying no metadata — `#[Fillable]` is a second
  > lock behind one that is already closed. The test reddens once request data actually reaches
  > `create()`, which is the regression that change would really cause, and that is what was
  > verified instead. Also added: a breakage proving the bucket-invariant test can fail. Confirm each goes
  red, then revert.

#### Manual Verification:

- `POST /items/{id}/attributes` with all five fields set returns the item with them populated, and
  `GET /api/items?bucket=<its bucket>` shows the item still in its original bucket
- Sending `null` for every field clears them all
- A generated Scramble export documents the new endpoint with the five fields and their
  descriptions

**Implementation Note**: After completing this phase and all automated verification passes, pause
for manual confirmation before proceeding.

---

## Phase 2: The derived Calendar view

### Overview

`GET /api/items?bucket=calendar` stops being a plain `where bucket = 'calendar'` and becomes the
scoped union, ordered by date. No HTTP contract change.

### Changes Required:

#### 1. Index

**File**: `database/migrations/<timestamp>_add_due_date_index_to_items_table.php`

**Intent**: Support the `due_date IS NOT NULL` branch and any future date-range query.

**Contract**: A plain index on `due_date`. The migration comment must be honest in the style of the
existing one: it serves the filter, and with an `OR` predicate the optimizer will most likely still
filesort the ordering at this scale — it is not claimed to remove the sort.

#### 2. The union query

**Files**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Infrastructure/Item/ItemRepository.php`

**Intent**: Return everything the Calendar view shows — items filed there by hand, plus dated
actionable commitments from other buckets — in date order.

**Contract**: `listCalendar(): Collection` with `@return Collection<int, ItemDto>`. The action-bucket
set is **derived** from `GtdBucket::cases()` filtered by `isActionBucket()`, not hardcoded — the
same discipline `RefileItemRequest` uses so edge and domain cannot drift. Completed items are **not**
excluded, matching every other bucket listing; the SPA's existing "Show completed" toggle hides them.

The predicate and ordering are the non-obvious part and are the contract other phases depend on:

```php
// Filed to Calendar on purpose, OR an actionable commitment that carries a date.
->where(function (Builder $query) use ($actionBuckets): void {
    $query->where('bucket', GtdBucket::Calendar->value)
        ->orWhere(function (Builder $dated) use ($actionBuckets): void {
            $dated->whereNotNull('due_date')->whereIn('bucket', $actionBuckets);
        });
})
// `due_date IS NULL` yields 0/1 on both SQLite and MySQL, so ASC puts dated rows first.
// A plain orderBy('due_date') would do the opposite: both engines sort NULL lowest.
->orderByRaw('due_date IS NULL')
->orderBy('due_date')
->orderByDesc('created_at')
->orderByDesc('id')
```

#### 3. Service dispatch

**File**: `app/Services/ItemService.php`

**Intent**: Route the Calendar bucket to the derived query while every other bucket keeps its
existing path, so the controller and the HTTP contract stay untouched.

**Contract**: `listByBucket()` stops being a bare pass-through and dispatches
`GtdBucket::Calendar` to `listCalendar()`, everything else to `listByBucket()`. A comment states
why this branch lives in the service rather than the controller (it is a domain rule about what
Calendar *means*, not an HTTP concern).

### Success Criteria:

#### Automated Verification:

- Pest suite passes: `php composer.phar test`
- Larastan clean at level 6; Pint clean
- Migration applies and rolls back: `php artisan migrate` / `php artisan migrate:rollback`
- New feature tests cover: a dated Next Action appears in Calendar **and** still appears in
  `?bucket=next_actions`, with its stored bucket unchanged; an undated Calendar-filed item still
  appears; a dated Reference item does **not** appear; a dated Trash item does **not** appear; a
  completed dated item **does** appear (consistent with other buckets); ordering puts dated items
  ascending by date with undated items last; clearing a date removes a Next Action from Calendar but
  leaves a Calendar-filed item in place
- **Deliberate breakage pass**: replacing `orderByRaw('due_date IS NULL')` with nothing must redden
  the ordering test (this is the assertion most likely to pass for the wrong reason — it must
  distinguish dated-first from undated-first, not merely "some order"); removing the
  `whereIn($actionBuckets)` clause must redden the Reference-exclusion test

#### Manual Verification:

- Set a date on a Next Actions item and confirm it appears in the Calendar view while remaining in
  Next Actions
- Confirm an item hand-filed to Calendar with no date still appears, below the dated ones

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 3: Rendering attributes and the Calendar view

### Overview

The item row shows the five attributes; the Calendar view explains why each item is there.

### Changes Required:

#### 1. Date formatting

**File**: `frontend/src/items/InboxList.tsx`

**Intent**: Render a due date as a date, not a timestamp, reusing the existing helper's shape.

**Contract**: A `dueOn(iso: string): string` sibling to the existing `capturedAt`, using
`toLocaleDateString()` and carrying the same `Number.isNaN` fallback to the raw string.

#### 2. Attribute rendering on the row

**File**: `frontend/src/items/InboxList.tsx`

**Intent**: Surface the five attributes without crowding the row or disturbing the existing
title / note / delegatedTo / timestamp layout.

**Contract**: Conditional rendering — each attribute appears only when non-null. The due date is a
`<time dateTime={item.dueDate}>`. Tags and context render as small labelled text; the
important/urgent flags render as short textual markers, **not colour alone** (the app is
light-only with an accent reserved for primary actions per `frontend/src/index.css`, and colour
alone would not survive the accessibility bar the existing components hold). An overdue date —
earlier than today — is marked textually as well as visually, for the same reason.

#### 3. Calendar view presentation

**File**: `frontend/src/items/BucketPage.tsx`

**Intent**: Make the Calendar view legible given that most of its rows are not *in* the Calendar
bucket — otherwise it reads as a bug.

**Contract**: When `bucket === 'calendar'`, the page shows each item's home bucket alongside it
(via the existing `bucketLabel`), and the empty-state message names the rule ("items you file here,
plus anything actionable with a date"). No new component — a variant of the existing page.

### Success Criteria:

#### Automated Verification:

- `npm run build` (includes `tsc -b`) and `npm run lint` pass
- Vitest passes: `npm run test`
- `InboxList.test.tsx` extended: each attribute renders when present and is absent when null; the
  due date renders as a date; an overdue date carries its marker
- New `BucketPage` cases: the Calendar view shows home-bucket labels; the Calendar empty state
  differs from other buckets'
- `frontend/src/test/server.ts`'s `makeItem` gains realistic non-null values in the fixtures that
  need them
- **Deliberate breakage pass**: deleting the tag rendering must redden the tag test; returning the
  raw ISO string from `dueOn` must redden the date-format test

#### Manual Verification:

- An item seeded through the Phase 1 API displays all five attributes correctly in its bucket
- The Calendar view is legible at ~400px width with no horizontal scroll
- An overdue item is distinguishable without relying on colour alone

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 4: The edit panel

### Overview

An inline panel modelled on `RefileDialog` edits all five attributes, with errors shown per field.

### Changes Required:

#### 1. API client

**File**: `frontend/src/api.ts`

**Intent**: Add the named mutation, following the file's one-function-per-verb pattern.

**Contract**: An `ItemAttributes` type (the five nullable fields) and
`updateItemAttributes(id: number, attributes: ItemAttributes): Promise<Item>` posting to
`/api/items/${id}/attributes`. All five fields always sent — full replacement.

#### 2. Field-level errors

**File**: `frontend/src/api.ts`, `frontend/src/apiMessage.ts`

**Intent**: Make Laravel's `errors` object reachable by the UI, which nothing does today.

**Contract**: `ApiError` gains a readonly `errors: Record<string, string[]> | null`, populated from
the 422 body when present and `null` otherwise. `messageFor` is unchanged, so the three existing
forms keep behaving exactly as they do — this is additive.

#### 3. The edit panel

**File**: `frontend/src/items/AttributesDialog.tsx`

**Intent**: Give the user one form that saves all five attributes at once.

**Contract**: Modelled on `RefileDialog.tsx` — a `role="dialog"` `<section>` rendered inline (no
portal, no backdrop, `aria-modal` deliberately omitted for the same reason stated at
`ClarifyDialog.tsx:179-183`), Escape to cancel, focus moved to the heading on open. Inputs: a
native `<input type="date">`, a text input for tags (comma-separated, normalized server-side), a
text input for context, and two tri-state controls for important/urgent that can express
**null / true / false** — "not yet judged" must remain reachable, since a plain checkbox cannot
express it and S-08 depends on the distinction. Field errors render beneath their input with
`aria-invalid` set, using the `.field` styling that already supports it. A submit failure without a
field-level error falls back to the single `role="alert"` region.

#### 4. Wiring

**Files**: `frontend/src/items/InboxList.tsx`, `frontend/src/items/BucketPage.tsx`,
`frontend/src/items/InboxPage.tsx`

**Intent**: Open the panel from a row and splice the result back into local state.

**Contract**: An optional `onEdit?: (item: Item) => void` prop adds an "Edit" button to the row,
passed by both pages (all buckets except Trash — the button is **absent** rather than
present-and-doomed-to-422, per S-11's F7 finding). The parent holds the open-panel state, stashes
`document.activeElement` before opening and restores it on close, and replaces the item in `items`
with the server's returned object — no refetch, matching every existing mutation.

### Success Criteria:

#### Automated Verification:

- `npm run build`, `npm run lint`, `npm run test` all pass
- New `AttributesDialog.test.tsx`: renders current values; submits all five; clearing a field sends
  `null`; a 422 with an `errors` object renders the message under the right field with
  `aria-invalid`; a 422 without one falls back to the alert region; Escape cancels without saving;
  the tri-state flag control can reach all three states
- `InboxList.test.tsx`: the Edit button is present for non-Trash buckets and absent in Trash
- `BucketPage.test.tsx`: saving splices the returned item into the list; focus returns to the
  triggering button on close
- **Deliberate breakage pass**: removing the `aria-invalid` assignment must redden the field-error
  test; making the dialog send only the changed field must redden the full-replacement test
  (this is the assertion most at risk of passing for the wrong reason — it must observe that
  *untouched* fields are transmitted, not merely that the request succeeded)

#### Manual Verification:

- Editing an item end to end: open, change all five, save, see the row update without a page reload
- Setting a date makes the item appear in Calendar immediately on navigating there
- Clearing the date removes it from Calendar
- Keyboard-only: the panel is reachable, Escape cancels, focus returns to the Edit button
- A deliberately invalid date shows an error under the date field, not a generic banner

---

## Testing Strategy

### Unit Tests

- `TagListNormalizer` — trimming, empty removal, case-insensitive dedupe preserving first-seen
  casing, re-packing to a list
- `ItemAttributesPayload::fromArray` — all five fields, including all-null
- `ItemService::updateAttributes` — success and failure-event paths against the fake repository

### Integration Tests

- The `/attributes` endpoint across success, each validation failure, the Trash guard, 404 and 401
- The Calendar union: membership rules per bucket, date-driven inclusion and exclusion, ordering
  including NULL placement, completed-item inclusion
- The invariant that matters most: a dated item's stored `bucket` is unchanged, asserted directly
  against the row rather than only through the API response

### Manual Testing Steps

1. Set a date on a Next Actions item; confirm it appears in Calendar **and** Next Actions
2. Confirm the stored bucket is unchanged (via the Next Actions view)
3. Hand-file an item to Calendar with no date; confirm it sorts below the dated ones
4. Complete a dated item; confirm it vanishes from Calendar under the default "Show completed" off
5. Clear a date; confirm the item leaves Calendar
6. Try to edit an item in the Trash; confirm no Edit button is offered
7. Check the Calendar view at ~400px width

## Performance Considerations

Single user, unbounded lists, no pagination. The union query adds an `OR` predicate and a computed
sort key over a table that realistically holds hundreds to low thousands of rows; the `due_date`
index serves the filter branch, and any residual filesort is immaterial at this scale. This is
recorded rather than optimized — PRD Open Question #2 defers list-view responsiveness deliberately.

## Migration Notes

One additive migration (an index). No data migration: every existing row has `NULL` in all five
columns, which is a valid state under the new rules — an existing item simply has no attributes
until the user sets them. Rollback drops the index; the feature degrades to a slower query, not a
broken one.

## References

- Research: `context/changes/item-metadata-and-calendar/research.md`
- FR-008 interpretation: `context/archive/2026-09-14-guided-clarify-routing/plan.md:46-48`
- "Done is a state, not a destination": `context/archive/2026-09-14-two-minute-rule-timer/change.md:15-38`
- The PATCH refusal: `context/archive/2026-09-14-item-actions-after-clarify/plan.md:63-65`
- The `tags` cast warning (F6): `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-1.md:131-145`
- Guard-and-write pattern: `app/Infrastructure/Item/ItemRepository.php:38-85`
- Edit-panel template: `frontend/src/items/RefileDialog.tsx`
- Test discipline: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: The attributes write path

#### Automated

- [x] 1.1 Pest suite passes — 7b17cb6
- [x] 1.2 Larastan clean at level 6 — 7b17cb6
- [x] 1.3 Pint clean — 7b17cb6
- [x] 1.4 Feature tests cover write, clear, Trash 422, 404, 401, and each invalid field shape — 7b17cb6
- [x] 1.5 Unit tests cover TagListNormalizer, ItemAttributesPayload, ItemService::updateAttributes — 7b17cb6
- [x] 1.6 CaptureItemTest client-injection test extended to tags, context and urgent — 7b17cb6
- [x] 1.7 Deliberate breakage pass: Fillable, Trash guard, tag dedupe each redden — 7b17cb6

#### Manual

- [x] 1.8 Attributes write returns populated item with bucket unchanged — 7b17cb6
- [x] 1.9 All-null payload clears every field — 7b17cb6
- [x] 1.10 Scramble export documents the new endpoint — 7b17cb6

### Phase 2: The derived Calendar view

#### Automated

- [x] 2.1 Pest suite passes — 962f10c
- [x] 2.2 Larastan clean at level 6; Pint clean — 962f10c
- [x] 2.3 Migration applies and rolls back — 962f10c
- [x] 2.4 Feature tests cover union membership, exclusions, completed inclusion and ordering — 962f10c
- [x] 2.5 Deliberate breakage pass: null-rank ordering and action-bucket clause each redden — 962f10c

#### Manual

- [x] 2.6 Dated Next Action appears in Calendar and remains in Next Actions — 962f10c
- [x] 2.7 Undated Calendar-filed item appears below the dated ones — 962f10c

### Phase 3: Rendering attributes and the Calendar view

#### Automated

- [x] 3.1 npm run build and npm run lint pass
- [x] 3.2 Vitest passes
- [x] 3.3 InboxList tests cover each attribute present/absent, date format and overdue marker
- [x] 3.4 BucketPage tests cover Calendar home-bucket labels and empty state
- [x] 3.5 Deliberate breakage pass: tag rendering and date formatting each redden

#### Manual

- [x] 3.6 Seeded item displays all five attributes correctly
- [x] 3.7 Calendar view legible at ~400px with no horizontal scroll
- [x] 3.8 Overdue item distinguishable without relying on colour alone

### Phase 4: The edit panel

#### Automated

- [ ] 4.1 npm run build, npm run lint, npm run test pass
- [ ] 4.2 AttributesDialog tests cover render, submit, clear, field errors, fallback, Escape, tri-state
- [ ] 4.3 InboxList test covers Edit button present outside Trash and absent within
- [ ] 4.4 BucketPage tests cover splice-on-save and focus restore
- [ ] 4.5 Deliberate breakage pass: aria-invalid and full-replacement each redden

#### Manual

- [ ] 4.6 End-to-end edit updates the row without a page reload
- [ ] 4.7 Setting a date surfaces the item in Calendar; clearing it removes the item
- [ ] 4.8 Keyboard-only operation with focus returning to the Edit button
- [ ] 4.9 Invalid date shows an error under the date field, not a generic banner
