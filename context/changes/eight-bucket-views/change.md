---
change_id: eight-bucket-views
title: View all 8 GTD buckets, and empty the Trash
status: implementing
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

Roadmap slice **S-05**. Generalizes the Inbox list from S-01 into navigation across all eight
buckets, and adds the product's first and only destructive write.

**Outcome:** the user opens and views the items in each of the 8 GTD buckets (Inbox, Next
Actions, Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, Trash), and can
permanently discard what sits in the Trash.

**PRD refs:** FR-009. The purge carries no FR of its own — it completes the Trash destination
FR-004 already offers.

**Prerequisites:** S-01 (done). Also lands after S-02, so clarify finally has a visible effect.

**The hard boundary, from the roadmap's own Risk note:** the purge must be reachable ONLY from
the Trash view, never as a generic "delete item" verb hanging off every bucket. That is what
keeps it inside FR-004's semantics instead of quietly shipping FR-010 (re-filing between
buckets), which is parked for v2.

**Open unknown to settle in planning:** purge granularity — empty the whole Trash in one
action, discard one item at a time from the Trash view, or both.

**Carry-forward:**

- **The read side is already built.** `GET /api/items?bucket=<value>` has accepted all eight
  `GtdBucket` values since S-01; `ListItemsRequest` validates with `Rule::enum` and falls back
  to `GtdBucket::default()`. No new read endpoint is needed — only navigation.
- **`listByBucket` is unbounded** — no limit, no pagination, deliberately deferred per PRD Open
  Question #2. Reference and Trash are append-only in GTD, so they are the two buckets where
  that deferral will bite first.
- **`InboxList` already takes an optional `onClarify`** and was built so bucket views can omit
  it: you do not re-clarify from a destination (FR-010, v2).
- **Delegation items carry `delegatedTo` / `delegationDone`** (S-02) with no UI yet. The
  Delegation view is where the who/what note first becomes visible; the done flag has no write
  path and stays out of scope unless planning says otherwise.
- **`context/foundation/lessons.md`**: a test is not coverage until deliberate breakage has
  reddened it. The S-02 review found 12 of 31 mutations surviving — apply the rule from the
  start here, especially to the destructive path.
