---
change_id: two-minute-rule-timer
title: 2-minute rule timer in clarify
status: archived
created: 2026-09-14
updated: 2026-09-14
archived_at: 2026-09-14T13:15:00Z
---

## Notes

Roadmap S-03. FR-006 + the US-01 acceptance criterion: a "< 2 min" item triggers a
2-minute timer; completion marks it done, "need more time" loops the timer.

### The unknown the roadmap recorded as "—"

`ClarifyDecision`'s own docblock names it: **there is no Done bucket among the eight.**
FR-008 says every clarified item lands in exactly one bucket, so "marks it done" has
nowhere to put the item unless something is decided here.

**Decision: done is a STATE, not a destination.** A completed 2-minute item lands in
**Next Actions** with `completed_at` set.

Why this and not the alternatives:

- *A ninth "Done" bucket* contradicts FR-009 and the `GtdBucket` enum the whole product
  is built on; the eight are the product's promise, not an implementation detail.
- *Deleting the item* is the one thing the guardrail "capture never loses an entry"
  exists to prevent, and it makes the act of doing work indistinguishable from losing it.
- *Trash* means "discarded", which is the opposite claim about a thing you just did.
- Next Actions is **what the item already was** — actionable, single-step, not delegated.
  Doing it immediately changes its state, not its classification. The PRD's own wording
  ("completion marks it done") is a state verb, and FR-007 already established the
  precedent of a done flag living on the item rather than in a bucket.

Completed items stay **visible** in Next Actions, marked done — not filtered out. An item
vanishing the instant the user finishes it is indistinguishable from an item that was
lost, which is exactly the confusion S-01's guardrail is about.
