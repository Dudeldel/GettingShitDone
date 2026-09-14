# Guided clarify routing — Plan Brief

> Full plan: `context/changes/guided-clarify-routing/plan.md`

## What & Why

Roadmap slice S-02: the user opens an Inbox item, answers the GTD decision tree one question
at a time, and the item lands in exactly one bucket. This is the PRD's second guardrail —
*"clarify never leaves an item without a bucket"* — and the half of the product that turns a
capture list into a GTD app.

## Starting Point

Capture works: sign in, type an idea, see it in the Inbox in ~2s, backed by 100 tests and a
blocking CI gate. Clarify does not exist at all — no code, no route, no domain class. The
repository can only `create()` and `listByBucket()`, so clarify is also the first write path
that modifies an existing row.

## Desired End State

From the Inbox, a per-item action opens a wizard. The user answers up to three questions and
the item leaves the Inbox for Trash, Someday/Maybe, Reference, Projects, Delegation or Next
Actions. Every answer path terminates. A clarified item cannot be clarified again.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Session shape | Stateless — client walks the tree, server derives the bucket | No session store, no expiry, no Octane worker-state concern; and the client never names a destination, preserving the guard that protects capture | Plan |
| Scope of the tree | Full tree **minus** the `< 2 min?` question | Every remaining path terminates, so FR-008 becomes provable now; S-03 inserts that question later | Plan |
| S-04 | Absorbed | Its own roadmap entry says a Project is just a destination bucket, and `GtdBucket::Projects` already exists — one enum value | Plan |
| Delegation model | Two nullable columns on `items` | Matches the five dormant columns already there; PRD FR-007 says free text, explicitly *not* user accounts | Plan |
| Routing logic | Domain Entity, pure PHP | `app/CLAUDE.md` puts the decision tree in a Domain Entity; Strategy + Factory is for *many algorithm variants*, and one fixed tree is not that | Plan |
| Quick-route | In scope, as its own answer mode | FR-002's Socrates resolution includes it; keeping it distinct from the tree path stops it becoming a general "client picks the bucket" hole | Plan |
| Transaction seam | Not introduced | This slice performs one UPDATE; the absent seam is recorded rather than built speculatively | Plan |

## Scope

**In scope:** the decision tree as a pure-PHP entity; two Delegation columns; the first
repository write path; `POST /api/items/{item}/clarify`; the 409 guard against re-clarifying;
the FR-002 quick-route; the wizard UI; unit tests across the full branch matrix and feature
tests proving the transition is observable.

**Out of scope:** the `< 2 min?` question and the timer (S-03); project hierarchy (FR-012,
parked); re-filing already-bucketed items (FR-010, v2); any contact/user entity for Delegation;
server-side clarify sessions; item edit and delete (absent from the PRD entirely); any change
to the capture path.

## Architecture / Approach

```
ClarifyDialog (wizard, one question at a time)
  → POST /api/items/{id}/clarify  { answers, never a bucket }
      → ClarifyItemRequest        cross-field rules via Laravel built-ins
      → ClarifyController         ~3 lines
      → ItemService::clarify      orchestration, LogEvent::itemClarified
      → ClarifyDecision           pure PHP: answers → exactly one GtdBucket
      → ItemRepository            single UPDATE, Inbox-only guard
```

The client submits answers; the server derives the destination. That mirrors capture, where
the client submits text and the server applies the bucket.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Domain and data | Decision tree, Delegation columns, first update path, branch-matrix unit tests | The oracle problem — expectations must come from the PRD, not from reading the tree code |
| 2. HTTP edge | Endpoint, cross-field validation, 404/409/422 mapping, transition feature tests | Letting the quick-route's bucket field leak into the tree path and recreate a client-chooses-destination hole |
| 3. Clarify wizard | One-question-at-a-time UI, Inbox removal, component tests | Collapsing the enforced question order into one form, which FR-003 says is the thing that makes GTD correct |

**Prerequisites:** S-01 (done), F-03 (done). Toolchain is warm — `vendor/`, `node_modules`,
`.env` all present.
**Estimated effort:** ~2.5–3.5 h across 3 phases.

## Open Risks & Assumptions

- **S-04 must be closed in the roadmap as absorbed**, or it will sit `proposed` forever
  describing work this slice already did.
- **No transaction seam** on the item path. Harmless for one UPDATE; becomes load-bearing the
  moment clarify grows a second write.
- **The wizard's state is in React**, so a page refresh mid-tree loses the answers. Accepted:
  no row is written until the final submit, so nothing is half-clarified.
- **`delegation_done` gets a column but no UI** in this slice — nothing yet lists the Delegation
  bucket or marks a waiting-for as done. That surface arrives with S-05.

## Success Criteria (Summary)

- Every path through the tree ends with the item in exactly one bucket, and never in the Inbox.
- A clarified item is readable in its target bucket through a separate request, and gone from
  the Inbox.
- Clarifying the same item twice is refused with a message the user can act on.
