# Item Attributes & the Derived Calendar View — Plan Brief

> Full plan: `context/changes/item-metadata-and-calendar/plan.md`
> Research: `context/changes/item-metadata-and-calendar/research.md`

## What & Why

Give an item a due date, tags, a context and the important/urgent flags — and make a dated item
show up in the Calendar/Dates view without ever changing which bucket it lives in. This is S-06
(FR-011 + FR-013, S-07 absorbed), the first write path for five columns that have been dormant
since S-01, and the product's first edit-an-item surface.

## Starting Point

The five columns exist and are cast, but are excluded from `#[Fillable]`. The **read half is
already done** — `ItemDto` carries all five and round-trips them with real values, the repository
reads them, and the SPA's `Item` type declares them. Nothing writes them and nothing renders them.

Critically, **Calendar/Dates is not an empty bucket**. It is already a labelled quick-route button
in the clarify dialog, a legal refile target, and an action bucket whose items can be completed —
all pinned by tests. So this slice adds *dates* to a bucket that already carries its own meaning.

## Desired End State

A user opens any item outside the Trash, sets a date, tags, a context and the two flags, and saves.
The item keeps its bucket. If it carries a date and is an actionable commitment — or was filed to
Calendar by hand — it appears in the Calendar view, dated items in chronological order with undated
hand-filed items below them. A dated Next Action appears in Calendar *and* stays in Next Actions.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Date vs. bucket | A date is a pure attribute; never rewrites `bucket` | Matches the settled "done is a state, not a destination" precedent and keeps FR-011's "a date is a field on any task" true | Research |
| What Calendar shows | Scoped union: filed-there **or** dated-and-actionable | Delivers both halves of FR-011; FR-008 was already ruled to constrain the column, not the view | Research |
| Ordering of undated items | Dated first, undated last (explicit null-rank) | Both SQLite and MySQL sort NULL lowest in ASC, so the naive query opens a date-ordered list with an undated item | Plan |
| Completed dated items | Included; hidden by the existing "Show completed" toggle | No new rule — Calendar behaves like the other seven buckets | Plan |
| Editable from | Every bucket except Trash | Dates and tags on Reference or Someday-Maybe are legitimate GTD; FR-013 says "an item", not "an actionable item" | Plan |
| Endpoint shape | One `POST /items/{id}/attributes` | The recorded hole is "client chooses where an item lands" — this verb structurally cannot | Plan |
| Write semantics | Full replacement; `null` clears | No absent-vs-null ambiguity, and clearing a date works without a second verb | Plan |
| Tags | Normalized in: trim, drop empty, case-insensitive dedupe, capped | Closes S-01's F6, which named this slice as the one that would hit it | Plan |
| Index | Plain index on `due_date`, honestly documented | Serves the filter branch; not claimed to remove the filesort under an `OR` | Plan |
| Edit surface | Inline panel modelled on `RefileDialog` | Copies a pattern that already survived review including focus management | Plan |
| Error display | Per-field with `aria-invalid` | `.field` already styles `[aria-invalid='true']`; one generic message across five fields tells the user nothing | Plan |
| Flags encoding | Two independent nullable booleans, tri-state in the UI | S-08 derives four quadrants from them; "not yet judged" must stay distinct from "not important" | Research |

## Scope

**In scope:** the five columns' write path; tag normalization; new domain constants; the derived
Calendar query and its index; attribute rendering on the item row; the Calendar view's presentation;
the edit panel with field-level errors; backend and frontend tests.

**Out of scope:** a generic PATCH; any change to `#[Fillable]`; date-driven re-bucketing; removing
Calendar as a quick-route/refile destination; the Eisenhower view (S-08); pagination; tag vocabulary
management; a month-grid calendar UI; reminders.

## Architecture / Approach

Backend in two independently verifiable halves, then frontend read before write.

```
POST /items/{id}/attributes
  → UpdateItemAttributesRequest  (validate + sanitize + normalize tags)
  → ItemAttributesPayload        (complete new attribute state)
  → ItemService::updateAttributes
  → ItemRepository::updateAttributes   guard: bucket != trash
                                       writes: the 5 columns + updated_at, nothing else

GET /api/items?bucket=calendar   (contract UNCHANGED)
  → ItemService::listByBucket      dispatches Calendar →
  → ItemRepository::listCalendar   bucket = calendar OR (due_date NOT NULL AND action bucket)
                                   ordered: dated first, by date, then created_at desc, id desc
```

Writes use query-builder `update()`, which bypasses mass-assignment — so the dormant columns stay
unreachable from any request array while still being writable by this one explicit verb.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Attributes write path | The endpoint, constants, tag normalization, logging | A write that clobbers a sibling column (S-11's F2 was exactly this) |
| 2. Derived Calendar view | The union query, the index, service dispatch | NULL ordering differing from intent; an ordering test that passes for the wrong reason |
| 3. Rendering attributes | Row shows all five; Calendar explains itself | Calendar reads as a bug when most rows aren't "in" that bucket |
| 4. Edit panel | The form, field-level errors, wiring | Tri-state flags collapsing to a checkbox, losing "not yet judged" |

**Prerequisites:** none beyond what is shipped — S-01 and S-05 are both done.
**Estimated effort:** ~4 sessions, one per phase.

## Open Risks & Assumptions

- The Calendar view is the codebase's **first derived query**; S-08 and S-09 will follow whatever
  shape it sets. Getting the repository-method boundary right matters beyond this slice.
- Field-level errors are a new convention the three existing forms won't follow until retrofitted —
  a deliberate, temporary inconsistency.
- Tag dedupe is case-insensitive, so typing `Work` when `work` exists silently keeps the first
  casing. Defensible, but it is a silent transformation.
- An unclarified Inbox item becomes editable. Judged correct (tagging before clarifying is real
  GTD), but it is a new capability rather than a neutral consequence.
- `research.md` claimed `CaptureItemTest`'s dormancy test "will need deliberate revision". It will
  not — that test keeps guarding that *capture* ignores metadata, which stays true. The plan
  extends it instead. Corrected in the plan's Key Discoveries.

## Success Criteria (Summary)

- A user can set and clear all five attributes on any item outside the Trash, and see them on the row
- A dated Next Action appears in Calendar **and** remains in Next Actions, with its stored bucket
  provably unchanged
- Everything already shipped still works: the Calendar quick-route button, refile-to-Calendar, and
  completion in Calendar, with their existing tests untouched and green
