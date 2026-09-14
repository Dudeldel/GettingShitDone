---
project: "Getting Shit Done"
version: 1
status: draft
created: 2026-06-24
updated: 2026-09-14
prd_version: 1
main_goal: quality
top_blocker: skills
---

# Roadmap: Getting Shit Done (GSD)

> Derived from `context/foundation/prd.md` (v1) + auto-researched codebase baseline.
> Edit-in-place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Vision recap

GSD is a single-user "Getting Things Done" app whose whole reason to exist is removing the three thresholds that kill GTD adoption: setup friction, capture friction, and clarify friction. It ships GTD correct out-of-the-box (zero configuration), lets the user dump an idea in seconds, and then guides that raw dump through the canonical GTD decision tree into exactly one of 8 buckets. This is a personal tool for the builder himself — not a market play — chosen because GTD is a clean domain to build well. The MVP is deliberately manual (no AI, no voice): the user answers the decision-tree questions, the app drives the routing.

## North star

**S-01: Capture an idea into the Inbox** — the validation milestone is that a typed idea reliably and near-instantly lands in the Inbox; everything downstream (clarify, routing, views) is worthless if capture itself isn't trustworthy.

> "North star" here means the smallest end-to-end flow whose successful delivery proves the product's core promise — placed as early as its Prerequisites allow, because the rest of the roadmap only matters if this works. The PRD's two non-negotiable Guardrails both touch this slice ("Capture never loses an entry"; capture is independent of clarify), and the sole committed NFR ("capture is near-instant, ~2s confirmation") is its acceptance bar. Proving capture first de-risks the auth + data + HTTP plumbing on an unfamiliar Octane/Swoole runtime before the hard clarify logic is layered on top.

## At a glance

| ID   | Change ID                  | Outcome (user can …)                                              | Prerequisites | PRD refs                          | Status   |
| ---- | -------------------------- | ---------------------------------------------------------------- | ------------- | --------------------------------- | -------- |
| F-01 | quality-gates-toolchain    | (foundation) CI runs Pest + Larastan L6 + Scramble on every push | —             | tests/CLAUDE.md gate, NFR         | done     |
| F-02 | email-password-auth        | (foundation) the one user signs in; API requests are authenticated | F-01        | Access Control, US-01             | done     |
| F-03 | observability-baseline     | (foundation) request-id correlation + structured logs + LogEvent | F-01          | NFR, Access Control               | done (absorbed by F-01+F-02) |
| S-01 | capture-to-inbox           | sign in, type an idea, and see it saved in the Inbox in ~2s      | F-02          | FR-001, US-01, NFR                | done |
| S-02 | guided-clarify-routing     | run guided clarify and route an item to its bucket              | S-01, F-03    | FR-002, FR-003, FR-004, FR-007, FR-008, US-01 | done |
| S-03 | two-minute-rule-timer      | run the 2-minute timer for a "< 2 min" item during clarify       | S-02          | FR-006, US-01                     | done |
| S-04 | promote-to-project         | promote a multi-step actionable item to the Projects bucket      | S-02          | FR-005, US-01                     | absorbed by S-02 |
| S-05 | eight-bucket-views         | open and view all 8 GTD buckets, and empty the Trash             | S-01          | FR-009 (+ Trash purge, no FR)     | done |
| S-06 | item-metadata-and-calendar | assign a date, tags, contexts and flags to an item; dated items show in Calendar | S-01, S-05    | FR-011, FR-013                    | in-progress |
| S-07 | item-metadata              | assign tags, contexts, priorities, and flags to an item         | S-01          | FR-013                            | absorbed by S-06 |
| S-08 | eisenhower-quadrants       | view Next Actions arranged in Eisenhower quadrants              | S-02, S-06    | FR-014                            | parked |
| S-09 | weekly-review              | run a guided weekly review across the buckets                    | S-05          | FR-015                            | parked |
| S-10 | visual-design-pass         | see an interface that reads as a considered product, not a scaffold | S-01, S-02, S-05 | no FR — see Note                 | done |
| S-11 | item-actions-after-clarify | move a clarified item to another bucket, and mark it done          | S-02, S-03, S-05 | FR-010 (v2, promoted) + PRD gap  | done        |
| S-12 | complete-an-item           | mark an item done outside the two-minute timer                    | S-03          | none — PRD gap                    | absorbed by S-11 |

## Streams

Navigation aid — groups items that share a Prerequisites chain. Canonical ordering still lives in the dependency graph below; this table is the proposed reading order across parallel tracks.

| Stream | Theme                       | Chain                              | Note                                                                      |
| ------ | --------------------------- | ---------------------------------- | ------------------------------------------------------------------------- |
| A      | Engineering safety net      | `F-01` → `F-03`                    | Quality gates + observability; the `quality` goal sequences these first.  |
| B      | Identity & capture (north star) | `F-02` → `S-01`                | Auth gate then the north-star capture slice; depends on Stream A's `F-01`. |
| C      | Clarify core & branches     | `S-02` → `S-03` / `S-04`           | The GTD heart; joins Stream B at `S-01` and needs `F-03` for LogEvent.    |
| D      | Buckets, dates & review      | `S-05` → `S-06` (~~`S-09`~~)       | Read/organize surfaces; builds on `S-01`, meaningful once `S-02` routes. S-09 parked, so the stream ends at S-06. |
| E      | Metadata & prioritization   | `S-06` (~~→ `S-08`~~)              | Item attributes (incl. important/urgent) fed the Eisenhower view; S-07 absorbed into S-06, S-08 parked — the stream is S-06 alone. |

## Parallel waves

Which items can run concurrently (separate agent runs / sessions), derived from the
dependency graph. Recorded 2026-06-25, after F-01 done + F-02 implemented + F-03 absorbed.

**Gateway — must run solo:** `S-01` (`capture-to-inbox`) lays the item domain spine
(`items` table, `GtdBucket` enum, Item model/repository/DTO) that 6 other slices import.
Build it alone first; nothing parallelizes with it.

| Wave | Runs in parallel | Each needs |
| ---- | ---------------- | ---------- |
| Gateway | `S-01` (solo) | F-02 |
| A | `S-02` ∥ `S-05` | each only needs S-01 (S-02 also F-03, done) |
| B | `S-03` ∥ `S-04` ∥ `S-06` ∥ ~~`S-09`~~ | S-03/S-04←S-02 · S-06←S-01+S-05 · S-09←S-05 (parked) |
| C | ~~`S-08`~~ | S-08←S-02+S-06 (parked — the wave is now empty) |

Within a wave, no item depends on another, so they are dependency-parallel. **Caveats:**
(1) Wave-A/B slices add columns/fields to the **same `items` table + Item model/DTO**, so
concurrent agents will collide on migrations/model — use git-worktree isolation per slice,
distinct migration timestamps, and reconcile the Item model at integration. (2) The #1
blocker is `skills`, not `capacity`; parallelism is the lever for a capacity constraint, so
running the full 5-wide Wave B at once mostly multiplies integration cost for a solo dev —
the graph *permits* it, it isn't necessarily *wise*. Lowest-conflict first parallel pair:
`S-02` (clarify) + `S-05` (bucket views) — mostly different surfaces.

## Baseline

What's already in place in the codebase as of 2026-06-24 (auto-researched + user-confirmed).
Foundations below assume these are present and do NOT re-scaffold them.

- **Frontend:** partial — React 19 + Vite + TypeScript scaffold builds and deploys, but only a walking-skeleton `App.tsx` (health-check fetch). No routing, state management, component library, or GTD screens (`frontend/package.json`, `frontend/src/App.tsx`).
- **Backend / API:** partial — Laravel + Octane + `routes/api.php` live, but only `HealthController`. No GTD domain code: `app/Domain`, `app/Services`, `app/Dto` empty; only the `User` model. The strict layering in `app/CLAUDE.md` is documented, not built.
- **Data:** partial — connection configured (SQLite default / MySQL "gsd" in prod), migrations are Laravel defaults only. No domain tables, no models beyond `User`, no domain seeders (`config/database.php`, `database/migrations/`).
- **Auth:** partial — Sanctum installed, `HasApiTokens` on `User`, `auth:sanctum` guards a `/user` probe route — but no `config/sanctum.php`, no login/register endpoints, no auth flow. Scaffold only.
- **Deploy / infra:** present — Railway 2-service deploy is LIVE and verified end-to-end (SPA → Octane API → MySQL). Dockerfiles, entrypoint, Caddyfile, and `.github/workflows/ci.yml` (Pint + tests + frontend lint/build) are in place.
- **Observability:** partial — stock Laravel logging only. No ECS/JSON channel, no request-id middleware, no `app/Logging/`. Larastan / Pest / Scramble NOT installed; CI runs no Pest/Larastan step yet.

## Foundations

### F-01: Quality gates & analysis toolchain

- **Outcome:** (foundation) Pest, Larastan (level 6), and Scramble are installed and wired so CI runs format + static-analysis + test + docs gates on every push.
- **Change ID:** quality-gates-toolchain
- **PRD refs:** `tests/CLAUDE.md` CI-gate convention; NFR (correctness net for the unfamiliar runtime)
- **Unlocks:** every `S-NN` (each must ship feature + unit tests to pass CI per `tests/CLAUDE.md`); reduces the `skills` blocker by giving an automated safety net for feature work on Octane/Swoole.
- **Prerequisites:** — (CI workflow + Pint scaffold already present per Baseline)
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Sequenced first because `main_goal: quality` and the CI gates are a hard convention; the only risk is Larastan level-6 surfacing pre-existing type gaps in the scaffold, caught immediately rather than mid-feature. Minimal scope: install + wire + migrate the example tests — it builds no features.
- **Status:** done

### F-02: Email + password authentication

- **Outcome:** (foundation) the single user can sign in with email + password; API requests are authenticated via Sanctum so the backend knows whose data it serves.
- **Change ID:** email-password-auth
- **PRD refs:** Access Control (MVP email+password, one account); US-01 ("a logged-in user")
- **Unlocks:** `S-01` (the north star — capture requires a logged-in user) and every data-bearing slice thereafter.
- **Prerequisites:** F-01
- **Parallel with:** F-03
- **Blockers:** —
- **Unknowns:**
  - Sanctum cookie (SPA) vs. token auth across the two Railway origins — `infrastructure.md` notes CORS is currently `*` and must tighten when auth lands. Owner: user. Block: no.
- **Risk:** Minimal-scope enabler (login / register / logout + route protection + a login screen), NOT a full account-management suite — password reset, OAuth, and magic link are explicit Non-Goals/v2. After it lands, `S-01` still exercises auth through a real capture flow, keeping the slice vertical.
- **Status:** done

### F-03: Observability baseline

- **Outcome:** (foundation) request-id correlation middleware, a structured (JSON) log channel, and the `LogEvent` domain-event helper are in place.
- **Change ID:** observability-baseline
- **PRD refs:** NFR (capture must be observably fast); Access Control (single-user `user_id` log context only — no tenant context)
- **Unlocks:** safe debugging of every `S-NN` on the unfamiliar Octane/Swoole runtime (directly mitigates the `skills` blocker — `infrastructure.md` flags worker state-leakage and connection-recycling papercuts); provides the `LogEvent::itemClarified`-style domain logging that `S-02` clarify requires per `app/CLAUDE.md`.
- **Prerequisites:** F-01
- **Parallel with:** F-02
- **Blockers:** —
- **Unknowns:**
  - Whether Octane's persistent workers need explicit log-context flushing between requests (`Log::flushSharedContext`) as documented for queues. Owner: user. Block: no.
- **Risk:** Sequenced early because `quality` goal does not defer observability behind features, and the `skills` blocker makes request correlation valuable before feature work on Octane. Scoped to request-id + JSON channel + `LogEvent` skeleton — NOT a full ECS shipping pipeline (that stays out of MVP).
- **Status:** done (absorbed)
- **Absorbed:** no separate change was needed — every piece shipped across **F-01** (`AssignRequestId` request-id middleware, the `json`/ECS log channel + `EcsFormatter` + redaction/flat→ECS processors, the `LogEvent` helper, Octane-safe per-request flush) and **F-02** (`LogContextMiddleware` wiring `user_id` into `Log::shareContext` inside the auth group). Nothing remains; closed without its own `/10x-new` cycle. Verified green under F-01/F-02 gates.

## Slices

### S-01: Capture an idea into the Inbox

- **Outcome:** user can sign in, type a free-text idea, save it, and see it appear in the Inbox with confirmation within ~2 seconds.
- **Change ID:** capture-to-inbox
- **PRD refs:** FR-001, US-01 (capture half), NFR (near-instant capture), Guardrail (capture never loses an entry)
- **Prerequisites:** F-02
- **Parallel with:** F-03
- **Blockers:** —
- **Unknowns:**
  - Does the capture write path interact badly with Octane/Swoole persistent state (no request state in singletons per `infrastructure.md`)? Owner: user. Block: no.
- **Risk:** This is the north star, so it carries first-feature risk: it introduces the item domain spine (the `items` table, the `GtdBucket` enum defaulting to Inbox, the Item model + repository + DTO). Kept minimal — capture + Inbox list only — so the spine is introduced vertically, not as a horizontal "build the schema" step.
- **Status:** done

### S-02: Guided clarify routes an item to its bucket

- **Outcome:** user can start guided clarify on an Inbox item, answer the decision-tree questions one at a time, and have the item land in exactly one bucket (Trash / Someday-Maybe / Reference / Next Actions / Delegation).
- **Change ID:** guided-clarify-routing
- **PRD refs:** FR-002, FR-003, FR-004, FR-007, FR-008, US-01 (clarify half), Guardrail (clarify never leaves an item without a bucket)
- **Prerequisites:** S-01, F-03
- **Parallel with:** S-05, S-07
- **Blockers:** —
- **Unknowns:**
  - Delegation is a free-text who/what note + done flag (FR-007) — confirm it is modelled as item fields, not a contact entity. Owner: user. Block: no.
- **Risk:** The GTD heart and the deepest correctness surface — the fixed question order (FR-003), branch-dependent destinations (FR-004/007), and the exactly-one-bucket invariant (FR-008). `quality` investment concentrates here: this belongs in a Domain Entity per `app/CLAUDE.md`, with the invariant enforced at both the domain and data layers. Excludes the two special branches (timer, project) — they are split into S-03/S-04 to keep this slice's risk single-axis.
- **Status:** done

### S-03: 2-minute rule timer in clarify

- **Outcome:** during clarify, a "< 2 min" item triggers a 2-minute timer; "done" marks it complete, "need more time" loops the timer.
- **Change ID:** two-minute-rule-timer
- **PRD refs:** FR-006, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-04
- **Blockers:** —
- **Unknowns:**
  - Where a *completed* 2-minute item lands: there is no Done bucket among the eight, yet
    FR-008 requires exactly one. Resolved in `context/changes/two-minute-rule-timer/change.md`
    — done is a state (`completed_at`), not a destination; the item lands in Next Actions.
    Owner: user. Block: no.
- **Risk:** A focused addition to the clarify flow built in S-02. The 120-second threshold is a domain constant (`app/Const/`), not a literal; the timed loop is the only stateful UX in clarify, so it is isolated here rather than tangled into the routing engine.
- **Status:** done

### S-04: Promote a multi-step item to a Project

- **Outcome:** during clarify, a multi-step actionable item is promoted to the Projects bucket.
- **Change ID:** promote-to-project
- **PRD refs:** FR-005, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Deliberately thin: in the MVP a Project is just a destination bucket (FR-005 resolution). Linking next actions to projects (FR-012) is Parked, so this slice must not grow project hierarchy — that scope creep is the main risk.
- **Status:** absorbed by S-02 — the multi-step branch of the clarify tree routes to the
  Projects bucket (`ClarifyDecision`), covered by unit and feature tests. FR-005's own
  resolution says a Project in the MVP *is* just a destination bucket, and `GtdBucket::Projects`
  already existed, so there was nothing left for a separate slice to build. Project hierarchy
  (FR-012) stays parked.

### S-05: View all 8 buckets

- **Outcome:** user can open and view the items in each of the 8 GTD buckets (Inbox, Next Actions, Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, Trash), and permanently discard what sits in the Trash ("empty the Trash").
- **Change ID:** eight-bucket-views
- **PRD refs:** FR-009; the Trash purge carries no FR of its own — it completes the Trash destination FR-004 already offers (see Note)
- **Prerequisites:** S-01
- **Parallel with:** S-02, S-07
- **Blockers:** —
- **Unknowns:**
  - Purge granularity: "empty the whole Trash" in one action vs. discarding one item at a time from the Trash view (or both). Owner: user. Block: no — decide in `/10x-plan`.
- **Risk:** Generalizes the Inbox list from S-01 into navigation across all 8 buckets, and adds the slice's one destructive write. The views themselves stay low risk; the purge does not — it is the only irreversible operation in the product, so it must be reachable ONLY from the Trash view and never as a generic "delete item" verb hanging off every bucket. That boundary is what keeps it inside FR-004's semantics instead of quietly shipping FR-010's re-filing, which is S-11's job and must arrive with its own verb. The full 8-bucket set IS the "GTD out-of-the-box" promise, so none can be dropped for UI economy.
- **Note (added 2026-09-14):** the Trash purge was folded in here on purpose rather than getting its own slice. In GTD the Trash IS the delete (FR-004), so routing an item there is a clarify outcome, not a removal — as of S-02 nothing in the product ever removes a row. The Trash view is the only place a permanent discard belongs, so it rides along with the view that introduces it. Secondary, and not the reason it was added: it also closes the missing Delete operation in the 10xBuilder CRUD check.
- **Status:** done

### S-06: Item attributes; Calendar/Dates bucket

- **Outcome:** user can give an item a date, tags, a context and the important/urgent flags,
  and an item that has a date shows up in the Calendar/Dates bucket.
- **Change ID:** item-metadata-and-calendar
- **PRD refs:** FR-011, FR-013 (S-07 absorbed)
- **Prerequisites:** S-01, S-05
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - **What "shows up in Calendar" means, given FR-008.** The PRD considered collapsing Calendar
    into a date field and deliberately kept both (FR-011's Socrates note), while FR-008 says an
    item lives in exactly one bucket. So a dated Next Action cannot be a member of two. Working
    direction: a date makes the item appear in Calendar as a **view over the date**, not a second
    membership — the same shape as the Eisenhower quadrant, which `CLAUDE.md` already requires to
    be computed rather than stored. This has to be settled in research/plan, not discovered
    mid-implementation.
- **Risk:** A date is both a field on any item AND the membership rule for the Calendar/Dates
  view. Bind the date format to a single constant so validation and display cannot drift.
  (Corrected 2026-09-14: this entry previously claimed `ClarifyConst::DATE_FORMAT` was
  "already present" — it never existed in code, only as an example in `app/CLAUDE.md`. S-06
  introduces it as `ItemConst::DATE_FORMAT`, on the class that owns item attributes.)
- **Risk:** This is the first write path for the five dormant columns, and the first
  edit-an-item surface in the product. The codebase has deliberately refused a generic update
  (`routes/api.php:35`; both S-11 payloads are shaped to close that hole), so the verb this
  slice adds is exactly where that discipline is most at risk.
- **Risk:** important/urgent are the inputs S-08 reads, so their encoding must be modelled
  deliberately here rather than improvised.
- **Status:** in-progress

### S-07: Tags, contexts, priorities, and flags

- **Outcome:** user can assign tags, contexts, priorities, and flags to an item.
- **Change ID:** item-metadata
- **PRD refs:** FR-013
- **Prerequisites:** S-01
- **Parallel with:** S-02, S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Cheap-win metadata fields, but they are the inputs the Eisenhower view (S-08) reads, so the important/urgent encoding must be modelled deliberately here rather than improvised. Low risk; mostly additive item fields.
- **Status:** absorbed by S-06 — both write to the same five dormant columns on `items`
  (`due_date`, `tags`, `context`, `important`, `urgent`), all of which already exist, are cast,
  and are deliberately kept out of `#[Fillable]`; `Item.php:14` has said since S-01 that
  "S-06/S-07 add their write paths together with validation". Neither slice has an
  edit-an-item surface to build on, so splitting them means inventing that form for one field
  and widening it for four more a week later. Worse, two overlapping verbs that both edit item
  fields are the standing invitation to "tidy them up" into the generic PATCH this codebase has
  twice refused. Following the S-04 and S-12 precedent, S-06 carries both.

### S-08: Eisenhower quadrants for Next Actions

- **Outcome:** user can view Next Actions arranged in Eisenhower quadrants (important × urgent).
- **Change ID:** eisenhower-quadrants
- **PRD refs:** FR-014
- **Prerequisites:** S-02, S-06
- **Parallel with:** S-09
- **Blockers:** —
- **Unknowns:** —
- **Risk:** The quadrant is a derived view from important × urgent, not stored state (per `CLAUDE.md`). The risk is materializing it as a persisted field instead of a computed projection over S-06's important/urgent flags and S-02's Next Actions.
- **Status:** parked (2026-09-14) — builder scope call: the MVP closes on what is built.
  Unlike every other Parked entry, this one parks **committed** scope: FR-014/FR-015 are PRD
  must-have, not Non-Goals. The slice is otherwise ready — nothing here is blocked or unknown.

### S-09: Guided weekly review

- **Outcome:** user can run a guided weekly review across the buckets.
- **Change ID:** weekly-review
- **PRD refs:** FR-015
- **Prerequisites:** S-05
- **Parallel with:** S-06, S-08
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Value is in the guided step-by-step ritual over the bucket views, not in reminders (push is a Non-Goal). The risk is scope creep into notifications; the slice must stay a structured walk across existing bucket views.
- **Status:** parked (2026-09-14) — builder scope call: the MVP closes on what is built.
  Unlike every other Parked entry, this one parks **committed** scope: FR-014/FR-015 are PRD
  must-have, not Non-Goals. The slice is otherwise ready — nothing here is blocked or unknown.

### S-10: A visual design the product deserves

- **Outcome:** user sees an interface that reads as a considered product — a real visual
  hierarchy, an accent that means something, a visible focus treatment, and no layout defects
  — instead of the unstyled scaffold the feature slices have been building on. (Written before
  the direction was settled; dark mode was then deliberately cut, so the original "working
  light/dark" wording is corrected here rather than copied into the permanent record.)
- **Change ID:** visual-design-pass
- **PRD refs:** none. The PRD is silent on visual design: it commits no NFR for it, and its
  only adjacent line defers "list-view responsiveness" as an uncommitted Open Question owned
  by the user. So unlike every slice before it, this one's acceptance bar cannot be derived
  from the spec — it has to be agreed before planning, or the slice has no definition of done.
- **Prerequisites:** S-01, S-02, S-05 (the screens to be designed must exist first — they now do)
- **Parallel with:** S-06, S-07, S-09 — different surface, but it will touch every component
  file, so running it concurrently with a feature slice means constant merge conflicts.
- **Blockers:** —
- **Unknowns:** resolved in `context/changes/visual-design-pass/` —
  - Definition of done: **Tailwind v4 alone**, light mode only, no visual-regression pin.
    The kit option was measured and dropped: every kit (Base UI, Radix, HeroUI, Ark) breaks
    the same existing test by `aria-hidden`-ing the background behind a modal, and subtracts
    focus guarantees that S-02 and S-05 reviews put in deliberately.
  - Styling approach: Tailwind is purely additive here — there is not one `className` in
    `src/` today, so no JSX structure has to move and the 91 tests are untouched.
- **Risk:** The measured starting point, not an impression: the 109-line stylesheet is
  inherited from the Vite starter and still carries rules for elements this app does not have
  (`#social .button-icon`, `.counter`). Of its 16 design tokens, **five are defined and never
  referenced anywhere** — `--accent`, `--accent-bg`, `--accent-border`, `--shadow`,
  `--social-bg` — so the app has no accent colour and no elevation at all. `--muted` is
  referenced six times but is byte-identical to `--text` in both themes, so every
  de-emphasised timestamp and empty-state message renders exactly like body text: the
  hierarchy the code believes it has does not exist on screen. And `:root { font: 18px/145% }`
  makes the inherited line-height a fixed 26.1px, so the 56px `h1` overlaps itself on every
  screen — shipped in the scaffold, survived four slices and three implementation reviews.
  That last one is the real risk of this slice: **it is the one kind of change the project's
  safety net does not reach.** Test-plan Risk #5 ("a style change shifts or hides part of the
  screen and ships unnoticed") has zero coverage and belongs to test-rollout Phase 4. A visual
  pass with no visual regression gate can silently break working screens, and the h1 bug is
  the proof that it already has. Scope control matters as much: this must not become a
  rewrite of the components' behaviour.
- **Status:** done

### S-11: What an item can do after it has been clarified

- **Outcome:** user can act on an item that has already left the Inbox — move it into a
  different bucket (Someday/Maybe into Next Actions when it becomes real, a mis-filed item
  into where it belongs), and mark it done without having done it inside a two-minute timer.
- **Change ID:** item-actions-after-clarify
- **PRD refs:** FR-010 (nice-to-have, deferred to v2). Promoted out of Parked because daily
  use of the shipped app made it the first thing missing: once an item leaves the Inbox there
  is currently NO way to move it, ever.
- **Prerequisites:** S-02, S-03, S-05
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - The backend does not merely lack this — it actively forbids it. `ItemRepository::clarify`
    guards on `where bucket = inbox` in the same statement that writes, and a second clarify
    answers 409 "That item has already been clarified." So this needs its own verb; loosening
    the clarify guard is the wrong move and would reopen the check-then-act race that guard
    was written to close. Owner: user. Block: no.
  - Whether re-filing re-runs the decision tree or is a direct destination pick. FR-002's
    quick-route already exists as a UI precedent. Owner: user. Block: **yes** — it decides
    whether this is a new endpoint or a new mode of an existing one.
  - Does a completed item stay in its bucket, as the two-minute rule already does, or leave
    it? S-03 decided "done is a state, not a destination" for its own branch; this slice
    either extends that decision or contradicts it. Owner: user. Block: **yes**.
  - Does Delegation's existing `delegation_done` flag (FR-007) merge with `completed_at`, or
    stay a separate concept? Two done-flags on one row is how a domain starts lying.
    Owner: user. Block: no.
  - Whether completed items stay listed forever. Today they do, deliberately — vanishing is
    indistinguishable from being lost — but that answer was for a trickle, not a backlog.
    Owner: user. Block: no.
- **Risk:** FR-008's exactly-one-bucket invariant is the thing to protect, and the single
  statement that enforces it is the same one that blocks this. The second trap is recorded in
  S-03's implementation review (F9): `clarify` writes `completed_at => null` unconditionally,
  which is safe ONLY because the inbox guard means a completed item can never be re-clarified.
  A re-filing path that reuses that write erases completions silently. Third: S-05 deliberately
  gave the Trash purge no item id so a generic "delete this row" verb could not arrive by the
  back door — re-filing must not become that verb either.

  Marking done carries less new risk than it looks — the column, the DTO field and the "✓ Done"
  rendering already exist and are tested, so it is a write path plus a control rather than new
  state. It is in the same slice because it shares everything that IS risky: the same guard
  blocks it, the same `completed_at => null` write can erase it, and it needs the same thing in
  the UI that does not exist yet — a per-row action in a bucket view, which S-05 deliberately
  left out so a generic delete verb could not arrive by the back door.
- **Status:** done

### S-12: Mark an item done outside the two-minute timer

- **Outcome:** user can mark any actionable item complete, and see what they finished — the
  ordinary act of doing a Next Action.
- **Change ID:** complete-an-item
- **PRD refs:** **none, and that is the finding.** A PRD gap rather than a deferred item:
  FR-006 records only the two-minute timer's outcome, and FR-007's done flag belongs to
  Delegation alone. Nothing in the spec lets a user finish a Next Action.
- **Prerequisites:** S-03
- **Status:** absorbed by S-11 — both change an item's state *after* clarify, both are blocked
  by the same `where bucket = inbox` guard, both can be silently undone by the same
  `completed_at => null` write (S-03 review F9), and both need the same missing UI affordance:
  a per-row action in a bucket view. Splitting them would mean two change folders editing the
  same repository method, the same DTO and the same list component, and reconciling them at
  integration. Following the S-04 precedent, S-11 carries both.

## Backlog Handoff

| Roadmap ID | Change ID                 | Suggested issue title                                  | Ready for `/10x-plan` | Notes                                  |
| ---------- | ------------------------- | ----------------------------------------------------- | --------------------- | -------------------------------------- |
| F-01       | quality-gates-toolchain   | Wire Pest + Larastan L6 + Scramble into CI gates      | yes                   | Run `/10x-plan quality-gates-toolchain` |
| F-02       | email-password-auth       | Email + password auth (Sanctum) for the single user  | no                    | Needs F-01                             |
| F-03       | observability-baseline    | Request-id + structured logging + LogEvent baseline   | no                    | Needs F-01                             |
| S-01       | capture-to-inbox          | Capture an idea into the Inbox (north star)           | no                    | Needs F-02                             |
| S-02       | guided-clarify-routing    | Guided clarify routes an item to its bucket          | no                    | Needs S-01, F-03                       |
| S-03       | two-minute-rule-timer     | 2-minute rule timer in clarify                        | no                    | Needs S-02                             |
| S-04       | promote-to-project        | Promote a multi-step item to a Project               | no                    | Needs S-02                             |
| S-05       | eight-bucket-views        | View all 8 GTD buckets; empty the Trash               | no                    | Needs S-01                             |
| S-06       | item-metadata-and-calendar | Item attributes; Calendar/Dates bucket               | **yes**               | S-01, S-05 shipped; absorbs S-07       |
| S-07       | item-metadata             | Tags, contexts, priorities, and flags                 | —                     | Absorbed by S-06                       |
| S-08       | eisenhower-quadrants      | Eisenhower quadrants for Next Actions                 | parked                | Needs S-06 (important/urgent)          |
| S-09       | weekly-review             | Guided weekly review                                   | parked                | S-05 shipped — unblocked, parked anyway |

## Open Roadmap Questions

1. **Data privacy for cloud-stored items** — Owner: user. Block: roadmap-wide (none currently). Deliberately not committed as an MVP NFR (PRD Open Questions); revisit before any non-personal use.
2. **List-view responsiveness target** — Owner: user. Block: none. Not committed for MVP (PRD Open Questions); revisit if task volume grows. Touches S-05/S-08.
3. **SPA auth model across two Railway origins** — Owner: user. Block: F-02 (resolve before building auth). Sanctum cookie vs. token + CORS tightening from the current `*` (per `infrastructure.md`).

## Parked

- **S-08: Eisenhower quadrants for Next Actions (FR-014)** — Why parked: builder scope call
  2026-09-14; the MVP closes on what is built. Ready to plan whenever S-06 lands — its inputs
  (important/urgent) ship with S-06 regardless. **Parks PRD must-have scope**, so the PRD and
  this roadmap now disagree until one of them is amended.
- **S-09: Guided weekly review (FR-015)** — Why parked: same 2026-09-14 scope call. Note this
  one was *unblocked* — S-05 shipped — so it is parked by choice, not by dependency. **Parks
  PRD must-have scope**; same caveat as S-08.
- **Project → next-actions linking (FR-012)** — Why parked: PRD defers to v2; in the MVP a Project is just a destination bucket.
- **AI-assisted clarify** — Why parked: PRD Non-Goal; the heart is GTD out-of-the-box, not AI. v2 fast-follow.
- **Voice capture + transcription** — Why parked: PRD Non-Goal; text capture only in MVP.
- **Mobile app** — Why parked: PRD Non-Goal; web only in MVP.
- **Recurring items / recurring detection** — Why parked: PRD Non-Goal in MVP.
- **Push reminders for the weekly review** — Why parked: PRD Non-Goal; review runs without notifications.
- **External integrations (calendar / email / third-party)** — Why parked: PRD Non-Goal in MVP.
- **OAuth + passwordless magic link; ESP32 hardware "catch" channel** — Why parked: PRD Access Control / shape-notes forward block; beyond the email+password MVP.
- **Multi-user / sharing** — Why parked: PERMANENT non-goal (PRD). Never roadmapped.

## Done

- **F-01: (foundation) CI runs Pest + Larastan L6 + Scramble on every push** — Archived 2026-06-24 → `context/archive/2026-06-24-quality-gates-toolchain/`. Lesson: —.
- **F-02: (foundation) the one user signs in; API requests are authenticated** — Archived 2026-06-25 → `context/archive/2026-06-24-email-password-auth/`. Lesson: —.
- **S-01: sign in, type an idea, and see it saved in the Inbox in ~2s** — Archived 2026-09-08 → `context/archive/2026-06-25-capture-to-inbox/`. Lesson: —.
- **S-02: user can start guided clarify on an Inbox item, answer the decision-tree questions one at a time, and have the item land in exactly one bucket (Trash / Someday-Maybe / Reference / Next Actions / Delegation).** — Archived 2026-09-14 → `context/archive/2026-09-14-guided-clarify-routing/`. Lesson: —.
- **S-05: user can open and view the items in each of the 8 GTD buckets (Inbox, Next Actions, Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, Trash), and permanently discard what sits in the Trash ("empty the Trash").** — Archived 2026-09-14 → `context/archive/2026-09-14-eight-bucket-views/`. Lesson: —.
- **S-03: during clarify, a "< 2 min" item triggers a 2-minute timer; "done" marks it complete, "need more time" loops the timer.** — Archived 2026-09-14 → `context/archive/2026-09-14-two-minute-rule-timer/`. Lesson: —.
- **S-10: user sees an interface that reads as a considered product — a real visual hierarchy, an accent that means something, a visible focus treatment, and no layout defects — instead of the unstyled scaffold the feature slices have been building on.** — Archived 2026-09-14 → `context/archive/2026-09-14-visual-design-pass/`. Lesson: —.
- **S-11: user can act on an item that has already left the Inbox — move it into a different bucket (Someday/Maybe into Next Actions when it becomes real, a mis-filed item into where it belongs), and mark it done without having done it inside a two-minute timer.** — Archived 2026-09-14 → `context/archive/2026-09-14-item-actions-after-clarify/`. Lesson: —.
