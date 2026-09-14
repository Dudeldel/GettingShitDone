---
change_id: guided-clarify-routing
title: Guided clarify routes an Inbox item into exactly one bucket
status: impl_reviewed
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

Roadmap slice **S-02** (`context/foundation/roadmap.md`). The GTD heart and, per the
roadmap, the deepest correctness surface in the product.

**Outcome:** the user starts guided clarify on an Inbox item, answers the decision-tree
questions one at a time, and the item lands in exactly one bucket (Trash / Someday-Maybe /
Reference / Next Actions / Delegation).

**PRD refs:** FR-002, FR-003, FR-004, FR-007, FR-008, US-01 (clarify half), and the second
PRD guardrail — *"clarify never leaves an item without a bucket"*.

**Prerequisites:** S-01 (done), F-03 (done).

**Deliberately out of scope** — split into their own slices to keep this one single-axis:

- the 2-minute timer branch → S-03 (`two-minute-rule-timer`, FR-006)
- promoting a multi-step item to a Project → S-04 (`promote-to-project`, FR-005)

**Open unknown to confirm before planning:** Delegation is a free-text who/what note plus a
done flag (FR-007) — confirm it is modelled as *item fields*, not a contact entity. Root
`CLAUDE.md` states this explicitly ("**Not** a user/contact entity"), so this is a
verification, not a decision.

**Architectural direction from the roadmap and `app/CLAUDE.md`:** the decision tree, the
routing and the exactly-one-bucket invariant belong in a **Domain Entity** (pure PHP, no
Eloquent, no framework) — never in a controller or model. Growing branch variants become a
Strategy per branch plus a Factory. The invariant should be enforced at the domain layer
*and* backed at the data layer.

**Carry-forward from S-01 (`context/archive/2026-09-14-testing-capture-durability/` and
`context/archive/2026-06-25-capture-to-inbox/`):**

- `ItemRepositoryInterface` has only `create()` and `listByBucket()`. Clarify is the first
  write path that *changes* an existing row, so it needs a new repository method — and there
  is currently **no transaction seam anywhere in the item path** (no `DB::transaction`, no
  `transaction(callable)` on the interface). If clarify becomes more than one write, that
  gap becomes load-bearing.
- `bucket` is fillable on the `Item` model. Capture's "always Inbox" invariant rests on
  `CaptureItemPayload` having no bucket field plus `ItemService` passing the enum. Clarify
  will be the first code allowed to set a bucket from user input — the guard that protects
  capture must not be weakened to make clarify convenient.
- `GtdBucket::default()` is Inbox; the enum is persisted as a plain indexed string, not a
  native DB enum, because SQLite (the whole test suite) would not enforce one. A data-layer
  guarantee for FR-008 therefore cannot rely on a column type.
- `LogEvent` already has the `item.captured.*` pattern; `app/CLAUDE.md` names
  `LogEvent::itemClarified()` as the expected domain event for this slice.
- `context/foundation/lessons.md` carries one accepted rule: *a test is not coverage until
  deliberate breakage has reddened it.* It applies to every test this slice adds.
- Test-plan Phase 5 (`Clarify routing invariant`, Risk #3) is explicitly blocked until this
  slice ships, and warns against the oracle problem: derive the expected bucket from PRD
  FR-004/005/006/007, never by reading the routing code.
