---
date: 2026-09-14T19:06:25+02:00
researcher: Jakub Dudek
git_commit: 3b27a70a0588356bfed11f199f09596783f5062a
branch: main
repository: GettingShitDone
topic: "Item attributes (FR-011 + FR-013), and what a date does to the Calendar/Dates bucket"
tags: [research, codebase, items, metadata, calendar, gtd-buckets, fr-008, fr-011, fr-013]
status: complete
last_updated: 2026-09-14
last_updated_by: Jakub Dudek
---

# Research: Item attributes, and what a date does to the Calendar bucket

**Date**: 2026-09-14T19:06:25+02:00
**Researcher**: Jakub Dudek
**Git Commit**: `3b27a70a0588356bfed11f199f09596783f5062a`
**Branch**: `main`
**Repository**: GettingShitDone

> Note on references: `HEAD` is one commit ahead of `origin/main` and **unpushed** at the time
> of writing, so GitHub permalinks pinned to this commit would 404. All references below are
> local `path:line`.

## Research Question

Roadmap **S-06** (`item-metadata-and-calendar`, with S-07 absorbed): give an item a date, tags,
a context and the important/urgent flags, and make an item that has a date show up in the
Calendar/Dates bucket. FR-011 + FR-013.

The change's own stated open question:

> What "shows up in Calendar" means, given FR-008's exactly-one-bucket. Working direction is a
> view over the date, not a second membership.

## Summary

Four findings, in descending order of how much they change the plan.

**1. The premise needs correcting: Calendar/Dates is not an empty bucket waiting for dates.**
It is already a live, fully-wired, user-facing membership destination. It is a labelled button
in the clarify dialog (`frontend/src/items/ClarifyDialog.tsx:38`), a legal refile target, and
an *action bucket* whose items can be marked done (`app/Domain/Item/GtdBucket.php:59-64`).
Tests pin all of this. So S-06 is not adding Calendar — it is adding **dates** to a bucket that
already carries an independent meaning. The design question is therefore not "view vs.
membership" but "how does a new date attribute relate to an existing membership that already
means something."

**2. FR-008 is not actually in tension here, and the project already ruled on why.** S-02's plan
settled the interpretation:

> "**FR-008's 'two buckets at once' half is structurally impossible** — one `bucket` column. The
> testable half is *'nothing falls through'*: every valid answer set must produce a non-Inbox
> bucket."
> — `context/archive/2026-09-14-guided-clarify-routing/plan.md:46-48`

FR-008 is a constraint on the **stored column**, not on how many *views* an item can appear in.
A derived Calendar view does not violate it. This removes the blocker the change.md was worried
about, and it is a settled project decision rather than a fresh reading.

**3. The read path is already finished; the gap is exactly the write path.** `ItemDto` already
carries all five fields, and `tests/Unit/Item/ItemDtoTest.php:50-68` round-trips them with real
non-null values (`dueDate: '2026-09-30'`, `tags: ['work','urgent']`, `context: '@computer'`).
The frontend `Item` interface already declares all five (`frontend/src/api.ts:187-208`). The
repository's `toDto()` already reads all five. Nothing needs to change in the DTO, the
TypeScript type, or the read mapping — S-01 pre-built them on purpose. The missing pieces are
narrow: a FormRequest, a Payload, a Service method, a repository write, and UI.

**4. Two documented constants do not exist, and one inherited landmine is aimed squarely at
this slice.** `ClarifyConst::DATE_FORMAT` is referenced four times in `app/CLAUDE.md` and
asserted by `context/foundation/roadmap.md:233` to be "already present" — it is not in the code.
Neither is `app/Validators/`. And S-01's impl review left an explicit warning that the `tags`
JSON cast has no shape guarantee and that "**S-06/S-07 will be the first code to discover
this**."

**Recommendation on the open question:** keep the date as a pure attribute that never rewrites
`bucket`, keep Calendar's existing membership meaning untouched, and make the Calendar view a
**scoped union** of the two. Full reasoning and the alternative in
[The Calendar question](#the-calendar-question--evaluation-and-recommendation) below.

## Detailed Findings

### The five dormant columns — what exists, precisely

Schema, from `database/migrations/2026_09_07_100000_create_items_table.php:22-27`:

```php
// Dormant until S-06 (dates, FR-011) and S-07 (metadata, FR-013). The columns
// ship now so the API shape stays stable; they have no write path yet.
$table->date('due_date')->nullable();
$table->json('tags')->nullable();
$table->string('context', 64)->nullable();
$table->boolean('important')->nullable();
$table->boolean('urgent')->nullable();
```

They are **cast but not fillable** — `app/Models/Item.php:36` declares
`#[Fillable(['title', 'note', 'bucket'])]`, and the casts at `app/Models/Item.php:41-52` include
`due_date => 'date'`, `tags => 'array'`, `important`/`urgent` => `'boolean'`.

What is already done for them:

| Layer | State | Reference |
| --- | --- | --- |
| Migration / casts | Done | `database/migrations/2026_09_07_100000_create_items_table.php:22-27` |
| `ItemDto` fields + `fromArray`/`toArray` | Done | `app/Dto/ItemDto.php:26-40`, `:45-87` |
| Scramble `@return array{...}` | Done, includes all five | `app/Dto/ItemDto.php:68`, `:90` |
| Repository `Model → DTO` read | Done | `app/Infrastructure/Item/ItemRepository.php:220-242` |
| Frontend `Item` TS interface | Done | `frontend/src/api.ts:187-208` |
| Test fixture `makeItem` | Done (all `null`) | `frontend/src/test/server.ts:22-39` |
| **Write path (validation → persist)** | **Absent — this slice** | — |
| **Any UI that renders or edits them** | **Absent — this slice** | — |

The dormancy is actively pinned by a test that this slice must deliberately revise —
`tests/Feature/Item/CaptureItemTest.php:127-146` posts `important: true` and
`dueDate: '2026-12-31'` on capture and asserts both come back `null`. Note it covers only
`important` and `dueDate`; `tags`, `context` and `urgent` are **not** covered by a client-injection
attempt, so the equivalent guarantee for those three is currently unproven.

### Calendar/Dates is already a live membership destination

This is the correction to the change's premise. Evidence from four independent places:

- `GtdBucket::isDestination()` returns true for it — anything but Inbox
  (`app/Domain/Item/GtdBucket.php:45-48`).
- `GtdBucket::isActionBucket()` lists it explicitly, so items there can be marked done:
  ```php
  return match ($this) {
      self::NextActions, self::Projects, self::Calendar, self::Delegation => true,
      self::Inbox, self::SomedayMaybe, self::Reference, self::Trash => false,
  };
  ```
  — `app/Domain/Item/GtdBucket.php:58-64`
- Clarify's quick-route falls straight through to it — `quickRoute()` rejects only `null` and
  `Inbox`, special-cases `Delegation`, and returns `ClarifyOutcome::to($bucket)` for everything
  else (`app/Domain/Clarify/ClarifyDecision.php:66-81`).
- It is a **visible, labelled button in the UI today**: `{ value: 'calendar', label: 'Calendar' }`
  in `QUICK_ROUTES` (`frontend/src/items/ClarifyDialog.tsx:38`), and a member of
  `ACTION_BUCKETS` (`frontend/src/items/buckets.ts:36-41`).

And it is pinned by tests: `tests/Feature/Item/RefileItemTest.php:47-51` refiles to `calendar`
and reads it back; `tests/Feature/Item/CompleteItemTest.php:59` completes items in it;
`tests/Unit/Item/GtdBucketTest.php:52` asserts `'calendar' => true` for `isActionBucket()`.

What is *not* reachable: the guided decision tree never routes to Calendar on any branch — only
the FR-002 quick-route and refile can put an item there. And `due_date` has no write path at
all, so **no code anywhere derives Calendar membership from a date**.

S-05, which built the eight bucket views, gave Calendar no special treatment whatsoever — a
grep for "calendar" across its plan and reviews returns nothing. So there is no prior deferral
to honour: this question is genuinely unaddressed rather than quietly postponed.

### The write-path spine a new verb must match

All three existing write verbs (`clarify`, `refile`, `complete`) are built to one shape, and a
metadata verb should be the fourth instance of it.

**Route** — own endpoint, `whereNumber` guard (`routes/api.php:31-41`).
**Controller** — ~3 lines, `Payload::fromArray($request->validated())` into the service
(`app/Http/Controllers/Api/V1/RefileController.php:32-38`).
**Payload** — private constructor + named static factories; carries a *command*, never state; no
`toArray()`, never returned (`app/Dto/Payload/RefileItemPayload.php`).
**Service** — re-asserts domain guards even when the FormRequest already checked them
(defense-in-depth, `app/Services/ItemService.php:100-123`), wraps the repo call in
`try { … } catch (ItemPersistenceException $e) { LogEvent::xFailed(…); throw $e; }`, logs success
after.
**Repository** — the load-bearing pattern. A **guard-and-write in one statement**, never
read-then-write:

```php
$affected = Item::query()
    ->where('id', $itemId)
    ->where('bucket', GtdBucket::Inbox->value)   // the guard
    ->update([ /* the write */ ]);
```
— `app/Infrastructure/Item/ItemRepository.php:38-85`

`$affected === 0` then triggers a targeted existence re-check to choose 404 vs. 409. Two
consequences for S-06:

1. **This is also how the non-fillable columns get written.** Query-builder `update()` bypasses
   Eloquent mass-assignment entirely, so `#[Fillable]` never needs loosening — the dormant
   columns stay unreachable from any request array while still being writable by an explicit
   verb. Keep it that way.
2. **The MySQL affected-rows hazard is already fixed** — S-11's F4 finding added
   `Mysql::ATTR_FOUND_ROWS => true` (`config/database.php:70`, `:97`) so `$affected === 0` means
   the same thing on MySQL as on SQLite. A new verb inherits this for free but must not
   introduce a second read-then-write path that sidesteps it.

**Exception → HTTP mapping** lives in `bootstrap/app.php:34-78`, not in controllers:
`ItemNotFoundException` → 404, `ItemNotInInboxException` → 409,
`ItemActionNotAllowedException` → 422, `ItemPersistenceException` → 500 with a *fixed generic*
message so the SQLSTATE never reaches the client.

**Logging** — `LogEvent` only, naming `item.<verb>.success` / `.failure`, failures carrying
SQLSTATE only, never raw user text (`app/Logging/LogEvent.php`).

#### On "no generic PATCH"

The refusal is recorded twice — `routes/api.php:28-32` and
`context/archive/2026-09-14-item-actions-after-clarify/plan.md:63-65`:

> "**No `PATCH /items/{id}`.** A generic update verb … would re-open the 'client chooses where an
> item lands' hole that both payload classes are shaped to close."

Read precisely, the hole is **"the client chooses where an item lands"** — not "more than one
field per request." A single `POST /items/{itemId}/attributes` verb that writes exactly
`due_date, tags, context, important, urgent` and structurally cannot touch `bucket`,
`completed_at`, `title`, `note` or `delegated_to` does not re-open that hole. It stays inside the
discipline while avoiding five near-identical endpoints. This is a judgement call the plan should
make explicitly rather than inherit by assumption.

### Frontend landing zone

Flat structure — only `items/`, `auth/`, `test/` under `frontend/src/`. No `components/`,
no `ui/`, no `lib/`.

- **Routing**: `/` → `InboxPage`, `/bucket/:bucket` → `BucketPage`
  (`frontend/src/AppRoutes.tsx:14-30`). Bucket is a URL param, validated by `isGtdBucket()`.
- **Both pages render the same `InboxList`**, parameterized by which callbacks are passed
  (`frontend/src/items/BucketPage.tsx:234-247`). The single item row is
  `frontend/src/items/InboxList.tsx:48-100` — today it shows checkbox, title, done marker, note,
  `delegatedTo`, `createdAt`, and Clarify/Move buttons. **No metadata rendering exists.**
- **Mutation pattern**: no React Query. `useState` + `useEffect` fetch-on-mount; mutations splice
  the server's returned object into local state, never refetch. The optimistic+rollback example
  is `handleComplete` (`frontend/src/items/BucketPage.tsx:75-124`); the dialog-submit example,
  closer to what an edit form needs, is `RefileDialog.send`
  (`frontend/src/items/RefileDialog.tsx:37-48`).
- **No Modal/Drawer abstraction exists.** Both "dialogs" are `role="dialog"` `<section>`s rendered
  inline in page flow, each managing its own Escape handling and focus restore. `RefileDialog.tsx`
  is the template to copy; building a shared abstraction would be new infrastructure.
- **No per-field error display exists anywhere.** `messageFor(err)`
  (`frontend/src/apiMessage.ts:13-32`) turns a 422 into one generic string in a single
  `role="alert"`; the backend's `errors: {field: [...]}` shape is never parsed. A five-field edit
  form is the first surface where this convention is genuinely strained — the plan must either
  keep it deliberately or introduce field-level errors.
- **Styling**: Tailwind v4, one `frontend/src/index.css`, light-only, no config file. Reusable
  classes: `.card`, `.panel`, `.btn*`, `.field`, `.live-line`. No `<select>` exists anywhere in
  the app — every choice so far is a button group.
- **Tests**: Vitest + RTL + MSW, `getByRole`/`getByLabelText` only. `makeItem` fixture at
  `frontend/src/test/server.ts:22-39` already has all five fields defaulted to `null` and is the
  natural place to extend.

### Constants, and two gaps

`ItemConst` has three constants — `TITLE_MAX_LENGTH`, `NOTE_MAX_LENGTH`,
`DELEGATED_TO_MAX_LENGTH` (`app/Const/ItemConst.php`). `ClarifyConst` has two —
`TWO_MINUTE_SECONDS`, `MAX_TWO_MINUTE_LOOPS` (`app/Const/ClarifyConst.php`).

**Gap 1 — `ClarifyConst::DATE_FORMAT` does not exist.** It appears only as an illustrative
example in `app/CLAUDE.md:220,230,240,269`. `context/foundation/roadmap.md:233` states it is
"already present" and instructs S-06 to use it. It is not in the code. The de facto format is
already `Y-m-d` (the `date` cast plus `->toDateString()` at
`app/Infrastructure/Item/ItemRepository.php:220-242`), but it is unnamed. This slice must
introduce the constant, and the roadmap line should be corrected.

**Gap 2 — no constant mirrors `context`'s `string(64)` column.** Every other length limit is
bound to a constant precisely so validation and schema cannot drift
(`app/Const/ItemConst.php:6-8`). `CONTEXT_MAX_LENGTH` is missing, as is any bound on tag count or
tag length.

**A precedent worth copying for the cross-boundary constants.** `ClarifyConst`'s own docblock
documents how this codebase handles a value that must hold in both PHP and TypeScript:

> "`TWO_MINUTE_SECONDS` is stated here and mirrored once in the SPA (`frontend/src/api.ts`) …
> That makes the usual 'single source' claim unenforceable by ordinary use, so
> `tests/Unit/Clarify/TwoMinuteThresholdContractTest.php` reads the TypeScript and fails when the
> two drift apart."
> — `app/Const/ClarifyConst.php:6-13`

S-06 reintroduces exactly this problem (date format, context/tag limits, both validated server-side
and rendered client-side). There is a built answer to reuse rather than invent.

Also absent: **`app/Validators/` does not exist**. A `DataAwareRule` for cross-field metadata
validation would be its first inhabitant.

### Inherited landmines

**The `tags` cast has no shape guarantee — and S-01's review named this slice as the one that
would find out.** From `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-1.md:131-145`
(F6, OBSERVATION, deliberately SKIPPED):

> "The `'tags' => 'array'` cast returns whatever `json_decode(…, true)` produces — a JSON object
> yields a string-keyed array, a scalar yields a scalar — neither matches the declared
> `array<int, string>|null` on `ItemDto`, and nothing coerces or validates it. **S-06/S-07 will be
> the first code to discover this.**"

The repository does defend partially — `tags: $item->tags === null ? null : array_values($item->tags)`
(`app/Infrastructure/Item/ItemRepository.php:220-242`) packs a string-keyed array back into a
list — but a scalar in the column would still violate the declared type. Validation on the way in
is the real fix.

**The composite index is bucket-shaped, not date-shaped.** `['bucket','created_at','id']`, with the
migration comment stating "the only query is `where bucket = ? order by created_at desc, id desc`"
(`database/migrations/2026_09_07_100000_create_items_table.php:31-35`). **There is no index on
`due_date`.** Any date-ordered or date-filtered Calendar query needs its own index decision, made
with the same "match the actual query" reasoning that produced the composite one (itself an
impl-review fix, F5).

**No pagination anywhere.** `listByBucket` is `get()`, not `paginate()`
(`app/Infrastructure/Item/ItemRepository.php:177-186`), deliberately deferred per PRD Open
Question #2. A union-shaped Calendar view inherits that and does not make it worse.

## Code References

- `database/migrations/2026_09_07_100000_create_items_table.php:22-27` — the five dormant columns
- `database/migrations/2026_09_07_100000_create_items_table.php:31-35` — composite index + its rationale
- `app/Models/Item.php:36` — `#[Fillable(['title','note','bucket'])]`, the dormancy mechanism
- `app/Domain/Item/GtdBucket.php:45-48` — `isDestination()`
- `app/Domain/Item/GtdBucket.php:58-64` — `isActionBucket()`, Calendar included
- `app/Domain/Clarify/ClarifyDecision.php:66-81` — quick-route lets Calendar through
- `app/Infrastructure/Item/ItemRepository.php:38-85` — the guard-and-write pattern
- `app/Infrastructure/Item/ItemRepository.php:177-186` — `listByBucket`, single `where bucket = ?`
- `app/Infrastructure/Item/ItemRepository.php:220-242` — `toDto()`, already reads all five fields
- `app/Dto/ItemDto.php:26-40` — DTO already carries all five
- `app/Services/ItemService.php:100-123` — service-level domain re-guard (S-11 F1's fix)
- `app/Const/ClarifyConst.php:6-13` — the PHP↔TS constant-drift contract-test precedent
- `bootstrap/app.php:34-78` — exception → HTTP status mapping
- `config/database.php:70,97` — `ATTR_FOUND_ROWS` (S-11 F4's fix)
- `routes/api.php:28-32` — the recorded refusal of a generic PATCH
- `tests/Feature/Item/CaptureItemTest.php:127-146` — pins dormancy; this slice must revise it
- `tests/Unit/Item/ItemDtoTest.php:50-68` — DTO round-trips all five with real values
- `tests/Feature/Item/RefileItemTest.php:47-51` — refile to Calendar, pinned
- `frontend/src/api.ts:187-208` — `Item` TS interface, all five declared dormant
- `frontend/src/items/ClarifyDialog.tsx:38` — Calendar as a user-clickable quick-route
- `frontend/src/items/buckets.ts:36-41` — Calendar in `ACTION_BUCKETS`
- `frontend/src/items/InboxList.tsx:48-100` — the item row, where metadata would render
- `frontend/src/items/RefileDialog.tsx:37-48` — the edit-surface template
- `frontend/src/apiMessage.ts:13-32` — single generic 422 message, no per-field errors

## Architecture Insights

**Two settled precedents both point the same way, and neither was invented for this slice.**

1. *Done is a state, not a destination.* S-03 faced the structurally identical question — where
   does a completed item live, when there is no Done bucket among the eight? — and answered with a
   nullable column, not a ninth bucket:
   > "A completed 2-minute item lands in **Next Actions** with `completed_at` set. … Next Actions
   > is what the item already was. Doing it immediately changes its **state**, not its
   > **classification**."
   > — `context/archive/2026-09-14-two-minute-rule-timer/change.md:15-38`

   And the consequence in code is instructive: `listByBucket` has **no** `completed_at` predicate.
   Completed and open items come back together, and the hide/show decision is made client-side by
   filtering the already-fetched array (`frontend/src/items/BucketPage.tsx:194-197`). A
   cross-cutting fact became a field plus a presentation rule — never a membership change.

2. *The Eisenhower quadrant is computed, not stored.* Root `CLAUDE.md` requires it, and the
   roadmap's S-08 entry — a slice that **depends on S-06** — restates it as the risk to avoid:
   > "The quadrant is a derived view from important × urgent, not stored state. The risk is
   > materializing it as a persisted field instead of a computed projection over S-06's
   > important/urgent flags."
   > — `context/foundation/roadmap.md:271` (S-08)

   Note what this implies for the `important`/`urgent` encoding: they must stay two independent
   nullable booleans. Collapsing them into a single `priority` scale would make the quadrant
   underivable. The columns as shipped are already right; the slice's job is not to redesign them.

**The layering holds and should not be bent.** Controller ~3 lines, Service free of
`Illuminate\Http`, Repository returning DTOs only, domain guards duplicated deliberately at edge
*and* service. S-11's F1 finding exists precisely because a guard was enforced only at the HTTP
edge — the lesson is already paid for.

**There is no backend precedent for a derived query.** Every repository read is
`where bucket = ?`. The only "derived view" shipping today is a client-side `.filter()`. Whatever
S-06 does for Calendar will be the first backend query selecting on something other than `bucket`,
and will therefore set the pattern S-08 and S-09 follow.

## The Calendar question — evaluation and recommendation

### Why the tension is smaller than it looks

Two facts dissolve most of it:

- **FR-008 constrains the column, not the view.** S-02 already ruled: "FR-008's 'two buckets at
  once' half is structurally impossible — one `bucket` column. The testable half is 'nothing falls
  through'" (`context/archive/2026-09-14-guided-clarify-routing/plan.md:46-48`). An item appearing
  in a derived Calendar view while its stored bucket remains `next_actions` does not violate
  FR-008 under the project's own settled reading.

- **FR-011's resolution already drew the distinction.** Read it closely:
  > "kept both — a date is a field on **any task** (e.g. a deadline) AND Calendar/Dates is a
  > separate GTD bucket/view for **date-specific items**."
  > — `context/foundation/prd.md:146-151`

  Those are two different things, and the distinction is canonical GTD: the calendar holds
  day-specific commitments ("dentist, Tuesday 3pm" — the item *is* the date), while a deadline is
  an attribute of an action that still lives in Next Actions. FR-011 asks for both, not for one
  collapsed into the other.

### The options, measured against the code

| | Interface change | New index | Matches precedent | Breaks shipped behaviour | Loses prior bucket |
| --- | --- | --- | --- | --- | --- |
| **A.** Date is an attribute only; Calendar stays a filing destination, view ordered by date | none | yes (ordering) | yes | no | no |
| **B.** Date is an attribute; Calendar view = scoped union of filed-there ∪ dated-actionable | new repo method | yes | yes | no | no |
| **C.** Setting a date rewrites `bucket` to Calendar | none | no | **no** | **yes** | **yes** |

**C should be rejected.** It reuses `refile()` verbatim and needs no new infrastructure, which is
its only appeal. Against it: it contradicts the `completed_at` precedent directly; it makes
FR-011's own "a date is a field on any task" false in practice, since dating a Project would
silently reclassify it; and it destroys the item's substantive classification (a dated Project, a
dated Delegation and a dated Next Action all become indistinguishable). It also makes the
ordinary GTD case — "submit the report by Friday", an action with a deadline — unrepresentable.

**A is correct but incomplete.** It is the minimal, precedent-perfect reading: dates never touch
`bucket`, Calendar keeps meaning "I filed this here because it is day-specific", and the view
sorts by date. Everything already shipped keeps working, and it needs no new repository method.
Its weakness is that it does not deliver what the change.md actually asks for — "make an item that
has a date show up in the Calendar/Dates bucket". Under A, a dated Next Action does *not* appear in
Calendar.

**B is the recommendation.** Keep the date as a pure attribute (never rewriting `bucket`), keep
Calendar's existing membership meaning entirely intact, and make the Calendar view the union of the
two:

```
Calendar view = bucket = 'calendar'
              OR (due_date IS NOT NULL AND bucket IN ('next_actions','projects','delegation'))
```

ordered by `due_date` ascending, then the existing `created_at desc, id desc` tiebreak.

Why this one:

- It delivers FR-011 literally and in both halves — the field on any task, *and* date-specific
  items appearing in Calendar.
- It preserves FR-008 exactly as the project already interprets it: one stored `bucket`, untouched.
- It is precedent-consistent with both `completed_at` (a field that changes presentation, not
  membership) and Eisenhower (a computed projection).
- **It breaks nothing.** The quick-route button, refile-to-Calendar, completion in Calendar and
  every test pinning them keep working unchanged, because the stored-bucket branch of the union is
  exactly today's behaviour.
- The `isActionBucket()` scoping is not arbitrary — it reuses vocabulary the domain already owns,
  and it prevents the absurd cases: a dated Reference note or a dated item in the Trash must not
  surface as a commitment. (Calendar is itself an action bucket, so the second clause could be
  written as `isActionBucket() AND due_date IS NOT NULL`, making the whole rule "filed there, or an
  actionable commitment carrying a date.")

Costs to accept, all of them real and none fatal:

- A new repository method is required — `listByBucket(GtdBucket)` cannot express this predicate,
  and the service, currently a bare pass-through (`app/Services/ItemService.php:172-175`), must
  branch. This will be the codebase's first non-`bucket` query, so it sets a pattern; it should be
  a named method (`listCalendar()`), not a flag on the existing one.
- A `due_date` index is needed, chosen against the actual query shape.
- Calendar becomes the one view where an item's row is not "in" the bucket the URL names. The UI
  should show *why* an item is there — its date, and arguably its home bucket — or the view will
  read as a bug.

### What this means for the slice's shape

The `important`/`urgent` encoding needs no invention: keep them as two independent nullable
booleans so S-08 can derive four quadrants. Nullable matters — "not yet judged" is distinct from
"judged not important", and the existing schema already allows that distinction.

## Historical Context (from prior changes)

- `context/archive/2026-06-25-capture-to-inbox/plan.md:79-81` — the dormant columns were a
  deliberate bet: "One migration instead of four; keeps the API shape stable for the frontend
  across later slices." The recorded risk: "if S-06/S-07 change their field shape, the unused
  columns will need a migration anyway." **Assessment: the bet paid off.** The five columns and
  the DTO shape are adequate as-is; no migration is needed except a `due_date` index.
- `context/archive/2026-06-25-capture-to-inbox/reviews/impl-review-phase-1.md:131-145` — F6, the
  `tags` cast warning explicitly addressed to this slice.
- `context/archive/2026-09-14-item-actions-after-clarify/plan.md:63-65` — the second PATCH refusal
  and its precise rationale.
- `context/archive/2026-09-14-item-actions-after-clarify/reviews/impl-review.md` — the ten
  findings. F1 (domain guard enforced only at the edge), F2 (a write silently clobbering a sibling
  column), F3 (an assertion that could not distinguish carried from re-stamped), F4
  (MySQL/SQLite affected-rows divergence) are the four most likely to recur in a new write verb.
- `context/archive/2026-09-14-two-minute-rule-timer/change.md:15-38` — "done is a state, not a
  destination", the closest structural precedent.
- `context/archive/2026-09-14-guided-clarify-routing/plan.md:46-48` — the FR-008 interpretation
  this research leans on.
- `context/archive/2026-09-14-guided-clarify-routing/plan.md:66-67` — "No edit or delete of items
  — absent from the PRD entirely… recorded as a product gap." S-06 is the slice that closes that
  gap for the five metadata fields.
- `context/archive/2026-09-14-eight-bucket-views/` — no mention of Calendar anywhere; nothing was
  deferred here to honour.
- `context/archive/2026-09-14-visual-design-pass/change.md:20-38` — Tailwind v4 alone, light-only,
  no component kit, no visual-regression pin. Constrains the new edit surface.

## Related Research

No prior research artifact addresses item metadata or the Calendar/Dates semantics. The archived
changes above carry their own `research.md` files for their respective slices; the
guided-clarify-routing and two-minute-rule-timer ones are the most relevant neighbours.

## Open Questions

1. **Does the recommendation land?** B (scoped union) delivers FR-011 in full but introduces the
   first derived backend query and a view whose contents aren't all "in" the named bucket. A is
   simpler and precedent-perfect but doesn't make dated items appear in Calendar. This is the one
   decision the plan must make before anything else. *Owner: user.*
2. **One `/attributes` verb, or several?** A single narrow verb writing exactly the five columns
   stays inside the no-generic-PATCH discipline (it structurally cannot choose where an item
   lands), but the plan should say so explicitly rather than assume it. *Owner: plan.*
3. **Field-level validation errors?** A five-field form is the first surface that strains the
   single-generic-message convention (`frontend/src/apiMessage.ts:13-32`). Keep it deliberately, or
   introduce per-field errors here. *Owner: plan.*
4. **Tag vocabulary and shape** — free-text tags, or a constrained set? No limit on count or length
   exists, and the JSON cast has no shape guarantee (F6). Validation must pin this on the way in.
   *Owner: plan.*
5. **Should a Calendar-filed item be required to have a date?** Under B, an item filed to Calendar
   with no date still appears (via the stored-bucket branch). Whether that's a legitimate state or
   something the UI should prompt to fix is a product call. *Owner: user.*
6. **Editable from which buckets?** `setCompleted` restricts to action buckets; `refile` excludes
   Inbox. Whether attributes can be edited on an unclarified Inbox item, or on a Trash item, needs
   an explicit guard decision rather than a default. *Owner: plan.*
7. **Correct `context/foundation/roadmap.md:233`**, which tells this slice to use a
   `ClarifyConst::DATE_FORMAT` that does not exist. *Owner: plan (small docs fix).*
