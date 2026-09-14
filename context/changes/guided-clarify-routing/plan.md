# Guided clarify routing — Implementation Plan

## Overview

Roadmap slice **S-02**. The user opens an Inbox item, answers the GTD decision tree one
question at a time, and the item lands in exactly one bucket. This is the second half of
US-01 and the PRD's second guardrail — *"clarify never leaves an item without a bucket"*.

## Current State Analysis

- **No clarify code exists.** `grep -rli clarify app/ routes/ frontend/src/ database/` returns
  only `app/CLAUDE.md` (conventions).
- **`ItemRepositoryInterface` has `create()` and `listByBucket()` only.** Clarify is the first
  write path that *modifies* an existing row, and there is **no transaction seam anywhere in
  the item path** — no `DB::transaction`, no `transaction(callable)` on the interface.
- **`GtdBucket` already carries all 8 cases**, including `Projects`, persisted as a plain
  indexed `varchar(32)` (not a native enum — SQLite, which is the whole test suite, would not
  enforce one).
- **`items` has five dormant nullable columns** (`due_date`, `tags`, `context`, `important`,
  `urgent`) and **none for Delegation**.
- **`bucket` is fillable on the `Item` model.** Capture's "always Inbox" invariant rests on
  `CaptureItemPayload` having no bucket field plus `ItemService` passing the enum explicitly.
- **The frontend has a working test harness** (Vitest + jsdom + MSW + RTL, 34 tests) and a
  blocking CI gate, both landed by the Phase 1 test rollout.

## Desired End State

From the Inbox, the user clarifies an item by answering up to three questions and sees it
leave the Inbox for its destination. Every answer path terminates in exactly one bucket.

Verify: `php artisan test` green including a unit test across the full branch matrix and a
feature test proving the item is readable in its target bucket and absent from the Inbox;
`cd frontend && npm test` green including the wizard flow.

### Key Discoveries:

- **The PRD tree is `actionable? → single step? → < 2 min? → delegable?`** (FR-003, Business
  Logic). This slice builds it **without the `< 2 min?` question** — S-03 inserts that between
  single-step and delegable. Every remaining path terminates, so FR-008 is fully provable now.
- **S-04 is absorbed.** Its roadmap entry says *"in the MVP a Project is just a destination
  bucket"*, and `GtdBucket::Projects` already exists — the multi-step branch is one enum value.
  S-04 must be closed in the roadmap as absorbed, not left dangling.
- **FR-002 includes a quick-route** — its Socrates resolution says *"within clarify, a user can
  pick the destination bucket directly instead of answering the full tree"*. It is in scope and
  is **not** FR-010 (re-filing an already-bucketed item, deferred to v2).
- **FR-008's "two buckets at once" half is structurally impossible** — one `bucket` column. The
  testable half is *"nothing falls through"*: every valid answer set must produce a non-Inbox
  bucket.
- **`app/CLAUDE.md` block map**: a business operation that changes entity state gets a Payload
  in `Dto/Payload/`, its own Controller, and a Service method; the decision logic goes in a
  Domain Entity, pure PHP. A Strategy + Factory is for *many algorithm variants* — one fixed
  tree does not qualify, so an Entity is the right block here.
- **`context/foundation/lessons.md`**: a test is not coverage until deliberate breakage has
  reddened it. Applies to every test below.

## What We're NOT Doing

- **No `< 2 min?` question and no timer** — S-03 (FR-006). Nothing in this slice may reference
  a 120-second threshold or a "done" state; there is no Done bucket among the 8, and deciding
  where a completed item goes is S-03's problem.
- **No project hierarchy** — FR-012 is parked. Projects is a destination bucket, nothing more.
- **No re-filing of already-bucketed items** (FR-010, v2). Clarify accepts Inbox items only.
- **No contact or user entity for Delegation** — PRD FR-007 is explicit: free text, not accounts.
- **No server-side clarify session.** Decided: the client walks the tree, the server derives
  the bucket from the submitted answers.
- **No edit or delete of items** — absent from the PRD entirely (no FR covers them); out of
  scope here and recorded as a product gap, not a defect of this slice.
- **No changes to the capture path.** The guard that keeps `bucket` out of client input on
  capture must not be loosened to make clarify convenient.

## Implementation Approach

Stateless clarify. The client walks the tree locally and submits one request with the complete
answer set; the server re-derives the destination from those answers in a pure-PHP entity and
persists the transition. The client never sends a bucket — exactly as capture never lets the
client choose one. Three phases: domain + data, then HTTP, then the wizard.

## Critical Implementation Details

**The client sends answers, never a destination.** The one exception is the FR-002 quick-route,
where the user explicitly picks a bucket. Those are two different payload shapes and must stay
distinguishable at the HTTP edge — a single optional `bucket` field that the tree path could
also populate would recreate, on the clarify endpoint, exactly the hole that
`CaptureItemPayload` was shaped to avoid. Model the quick-route as its own answer mode, and
let the domain reject a payload that carries both a tree path and an explicit bucket.

**Delegation's destination and its note arrive together.** `delegated_to` is required when and
only when the delegable branch is taken — `required_if` / `prohibited_unless` at the edge (see
`app/CLAUDE.md` "Cross-field invariants — built-ins first"), not a custom rule.

---

## Phase 1: Domain and data

### Overview

The decision tree as pure PHP, the two Delegation columns, and the first repository write path
that modifies an existing row.

### Changes Required:

#### 1. Delegation columns

**File**: `database/migrations/<timestamp>_add_delegation_fields_to_items_table.php`

**Intent**: Give FR-007 somewhere to live, following the dormant-column pattern the table
already uses.

**Contract**: Adds `delegated_to` (nullable string, 255) and `delegation_done` (nullable
boolean). Both nullable because they are meaningful for exactly one of eight buckets. Update
`app/Models/Item.php` casts and PHPDoc, and add them to the `#[Fillable]` list only if the
repository writes them through mass assignment.

#### 2. Clarify payload

**File**: `app/Dto/Payload/ClarifyItemPayload.php`

**Intent**: Carry the user's answers from the HTTP edge to the domain as a command, not as
entity state.

**Contract**: Readonly, `fromArray()` taking camelCase keys. Carries the tree answers
(`actionable`, `singleStep`, `delegable` — the last two only meaningful when the previous is
true), the non-actionable destination choice, an optional `delegatedTo`, and the quick-route
bucket. It carries **no** general-purpose bucket field. Mirrors `CaptureItemPayload`'s shape
and namespace.

#### 3. The decision tree

**File**: `app/Domain/Clarify/ClarifyDecision.php` (entity), `app/Domain/Clarify/ClarifyOutcome.php`
(value object) or equivalent under `app/Domain/Clarify/`

**Intent**: Own the routing rule. Pure PHP — no Eloquent, no framework, no HTTP. This is the
slice's whole correctness surface and the only place the tree is encoded.

**Contract**: A total function from `ClarifyItemPayload` to a `GtdBucket` plus any fields the
branch implies (`delegatedTo` on the Delegation branch). Terminating paths, derived from the
PRD and **not** from any existing code:

| Answers | Destination | PRD |
| --- | --- | --- |
| `actionable = false` | the chosen one of Trash / SomedayMaybe / Reference | FR-004 |
| `actionable, !singleStep` | Projects | FR-005 |
| `actionable, singleStep, delegable` | Delegation (+ `delegatedTo`) | FR-007 |
| `actionable, singleStep, !delegable` | NextActions | FR-008 |
| quick-route | the bucket the user picked | FR-002 |

Must never return Inbox and must never return null for a well-formed payload. An
ill-formed payload (e.g. non-actionable with no destination chosen, or delegable with no
`delegatedTo`) is rejected by throwing, not by falling through to a default.

#### 4. Repository write path

**File**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Infrastructure/Item/ItemRepository.php`

**Intent**: The first method that modifies an existing item. Also the place to decide whether
this slice needs a transaction seam.

**Contract**: A method that applies a clarification to one item by id and returns the updated
`ItemDto` — bucket plus the Delegation fields when present. It must fail loudly if the item
does not exist or is not currently in the Inbox, rather than silently updating nothing; the
service turns that into an HTTP status in Phase 2. Reuse the `QueryException` →
`ItemPersistenceException` confinement already in `create()` — the same reasoning about query
bindings in log messages applies. This slice performs one UPDATE, so no transaction is
introduced; record in the plan's References that the seam is still absent.

#### 5. Service orchestration and the domain event

**File**: `app/Services/ItemService.php`, `app/Logging/LogEvent.php`

**Intent**: Build the decision from the payload, persist the result, emit the event
`app/CLAUDE.md` already names for this slice.

**Contract**: `ItemService::clarify(int $itemId, ClarifyItemPayload $payload): ItemDto` — no
`Illuminate\Http` import. `LogEvent::itemClarified(int $itemId, GtdBucket $bucket)` emits
`item.clarified.success` (category `database`, outcome `success`) at info level, and a failure
counterpart mirroring `itemCaptureFailed`. Neither may carry the item's title or note — the
same privacy rule that shaped the capture events.

#### 6. Unit tests across the full branch matrix

**File**: `tests/Unit/Clarify/ClarifyDecisionTest.php`

**Intent**: Prove every path terminates in exactly one bucket, and that no path yields Inbox.

**Contract**: One case per row of the table above, plus the three non-actionable destinations
separately, plus the rejection cases. **Derive every expected bucket from the PRD FR cited in
that row, never by reading `ClarifyDecision`** — an assertion lifted from the implementation
can only confirm current behaviour, including current bugs. Add one test asserting that no
answer combination produces `GtdBucket::Inbox`.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly: `php artisan migrate:fresh`
- Unit tests pass: `php artisan test tests/Unit`
- Full backend suite passes: `php artisan test`
- Larastan level 6 clean: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint clean: `./vendor/bin/pint --test`
- Branch-matrix test proven able to fail: point one branch at the wrong bucket, confirm red, revert

#### Manual Verification:

- The decision table in `ClarifyDecision` matches PRD FR-004/005/007/008 read side by side

---

## Phase 2: HTTP edge

### Overview

Expose clarify as an endpoint, with the cross-field rules at the edge and the state guard
mapped to a real status.

### Changes Required:

#### 1. Request validation

**File**: `app/Http/Requests/ClarifyItemRequest.php`

**Intent**: Enforce the answer shape before the domain sees it, using Laravel built-ins rather
than a custom rule.

**Contract**: Rules as arrays, never pipe-strings. `delegatedTo` uses `required_if` /
`prohibited_unless` against the delegable answer; the non-actionable destination is
`required_if:actionable,false` and constrained by `Rule::enum(GtdBucket::class)` narrowed to
the three FR-004 values; the quick-route bucket is `prohibited_with` the tree answers. Free
text is scrubbed in `prepareForValidation()` with the `is_string()` guard pattern already used
by `CaptureItemRequest`.

#### 2. Controller and route

**File**: `app/Http/Controllers/Api/V1/ClarifyController.php`, `routes/api.php`

**Intent**: A state-changing business operation gets its own controller per `app/CLAUDE.md`.

**Contract**: `POST /api/items/{item}/clarify` inside the existing
`['auth:sanctum', 'throttle:api', LogContextMiddleware]` group. Controller is ~3 lines: map
request to payload, call the service, return the `ItemDto` with `Response::HTTP_OK`. Scramble
summary PHPDoc, no `@return` generic.

#### 3. Failure mapping

**File**: `app/Exceptions/`, `bootstrap/app.php`

**Intent**: Distinguish "no such item" from "that item is not in the Inbox" — the second is the
FR-010 boundary and a client that gets them confused will retry forever.

**Contract**: A domain exception for the not-in-Inbox case mapped to
`Response::HTTP_CONFLICT` with a fixed user-facing message, following the
`ItemPersistenceException` precedent (the message is the contract the SPA renders). Missing
item yields 404.

#### 4. Feature tests

**File**: `tests/Feature/Item/ClarifyItemTest.php`

**Intent**: Prove the transition is real and observable, not just that the endpoint answers 200.

**Contract**: For at least the Delegation and Next Actions branches: POST clarify, then a
**separate** `GET /api/items?bucket=<target>` shows the item and `GET /api/items` (Inbox) does
not — the durability oracle established in the Phase 1 test rollout. Plus: clarifying an
already-clarified item returns 409 and does not move it; delegable without `delegatedTo`
returns 422; an unknown item returns 404; the domain event fires with the destination bucket
and carries no captured text.

### Success Criteria:

#### Automated Verification:

- Item feature tests pass: `php artisan test tests/Feature/Item`
- Full backend quality gate passes: `php composer.phar quality`
- Scramble export succeeds: `php artisan scramble:export`
- Transition test proven able to fail: make the repository skip the update, confirm red, revert

#### Manual Verification:

- `POST /api/items/{id}/clarify` with a tree path and with a quick-route both behave as documented

---

## Phase 3: The clarify wizard

### Overview

One question at a time in the UI, and the item leaving the Inbox when it lands.

### Changes Required:

#### 1. API client

**File**: `frontend/src/api.ts`

**Intent**: Type the clarify call against the same enum the backend validates.

**Contract**: `clarifyItem(id, answers)` returning `Item`, with an answers type mirroring
`ClarifyItemPayload`'s camelCase shape. Reuse the existing `GtdBucket` union.

#### 2. The wizard

**File**: `frontend/src/items/ClarifyDialog.tsx` (or equivalent under `frontend/src/items/`)

**Intent**: Present the fixed question order — the enforced order is what FR-003's Socrates
resolution says makes GTD correct out of the box, so the UI must not collapse it into one form.

**Contract**: Renders one question at a time with accessible controls (`getByRole`-findable),
a back step, the three-destination picker on the non-actionable branch, a `delegatedTo` field
on the delegable branch, and the FR-002 quick-route as an explicit alternative entry. Errors go
through the shared `messageFor` from `frontend/src/apiMessage.ts`; never render a raw
`err.message`.

#### 3. Wiring into the Inbox

**File**: `frontend/src/items/InboxPage.tsx`, `frontend/src/items/InboxList.tsx`

**Intent**: Start clarify from a listed item and remove it once it has left the Inbox.

**Contract**: A per-item action opens the wizard; a completed clarify removes that item from
the Inbox list by id. Follow the existing merge discipline in `InboxPage` — the list is a union
of server truth and local state, so removal must be by id, not by index.

#### 4. Component tests

**File**: `frontend/src/items/ClarifyDialog.test.tsx`

**Intent**: Pin the question order, each terminating branch, and the disappearance from the Inbox.

**Contract**: Driven by real MSW responses per the §6.3 cookbook. Assert questions appear in
the FR-003 order; assert each branch sends the answers that the backend maps to the expected
bucket; assert the item is gone from the Inbox after a successful clarify; assert a failed
clarify leaves the item in place and shows an actionable message. Every test verified by
deliberate breakage before acceptance.

### Success Criteria:

#### Automated Verification:

- Frontend tests pass: `cd frontend && npm test`
- Frontend build and lint pass: `cd frontend && npm run build && npm run lint`
- Backend suite still green: `php artisan test`
- Wizard test proven able to fail: reorder the questions, confirm red, revert

#### Manual Verification:

- Capture an idea, clarify it down each branch, confirm it appears in the right bucket via the API
- The Inbox no longer lists a clarified item after the wizard closes

---

## Testing Strategy

### Unit Tests:

- `ClarifyDecision` across the full branch matrix, expectations derived from PRD FR numbers
- No answer combination yields `GtdBucket::Inbox`
- Ill-formed payloads are rejected by throwing, not by defaulting

### Integration Tests:

- POST clarify → item readable via a separate GET of the target bucket, absent from Inbox
- 409 on an already-clarified item; 422 on delegable without `delegatedTo`; 404 on unknown item
- `item.clarified.success` fires with the destination and no captured text

### Manual Testing Steps:

1. Capture an idea, clarify as non-actionable → Reference; confirm it leaves the Inbox
2. Clarify as actionable + multi-step → Projects
3. Clarify as actionable + single-step + delegable, with a who/what note → Delegation
4. Clarify as actionable + single-step + not delegable → Next Actions
5. Use the quick-route to send an item straight to a bucket
6. Try clarifying the same item twice — the second attempt is refused with a clear message

## Migration Notes

Two additive nullable columns; no backfill, no data migration. Existing rows are unaffected —
every item is currently in the Inbox and none has Delegation data. Rollback is the inverse
migration plus reverting the slice.

## References

- Change identity and carry-forward: `context/changes/guided-clarify-routing/change.md`
- Item-layer grounding: `context/archive/2026-09-14-testing-capture-durability/research.md`
- Conventions: `app/CLAUDE.md` (block map, cross-field invariants, LogEvent), `tests/CLAUDE.md`
- Test conventions for the frontend: `context/foundation/test-plan.md` §6.3
- Accepted rules: `context/foundation/lessons.md`
- Still absent after this slice: a transaction seam on `ItemRepositoryInterface`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Domain and data

#### Automated

- [x] 1.1 Migration applies cleanly on a fresh database
- [x] 1.2 Unit tests pass
- [x] 1.3 Full backend suite passes
- [x] 1.4 Larastan level 6 clean
- [x] 1.5 Pint clean
- [x] 1.6 Branch-matrix test proven able to fail by deliberate breakage

#### Manual

- [x] 1.7 Decision table matches PRD FR-004/005/007/008 read side by side

### Phase 2: HTTP edge

#### Automated

- [ ] 2.1 Item feature tests pass
- [ ] 2.2 Full backend quality gate passes
- [ ] 2.3 Scramble export succeeds
- [ ] 2.4 Transition test proven able to fail by deliberate breakage

#### Manual

- [ ] 2.5 Tree path and quick-route both behave as documented

### Phase 3: The clarify wizard

#### Automated

- [ ] 3.1 Frontend tests pass
- [ ] 3.2 Frontend build and lint pass
- [ ] 3.3 Backend suite still green
- [ ] 3.4 Wizard test proven able to fail by deliberate breakage

#### Manual

- [ ] 3.5 Each branch routes to the right bucket, verified end to end
- [ ] 3.6 A clarified item disappears from the Inbox list
