---
project: "Getting Shit Done"
version: 1
status: draft
created: 2026-06-15
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: null
  after_hours_only: true
---

# Getting Shit Done (GSD) — Product Requirements

## Vision & Problem Statement

Doing GTD properly requires either a config-heavy tool (OmniFocus, Todoist,
Things) that has to be set up before it works, or constant manual discipline to
capture and clarify items. The friction is highest exactly when ideas arrive —
driving, walking, mid-task — moments where stopping to type, let alone configure
a workflow, is impossible. For someone with ADHD-style friction, every
configuration decision is a threshold to trip over, and the best ideas are lost
because the bar to capture them is too high in the moment they appear.

Three thresholds kill GTD adoption — setup friction, capture friction, and
clarify friction — and existing tools only partially address the last. A product
that ships GTD correct out-of-the-box (zero configuration), lets the user dump an
idea in seconds, and guides that raw dump into the right GTD item removes those
thresholds. This is a personal project, not a market play — chosen because GTD is
a clean, simple domain to build well.

## User & Persona

Primary persona: **the builder himself** — a GTD practitioner who bounces off
setup-heavy task tools and experiences ADHD-style friction (each configuration
decision is a threshold). He has his best ideas in places where writing them down
is impractical (driving, away from a keyboard). He reaches for this product in two
moments: (1) the *catch* moment — an idea strikes and must be dumped in seconds;
(2) the *process* moment — later, at a desk, when he sits down to clarify the
inbox and run the GTD loop. Single user; built for personal use, not for a market.

## Success Criteria

### Primary
- The end-to-end core flow works: the user captures an idea as text → it lands in
  the Inbox → the user runs guided clarify → the app walks the GTD decision tree
  (actionable? single step? < 2 min? delegable?), enforces the 2-minute rule, and
  routes the item to the correct bucket (Trash / Someday-Maybe / Reference /
  Projects / Delegation / Next Actions) — with no automated assistance in the MVP;
  the user answers, the app drives the tree and routing. GTD works out-of-the-box,
  zero configuration.

### Secondary
- None. Scope is deliberately locked to the primary flow plus the three cheap
  wins (tags/contexts/priorities/flags, Eisenhower quadrants, weekly review
  without reminders). Deferred items are explicitly NOT MVP success criteria.

### Guardrails
- **Capture never loses an entry.** A dumped idea must reliably reach the Inbox —
  unreliable capture defeats the whole purpose of the product.
- **Clarify never leaves an item without a bucket.** Every item that exits the
  clarify flow lands in exactly one destination; nothing falls through.

## User Stories

### US-01: Capture an idea and clarify it into the right bucket

- **Given** a logged-in user with the app open
- **When** they choose "Catch idea", type an idea, save it, and then run guided
  clarify on that Inbox item
- **Then** the app walks them through the GTD decision tree and the item lands in
  exactly one bucket reflecting their answers

#### Acceptance Criteria
- A captured idea reaches the Inbox before any clarify step runs (capture is
  independent of clarify).
- Clarify asks the decision-tree questions one at a time: actionable? → single
  step? → < 2 min? → delegable?
- A "no" to "actionable?" offers exactly Trash / Someday-Maybe / Reference.
- A multi-step actionable item becomes a Project.
- A "< 2 min" item triggers a 2-minute timer; completion marks it done, "need
  more time" loops the timer.
- A non-delegable single actionable item routes to Next Actions; a delegable one
  routes to Delegation (waiting-for).
- No clarified item is ever left in the Inbox or without a bucket.

## Functional Requirements

### Capture
- FR-001: User can capture an idea as free text; the item lands in the Inbox. Priority: must-have
  > Socrates: Counter considered: "voice was the differentiator, so text-only
  > capture doesn't test the core value." Resolution: kept — text is chosen
  > precisely because it is the fastest, simplest capture for the MVP; the point
  > is to grab the idea with minimal friction. Voice is additive in v2.

### Clarify (core domain workflow)
- FR-002: User can start a guided clarify session on an Inbox item. Priority: must-have
  > Socrates: Counter considered: "a guided wizard is overkill — faster to assign
  > a bucket manually." Resolution: accepted in part — within clarify, a user can
  > pick the destination bucket directly (a quick-route) instead of answering the
  > full tree. This is distinct from re-filing already-bucketed items (FR-010,
  > deferred to v2). The guided tree remains the default zero-config path.
- FR-003: App walks the GTD decision tree one question at a time (actionable? → single step? → < 2 min? → delegable?). Priority: must-have
  > Socrates: Counter considered: "step-by-step is tedious; show the whole form
  > at once." Resolution: kept — the enforced question order is exactly what makes
  > GTD correct out-of-the-box; removing it reintroduces the configuration burden.
- FR-004: User can route a non-actionable item to Trash, Someday/Maybe, or Reference. Priority: must-have
  > Socrates: Counter considered: "three destinations is too many choices — just
  > Trash + Someday." Resolution: kept — these are the three canonical GTD
  > destinations for non-actionable items; Reference and Someday/Maybe serve
  > distinct purposes.
- FR-005: User can promote a multi-step actionable item to a Project. Priority: must-have
  > Socrates: Counter considered: "Projects are a whole hierarchy — too heavy for
  > MVP." Resolution: scoped down — in the MVP a Project is just a destination
  > bucket. Full project handling (linking next actions to projects, project
  > outcomes) is deferred far beyond MVP. This demotes FR-012 out of MVP scope.
- FR-006: App starts a 2-minute timer for "< 2 min" items and records the outcome (done / loop). Priority: must-have
  > Socrates: Counter considered: "a built-in timer is a gimmick — everyone has a
  > phone clock." Resolution: kept — enforcing the 2-minute rule inside the app is
  > a concrete GTD discipline win that other tools don't provide.
- FR-007: User can route an actionable item to Delegation (waiting-for). Delegation carries a free-text "who / what" note and a done flag — NOT assigned user accounts. Priority: must-have
  > Socrates: Counter considered: "delegation in a single-user app is pointless."
  > Resolution: refined — there are no other user accounts. Delegation is just a
  > free-text field recording who you're waiting on and whether it's done, so you
  > know whom to chase. No contact/user entities.
- FR-008: Every clarified item lands in exactly one bucket — nothing falls through, nothing lives in two buckets at once. Priority: must-have
  > Socrates: Counter considered: "this is a guardrail, redundant with FR-004/007."
  > Resolution: kept — it is the explicit invariant of the workflow (a saved item
  > must be somewhere, and in exactly one place). Stated so it can't be forgotten.

### Buckets & views
- FR-009: User can view the items in each of the 8 buckets (Inbox, Next Actions, Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, Trash). Priority: must-have
  > Socrates: Counter considered: "8 buckets is a lot of UI; Trash/Reference are
  > rarely opened." Resolution: kept — all 8 are the canonical GTD lists and the
  > full set IS the "GTD out-of-the-box" promise.
- FR-010: User can manually move an item between buckets (re-file an already-bucketed item). Priority: nice-to-have — deferred to v2.
  > Socrates: Counter considered: "if clarify routes correctly, manual re-filing is
  > rarely needed." Resolution: deferred — re-filing already-bucketed items moves
  > to v2. Note: this is distinct from FR-002's in-clarify quick-pick of a
  > destination, which stays in the MVP.
- FR-011: User can assign a date/deadline to an item, and date-specific items appear in the Calendar/Dates bucket. Priority: must-have
  > Socrates: Counter considered: "a date is a field, not a bucket — collapse
  > Calendar into a date field." Resolution: kept both — a date is a field on any
  > task (e.g. a deadline) AND Calendar/Dates is a separate GTD bucket/view for
  > date-specific items.
- FR-012: A Project can have associated next actions (steps). Priority: nice-to-have — deferred to v2 (demoted during FR-005 Socrates round; in MVP a Project is just a destination bucket).
  > Socrates: Challenged during the FR-005 round — full project handling (linking
  > next actions to projects) is far beyond MVP. Resolution: deferred to v2.

### Metadata & views (cheap wins)
- FR-013: User can assign tags, contexts, priorities, and flags to an item. Priority: must-have
  > Socrates: Counter considered: "four metadata dimensions is a lot — contexts
  > alone is the GTD essential." Resolution: kept — they are cheap fields and
  > feed the Eisenhower quadrants (FR-014).
- FR-014: User can view Next Actions arranged in Eisenhower quadrants (important × urgent). Priority: must-have
  > Socrates: Counter considered: "Eisenhower isn't canonical GTD — it's a bolted-on
  > system." Resolution: kept — GTD deliberately leaves prioritization open; the
  > user explicitly chooses the Eisenhower matrix as the prioritization lens for
  > Next Actions.

### Review
- FR-015: User can run a guided weekly review across the buckets. Priority: must-have
  > Socrates: Counter considered: "review without reminders is just 'open all
  > lists'." Resolution: kept — the guided step-by-step review ritual has value in
  > its structure, independent of reminders (which are v2).

## Non-Functional Requirements

- **Capture is near-instant.** From opening capture to the idea being saved, the
  user sees a visible confirmation within ~2 seconds, with immediate
  acknowledgement of input. Frictionless capture is the product's reason to exist;
  a slow capture defeats it.

Considered but deliberately NOT committed as MVP non-functional requirements (the
user can revisit these later): capture durability (already covered as a Guardrail
in Success Criteria), data privacy for cloud-stored items, and list-view
responsiveness. Recorded so the absence is a deliberate choice, not an oversight —
see Open Questions.

## Business Logic

The app routes every captured item to exactly one GTD bucket by walking the user
through the canonical GTD clarify decision tree (actionable? → single step? →
< 2 min? → delegable?), enforcing the two-minute rule along the way.

The rule consumes two user-facing inputs: the raw text of a captured idea, and
the user's yes/no answers as they move through the decision tree. Its output is
the item placed in exactly one destination (Inbox → one of Next Actions,
Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, or Trash), with
any metadata the user attached (tags, contexts, priorities, flags).

The user encounters the rule in the clarify flow: after capturing an idea to the
Inbox, they start clarify and answer one question at a time; the order is fixed
and the destinations offered at each branch are determined by their prior
answers. When an item is judged to take under two minutes, the app holds the user
in a short timed loop until it is done or explicitly deferred. The decision of
*which* answer is correct is made by the user in the MVP; a later version will
have the system suggest those answers, but the routing rule itself is unchanged —
only who proposes the answers differs.

## Access Control

Flat single-user model — no roles, no sharing, no multi-tenant separation, in the
MVP and permanently (multi-user is a permanent non-goal). Because all data is
stored remotely and reached from more than one device, the backend authenticates
the one user so it knows whose data it serves.

- **MVP:** the user signs in with an e-mail address and a password. One account,
  one user.

Additional sign-in methods and a hands-free hardware capture channel are out of
MVP scope and recorded in the technical roadmap (see hand-off), not committed here.

## Non-Goals

Deferred (will likely be built later, just not in the MVP):

- **No automated clarify assistance in the MVP.** Clarify is manual; having the
  system suggest the decision-tree answers is deferred. (The heart is GTD
  out-of-the-box, not automation.)
- **No voice capture.** Text capture only; the hands-free voice "catch idea"
  moment is deferred — even though it was the original differentiator.
- **No mobile app.** Web only in the MVP; a mobile front is deferred.
- **No recurring items — and no recurring detection** in the MVP.
- **No reminders.** The weekly review runs without notifications.
- **No external integrations.** No calendar, e-mail, or third-party tool
  integration in the MVP.

Permanent non-goal (never, not even beyond the MVP):

- **No multi-user / sharing.** This is and remains a single-user personal tool —
  no other-user accounts, no shared lists, ever. This permanently bounds the
  access model and the product surface.

## Open Questions

No blocking open questions — shaping closed with a clean quality cross-check (all
required elements present), and every frontmatter field is populated.

Non-blocking items to revisit later (deliberately deferred, not gaps):

1. **Data privacy for cloud-stored items** — owner: user. Deliberately not
   committed as an MVP NFR; revisit before any non-personal use.
2. **List-view responsiveness target** — owner: user. Not committed for MVP;
   revisit if task volume grows.
