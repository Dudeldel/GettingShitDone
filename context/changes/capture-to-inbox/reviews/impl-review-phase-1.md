<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Capture an Idea into the Inbox (S-01)

- **Plan**: `context/changes/capture-to-inbox/plan.md`
- **Scope**: Phase 1 of 4 — "Item domain spine"
- **Commit**: `37ba633`
- **Date**: 2026-09-07
- **Verdict**: REJECTED
- **Findings**: 1 critical, 3 warnings, 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | WARNING |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

All six Phase 1 automated criteria were re-run independently and pass (`migrate:fresh`,
`test tests/Unit` 18/18, `ItemRepositoryTest` 6/6, Larastan level 6 zero errors, Pint
clean, full suite 39/39 — no regression in the auth or logging slices). The findings
below are things the gates do not catch.

## Findings

### F1 — Sanitizer silently deletes the entire input on one malformed byte

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `app/Filters/FreeTextSanitizer.php:18`
- **Detail**: `preg_replace('/…/u', …)` returns `null` when the *subject* is not valid
  UTF-8 (`PREG_BAD_UTF8_ERROR`), and the `(string)` cast turns that `null` into `''`.
  Verified in this environment: `FreeTextSanitizer::sanitize("cafe\xE9\x00 idea")`
  returns `string(0) ""` — the whole title is destroyed, no exception, no log. Without
  the `/u` modifier the same input correctly yields `"cafe\xE9 idea"`. The character
  class is pure ASCII, so `/u` buys nothing. This directly contradicts the PRD guardrail
  "capture never loses an entry"; it is latent only because Phase 2 has not wired the
  class to `CaptureItemRequest` yet.
- **Fix**: Drop the `/u` modifier (byte-wise replace is correct here and cannot fail), and
  add a regression test with an invalid-UTF-8 subject. Never cast a `preg_replace` result
  to string without handling `null`.
- **Decision**: FIXED — dropped the /u modifier, return the original on PCRE failure, regression test added

### F2 — Timestamps dereferenced unconditionally on nullable columns

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Infrastructure/Item/ItemRepository.php:43-44`
- **Detail**: `$item->created_at->toIso8601String()` and the `updated_at` line dereference
  without a null check, while `due_date` on the next line correctly uses `?->`. Confirmed
  nullable in the shipped schema (`php artisan db:table items` reports
  `created_at datetime, nullable`) — `$table->timestamps()` creates nullable columns.
  Larastan is satisfied only because `app/Models/Item.php:25-26` *declares* them
  non-null; that is an assertion, not a guarantee. Any row written without timestamps
  (raw `DB::table()->insert()`, a seeder, an import, `withoutTimestamps()`) makes every
  subsequent list request throw.
- **Fix A ⭐ Recommended**: Make the columns non-nullable in the migration.
  - Strength: Matches the `createdAt: string` (non-null) contract the DTO already
    declares, and fails fast at write time instead of at read time for every later list.
  - Tradeoff: Deviates from Laravel's nullable-by-default `timestamps()`; a raw insert
    that omits them now errors instead of silently storing null.
  - Confidence: HIGH — pre-production, no rows to migrate.
  - Blind spot: Have not audited whether any future import path intends to backdate rows.
- **Fix B**: Use `?->toIso8601String() ?? <fallback>` in `toDto`.
  - Strength: Local, one-line, no schema change.
  - Tradeoff: Invents a fallback value for data that should not exist; hides the bad row
    rather than preventing it.
  - Confidence: HIGH.
  - Blind spot: Choice of fallback is arbitrary and would leak into the API.
- **Decision**: FIXED via Fix B — nullsafe access with an empty-string fallback; model docblock corrected to Carbon|null

### F3 — Ordering test does not guard the primary sort key

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `tests/Feature/Item/ItemRepositoryTest.php:52-60`
- **Detail**: "lists the newest item first" creates both rows inside the same second, so
  it only exercises the `id desc` tiebreaker. Verified by deliberate breakage: deleting
  `->orderByDesc('created_at')` from `ItemRepository::listByBucket` leaves all 6 tests
  green. The contracted `created_at desc` half of the ordering is unprotected, and
  criterion 1.3 passes without covering it.
- **Fix**: Add a case that backdates one row's `created_at` (e.g. `forceFill` or
  `Carbon::setTestNow`) so the primary key decides the order, then re-run the deliberate
  breakage to confirm it now fails.
- **Decision**: FIXED — added a backdating case; re-verified by deliberate breakage (the test now fails when created_at ordering is removed)

### F4 — `listByBucket` is unbounded and the interface locks that shape

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: `app/Domain/Item/ItemRepositoryInterface.php:23`, `app/Infrastructure/Item/ItemRepository.php:25-34`
- **Detail**: No limit, no cursor, no pagination — every row in a bucket is hydrated into
  a Model and then a DTO. The interface hard-codes `Collection<int, ItemDto>`, and Phase 2's
  endpoint plus S-05's eight bucket views will be written against that signature. Reference
  and Trash are append-only in GTD and grow without bound.
- **Fix A ⭐ Recommended**: Accept for the MVP and record the deferral in
  `docs/reference/contract-surfaces.md` alongside the interface entry.
  - Strength: The PRD already made this call — "List-view responsiveness target" is Open
    Question #2, explicitly *not* committed for MVP, revisit if task volume grows. Single
    user, low volume. Adding pagination now is speculative work the PRD declined.
  - Tradeoff: A later retrofit changes the interface signature and its callers.
  - Confidence: HIGH — the deferral is written down in the PRD, not assumed.
  - Blind spot: Cost of the retrofit scales with how many slices consume the method first.
- **Fix B**: Add `int $limit` (or a cursor) to the interface now, before Phase 2 depends on it.
  - Strength: Cheapest moment to change the contract — two call sites exist.
  - Tradeoff: Builds a knob with no consumer and no UI for it; contradicts the plan's own
    "What We're NOT Doing" discipline.
  - Confidence: MEDIUM — depends on whether S-05 would have needed paging anyway.
  - Blind spot: No measurement of actual list sizes exists.
- **Decision**: FIXED via Fix A — deferral recorded in the plan's Phase 4 contract-surfaces entry, citing PRD Open Question #2

### F5 — Single-column index forces a filesort on the only query

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `database/migrations/2026_09_07_100000_create_items_table.php:19`
- **Detail**: The index is on `bucket` alone, but the only query is
  `where bucket = ? order by created_at desc, id desc`. MySQL uses the index for the
  filter and then filesorts. A composite `(bucket, created_at, id)` serves both.
- **Fix**: `$table->index(['bucket', 'created_at', 'id'])` instead of the single-column index.
- **Decision**: FIXED — replaced with a composite index (bucket, created_at, id); verified as `items_bucket_created_at_id_index … compound`

### F6 — The five dormant mapping lines in `toDto` have zero coverage

- **Severity**: 💡 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: `app/Infrastructure/Item/ItemRepository.php:45-49`
- **Detail**: Nothing writes `due_date`, `tags`, `context`, `important` or `urgent`, so the
  tests only ever assert they are `null`. The `'tags' => 'array'` cast returns whatever
  `json_decode(…, true)` produces — a JSON object yields a string-keyed array, a scalar
  yields a scalar — neither matches the declared `array<int, string>|null` on `ItemDto`,
  and nothing coerces or validates it. S-06/S-07 will be the first code to discover this.
- **Fix**: Add one Feature test that seeds a row with all five columns populated (via
  `forceFill`) and asserts the resulting DTO shape, so the mapping and the json cast are
  exercised before the slices that own those fields arrive.
- **Decision**: SKIPPED — S-06/S-07 will cover the dormant mapping together with their own validation

### F7 — `ItemConst` has no consumer and duplicates the column width

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Const/ItemConst.php:12`, migration line 13
- **Detail**: `grep -rn ItemConst app/ tests/ database/` returns nothing outside the class
  itself. `TITLE_MAX_LENGTH = 255` restates the implicit default of `$table->string('title')`
  — exactly the drift `app/CLAUDE.md` says constants exist to prevent. The constants become
  live in Phase 2 when the FormRequest arrives, but the migration will still not use one.
- **Fix**: `$table->string('title', ItemConst::TITLE_MAX_LENGTH)` in the migration, tying
  schema and validation to one source.
- **Decision**: FIXED — migration now uses $table->string('title', ItemConst::TITLE_MAX_LENGTH)

### F8 — DTO annotations drift from the plan's written contract

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `app/Dto/ItemDto.php:27`, `:61`, `:81`
- **Detail**: Two intent-preserving deviations. (a) The `bucket` constructor property is
  typed `GtdBucket`, where the plan's contract line says `bucket: string`; the serialized
  shape is still a string (`$this->bucket->value`) so the API contract holds, but Phase 2/3
  callers must pass and read an enum. (b) `tags` is annotated `array<int, string>|null`
  where the plan specified `list<string>|null` — the weaker shape, which permits
  non-sequential keys.
- **Fix**: Keep the stronger enum typing and amend the plan's contract line to match;
  tighten the `tags` annotation to `list<string>|null`.
- **Decision**: FIXED — enum typing kept and the plan contract amended to match; tags tightened to list<string>|null and coerced with array_values() at the repository seam so the annotation is true, not asserted

### F9 — `app/CLAUDE.md` carries the same sanitizer bug and contradicts itself on DTO casing

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/CLAUDE.md` — "Input sanitization in `prepareForValidation()`" and
  "Rules often broken on day one"
- **Detail**: The worked `FreeTextSanitizer` example in the conventions file is the exact
  code from F1, `/u` modifier included — the next slice that copies it inherits the bug.
  Separately, the file says both "`fromArray` … takes **camelCase** keys" and
  "DTO-from-request: … snake_case in (from validation)". `ItemDto` and `UserDto` both use
  camelCase, so the snake_case bullet is the stale one.
- **Fix**: Correct the regex in the doc example when F1 is fixed, and delete the
  contradictory snake_case clause.
- **Decision**: FIXED — both: the worked sanitizer example no longer carries the /u bug, and the stale snake_case clause is gone

### F10 — `tests/CLAUDE.md` taxonomy does not describe a repository integration test

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/CLAUDE.md`, `tests/Feature/Item/ItemRepositoryTest.php`
- **Detail**: The doc defines Feature as "endpoints end-to-end" and Unit as "isolated
  classes… no DB". A repository test is neither; it lives in `Feature/` only because
  `tests/Pest.php:10` applies `RefreshDatabase` there. "Feature" now means two things.
- **Fix**: Amend `tests/CLAUDE.md` to define Feature as "anything needing the app + DB"
  (which is what the `Pest.php` comment already says).
- **Decision**: FIXED — Feature redefined as "anything needing the booted app and a database"

## Carry into Phase 2 (not a finding)

`bucket` is fillable on the model. That is correct for the repository's explicit
`create(['bucket' => $bucket])`, but it means a future
`Item::query()->create($request->validated())` would let a client choose its own bucket and
bypass the "capture always targets Inbox" invariant. Phase 2 must keep passing the bucket
as a separate enum argument, as planned.
