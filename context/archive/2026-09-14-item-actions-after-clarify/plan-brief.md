# What an item can do after it has been clarified — Plan Brief

> Full plan: `context/changes/item-actions-after-clarify/plan.md`

## What & Why

Once an item leaves the Inbox it is frozen: there is no way to move it and no way to finish it
unless you happened to do the work inside a two-minute timer. This slice adds two verbs —
**refile** into another destination bucket, and **complete** — covering roadmap S-11 (FR-010,
un-parked) and S-12 (no FR at all; nothing in the PRD lets a user finish a Next Action).

## Starting Point

`ItemRepository::clarify` guards on `where bucket = inbox` in the same statement that writes,
and answers 409 on a second attempt — that guard is what closed S-02's check-then-act race, so
it cannot be loosened. Bucket views carry no per-row actions at all: S-05 reused `InboxList`
*without* `onClarify` on purpose, so a generic "change this row" verb could not arrive by the
back door. `completed_at` exists and renders as `✓ Done`, but only the timer can set it.

## Desired End State

From any of the four action buckets, a row carries a checkbox that marks the item done and
un-marks it, and a button that opens a seven-destination picker. Completed items are hidden
behind a "show completed" toggle, and checking the box says where the item went. Trash is an
ordinary bucket you can move things out of; emptying it stays the only irreversible act.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| API shape | Two business verbs, `/refile` and `/complete` | A generic `PATCH` is what `ItemRepositoryInterface:44-52` warns against, and re-opens the "client picks the bucket" hole both payload classes close | Plan |
| `clarify` | Untouched, still Inbox-only | Its guard closed a real race; refile gets its own path instead | Plan |
| Refile to Inbox | **Not allowed** | An Inbox item is unclarified by definition. Accepted cost: a mis-filed item can be corrected but not re-run through the guided tree | Plan |
| Refile shape | Direct destination pick, no tree | The quick-route step already proves the pattern; the user answered the questions once already | Plan |
| Done | A **toggle** | This product has exactly one irreversible operation, deliberately. A one-click permanent label would be the second | Plan |
| Where completing is allowed | The four action buckets only | FR-004 already split actionable from not; "done" on a Reference article means nothing | Plan |
| Trash | An ordinary bucket — items can leave | Otherwise filing to Trash becomes a second irreversible act, without even a confirmation | Plan |
| `delegation_done` | Merged into `completed_at` | Verified dead: written once as `false`, never changed, no endpoint, no UI, no test | Plan |
| Row controls | Checkbox for done, button for move | Done is a state, so a checkbox gives keyboard semantics and announcement for free | Plan |
| Completed items | Hidden behind "show completed" | **Reverses S-03's visibility decision**, so a status message on completion is mandatory compensation | Plan |
| Scope | Both verbs or neither | Half the story is half an answer | Plan |

## Scope

**In scope:** two endpoints · two repository writes guarded in one statement each · two
`GtdBucket` predicates · per-row checkbox and move button · a destination picker · a "show
completed" toggle · retiring `delegation_done` · correcting four stale FR-010 comments

**Out of scope:** touching `clarify` · a generic `PATCH /items/{id}` · refiling to the Inbox ·
re-running the tree on refile · completing outside action buckets · per-item delete · bulk
actions · undo history · a completed archive view

## Architecture / Approach

Each verb mirrors the shape `clarify` proved: FormRequest → Payload → Service → repository
method that **guards and writes in one statement**. The two rules — which buckets are legal
destinations, which allow completing — are predicates on the `GtdBucket` enum itself, read by
both the edge and the domain so a loosened rule at one cannot smuggle a value past the other.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Two verbs on the backend | Both endpoints end to end, plus retiring `delegation_done` | Reusing `clarify`'s update array would erase completions — the F9 trap |
| 2. Per-row actions | Checkbox, move button, picker, completed toggle | The first actions ever placed on a list row; three tests match row text as direct children |
| 3. Close out | Gates, browser walk, stale comments corrected | Four comments still tell the next reader FR-010 is parked |

**Prerequisites:** S-02, S-03 and S-05 shipped — they are.
**Estimated effort:** ~3 sessions, one per phase.

## Open Risks & Assumptions

- **The `completed_at` trap is the slice's whole risk.** Refile must carry an existing
  completion across and complete must not touch the bucket. Tests must assert the column each
  verb does *not* change, or a clobbering write passes.
- Hiding completed items reverses a decision S-03 made on purpose ("vanishing is
  indistinguishable from being lost"). The status message is not polish; without it this
  re-introduces the confusion that decision avoided.
- Refiling out of Trash makes the purge the only point of no return. The Trash view must not
  imply otherwise.
- `BucketPage.test.tsx:95` ("never offers a Clarify action from a destination") stays **true**
  and unchanged — clarify remains Inbox-only. Only its comment is stale.

## Success Criteria (Summary)

- A Someday/Maybe item can be moved to Next Actions when it becomes real, and a mis-filed item
  can be corrected, without re-typing it.
- A Next Action can be checked off and unchecked, and checking it says where it went.
- Nothing that was completed loses that fact by being moved.
