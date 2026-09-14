<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Item Attributes & the Derived Calendar View

- **Plan**: `context/changes/item-metadata-and-calendar/plan.md`
- **Scope**: Full plan — Phases 1–4 of 4
- **Date**: 2026-09-14
- **Commits reviewed**: `7b17cb6` (p1) · `962f10c` (p2) · `ca7b606` (p3) · `c892f56` (p4) · `2852a06` (epilogue)
- **Verdict**: NEEDS ATTENTION → **RESOLVED** after triage (F1 fixed; F2/F4/F5 accepted; F3 fixed)
- **Findings**: 1 critical · 2 warnings · 2 observations

> Scope note: `75c5333` ("docs(deploy): record that the tracked Railway branch is gone") landed
> mid-session and is not part of this change; it was excluded from the review range.

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | FAIL → PASS after F1 fix |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

**Success criteria re-run at review time:** Pest 211/211 (696 assertions) · Larastan level 6, 0
errors · Pint clean · migration applies and rolls back · Vitest 145/145 (144 at review time, plus F1's regression test) · `npm run build` clean ·
`npm run lint` clean. All 34 Progress rows are `[x]` and carry a commit SHA.

**Plan adherence detail:** every one of the five invariants the plan called out was verified in the
final code — the attributes write touches exactly five columns plus `updated_at` and never
`bucket`; full-replacement semantics hold at both the FormRequest and the dialog; the Calendar
ordering leads with the computed null-rank; the action-bucket set is derived from `GtdBucket`
rather than hardcoded; and `important`/`urgent` remain two independent nullable booleans. Every
item on the plan's "What We're NOT Doing" list is respected, including `#[Fillable]` being
unchanged and Calendar remaining a quick-route and refile destination.

## Findings

### F1 — Stale dialog state silently writes one item's attributes onto another

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; the fix is one prop at two call sites
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/AttributesDialog.tsx:27-31`, triggered from
  `frontend/src/items/BucketPage.tsx:292-294` and `frontend/src/items/InboxPage.tsx:60-69`
- **Detail**: The dialog seeds all five fields from the `item` prop with `useState(...)`, which
  only runs on mount. Neither call site passes a `key`, and both leave every row's Edit button
  live while the dialog is open. Clicking Edit on a second item therefore reuses the same
  component instance: React re-renders it with the new `item`, so the heading changes to the new
  title, but the form fields keep the first item's values. Saving then posts the **first** item's
  attributes to the **second** item's id — silent cross-item data corruption, with no error and
  nothing on screen suggesting anything is wrong.

  Reproduced during review with a throwaway test against the real `BucketPage`: seed two Next
  Actions items with contexts `@AAA` and `@BBB`, click Edit on A, then Edit on B without closing.
  The heading reads "item B" while the Context field still reads `@AAA`:

  ```
  Expected the element to have value: @BBB
  Received:                           @AAA
  ```

  The sibling `RefileDialog` does not have this class of bug because it derives no state from
  `item` — the defect is specific to the component this slice introduced.

- **Fix**: Pass `key={editing.id}` on `<AttributesDialog>` at both call sites so switching items
  remounts it, and add a regression test that drives the A→B switch through the real page.
  - Strength: One prop per call site; `key` is React's documented remount signal and needs no
    `useEffect` sync that could re-fire on unrelated re-renders.
  - Tradeoff: Does not address the broader question of whether other rows' triggers should be
    inert while a dialog is open — that stays as-is, matching `RefileDialog`'s existing behaviour.
  - Confidence: HIGH — reproduced red, and the mechanism is plain React reconciliation.
  - Blind spot: `RefileDialog` shares the no-`key` pattern; it is safe today only because it holds
    no derived state, which is a property nobody has written down.
- **Decision**: FIXED — `key={editing.id}` added at both call sites, with a regression test in
  `frontend/src/items/BucketPage.test.tsx` driving the A→B switch through the real page. The test
  was verified by deliberate breakage: removing the `key` reddens it, restoring it turns it green.

### F2 — Product-roadmap decisions bundled into the phase-1 code commit

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — no code change; the record is what is at stake
- **Dimension**: Scope Discipline
- **Location**: `7b17cb6` — `context/foundation/roadmap.md`, `context/foundation/test-plan.md`
- **Detail**: The plan authorized exactly one roadmap edit: correcting the false claim that
  `ClarifyConst::DATE_FORMAT` was "already present". The commit also carries S-08 (FR-014,
  Eisenhower) and S-09 (FR-015, weekly review) being **parked** across seven places, annotated
  "Parks PRD must-have scope", plus a ~60-line status audit in `test-plan.md` closing out an
  unrelated rollout phase.

  These edits were already in the working tree when implementation began. The implementer saw
  `roadmap.md` was dirty, reasoned that it must be its own plan-phase status flip, and folded it
  in — verifying *why* the file might be dirty without checking *what* was in it. The
  `ROADMAP_PREDIRTY` guard in the implement workflow exists precisely to force that check.
  `test-plan.md` was surfaced and explicitly approved for inclusion; the roadmap content was not
  surfaced at all.

  Consequence for the record: the decision to park two PRD must-have requirements is now
  recoverable only by reading a commit titled "the attributes write path".
- **Fix**: None to the code. The user has confirmed the content was intended and should stand.
- **Decision**: ACCEPTED — the user authorized this content and chose to keep it as committed
  ("Ja zezwoliłem, tak miało być. Niech tak zostanie"). Recorded here so the provenance of the
  FR-014/FR-015 parking decision is findable without archaeology.

### F3 — Three necessary fixes landed without appearing in the plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — the code is right; this is about the plan as a record
- **Dimension**: Scope Discipline
- **Location**: `frontend/src/items/BucketPage.tsx:158,252`, `frontend/src/items/buckets.ts:52-68`
- **Detail**: Three changes were made that the plan's "Changes Required" never described:
  `RefileDialog` now receives `currentBucket={refiling.bucket}` instead of the page's bucket;
  `handleRefiled` keeps a re-filed row visible when it still qualifies for the Calendar view; and
  a new `showsOnCalendar()` helper mirrors the server predicate client-side.

  All three are correct and each has its own test and its own deliberate-breakage verification.
  They are genuine consequences of Phase 2's derived view — before it, the page's bucket and the
  item's bucket were always the same value, so the distinction did not exist. They were reported
  at the time rather than slipped in. The finding is only that the plan document was never
  amended, so a reader reconstructing the slice from the plan alone would not find them.
- **Fix**: Add a short addendum to the plan's Phase 3 block naming these three, so the plan
  matches what shipped.
- **Decision**: FIXED — Phase 3 of the plan now carries a "#### 4. Integration fixes the derived
  view made necessary (added during implementation)" entry describing all three and why Phase 2
  made them necessary.

### F4 — Test fixture extended per-test rather than in the shared factory

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/test/server.ts` (unchanged)
- **Detail**: Phase 3 planned that "`makeItem` gains realistic non-null values in the fixtures that
  need them". The file was not touched; each test passes its own `overrides` instead. That is
  arguably better and matches the file's own documented convention ("each test declares only its
  own deviation"), but it is a deviation from the plan text.
- **Fix**: None recommended — the implementation is the better of the two.
- **Decision**: ACCEPTED — kept as implemented. Per-test overrides match the fixture file's own
  documented convention ("each test declares only its own deviation"), which makes a test's
  intent readable at the test rather than in a shared default.

### F5 — One CSS selector in a frontend test

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Pattern Consistency
- **Location**: `frontend/src/items/InboxList.test.tsx:73`
- **Detail**: `.querySelector('time')` is used to read the `datetime` attribute off a `<time>`
  element, scoped inside a node already located by `getByText`. The project rule is
  role/label/text locators, never CSS. There is no accessible-role path to a `<time>` element, so
  the alternative is a `getByTestId` — which the same rule discourages more strongly. Reported so
  the exception is a decision rather than an oversight.
- **Fix**: None recommended — leave it, with the rationale now recorded.
- **Decision**: ACCEPTED — kept as implemented. There is no accessible-role path to a `<time>`
  element, and the alternative the rule discourages more strongly is `getByTestId`. The selector
  is scoped inside an already-text-located node, not a page-wide lookup.

## Verified and clean

Checked and found correct, recorded so a later review need not re-derive them:

- **Injection**: `orderByRaw('due_date IS NULL')` is a hardcoded literal with no interpolation.
- **Authz**: the new route sits inside the existing `auth:sanctum` group.
- **Log redaction**: the new events carry only `item_id` and a SQLSTATE; two tests plant a
  PIN-like string and assert it never reaches the log context.
- **Mass assignment**: the write is a fixed column list through the query builder, so no request
  array can widen it; `#[Fillable]` is unchanged.
- **Concurrency**: guard-and-write in one statement with failure-path disambiguation, identical in
  shape to `clarify`/`refile`/`setCompleted`; `ATTR_FOUND_ROWS` already makes `$affected === 0`
  mean the same on MySQL as on SQLite.
- **Migration**: additive index, reversible, verified by an actual rollback and re-apply.
- **`isset()` on the flags**: `false` survives (`isset` is true for `false`), `null` becomes null —
  correct under full-replacement semantics, and the distinction S-08 depends on is preserved.
- **Tag normalization**: null-dropping, case-insensitive dedupe and list re-packing all behave as
  specified, including the deliberate passthrough of non-null non-strings so validation can reject.
- **Performance**: the Calendar OR-predicate will most likely filesort despite the new index; at
  single-user scale this is immaterial, and the migration comment already says so rather than
  overclaiming.
