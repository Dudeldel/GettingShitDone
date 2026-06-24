---
project: "Getting Shit Done"
version: 1
status: draft
created: 2026-06-24
updated: 2026-06-24
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
| F-01 | quality-gates-toolchain    | (foundation) CI runs Pest + Larastan L6 + Scramble on every push | —             | tests/CLAUDE.md gate, NFR         | ready    |
| F-02 | email-password-auth        | (foundation) the one user signs in; API requests are authenticated | F-01        | Access Control, US-01             | proposed |
| F-03 | observability-baseline     | (foundation) request-id correlation + structured logs + LogEvent | F-01          | NFR, Access Control               | proposed |
| S-01 | capture-to-inbox           | sign in, type an idea, and see it saved in the Inbox in ~2s      | F-02          | FR-001, US-01, NFR                | proposed |
| S-02 | guided-clarify-routing     | run guided clarify and route an item to its bucket              | S-01, F-03    | FR-002, FR-003, FR-004, FR-007, FR-008, US-01 | proposed |
| S-03 | two-minute-rule-timer      | run the 2-minute timer for a "< 2 min" item during clarify       | S-02          | FR-006, US-01                     | proposed |
| S-04 | promote-to-project         | promote a multi-step actionable item to the Projects bucket      | S-02          | FR-005, US-01                     | proposed |
| S-05 | eight-bucket-views         | open and view the items in each of the 8 GTD buckets             | S-01          | FR-009                            | proposed |
| S-06 | dates-and-calendar-bucket  | assign a date to an item and see it in the Calendar/Dates bucket | S-01, S-05    | FR-011                            | proposed |
| S-07 | item-metadata              | assign tags, contexts, priorities, and flags to an item         | S-01          | FR-013                            | proposed |
| S-08 | eisenhower-quadrants       | view Next Actions arranged in Eisenhower quadrants              | S-02, S-07    | FR-014                            | proposed |
| S-09 | weekly-review              | run a guided weekly review across the buckets                    | S-05          | FR-015                            | proposed |

## Streams

Navigation aid — groups items that share a Prerequisites chain. Canonical ordering still lives in the dependency graph below; this table is the proposed reading order across parallel tracks.

| Stream | Theme                       | Chain                              | Note                                                                      |
| ------ | --------------------------- | ---------------------------------- | ------------------------------------------------------------------------- |
| A      | Engineering safety net      | `F-01` → `F-03`                    | Quality gates + observability; the `quality` goal sequences these first.  |
| B      | Identity & capture (north star) | `F-02` → `S-01`                | Auth gate then the north-star capture slice; depends on Stream A's `F-01`. |
| C      | Clarify core & branches     | `S-02` → `S-03` / `S-04`           | The GTD heart; joins Stream B at `S-01` and needs `F-03` for LogEvent.    |
| D      | Buckets, dates & review      | `S-05` → `S-06` / `S-09`           | Read/organize surfaces; builds on `S-01`, meaningful once `S-02` routes.  |
| E      | Metadata & prioritization   | `S-07` → `S-08`                    | Cheap-win fields that feed the Eisenhower view; builds on `S-01`/`S-02`.  |

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
- **Status:** ready

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
- **Status:** proposed

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
- **Status:** proposed

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
- **Status:** proposed

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
- **Status:** proposed

### S-03: 2-minute rule timer in clarify

- **Outcome:** during clarify, a "< 2 min" item triggers a 2-minute timer; "done" marks it complete, "need more time" loops the timer.
- **Change ID:** two-minute-rule-timer
- **PRD refs:** FR-006, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-04
- **Blockers:** —
- **Unknowns:** —
- **Risk:** A focused addition to the clarify flow built in S-02. The 120-second threshold is a domain constant (`app/Const/`), not a literal; the timed loop is the only stateful UX in clarify, so it is isolated here rather than tangled into the routing engine.
- **Status:** proposed

### S-04: Promote a multi-step item to a Project

- **Outcome:** during clarify, a multi-step actionable item is promoted to the Projects bucket.
- **Change ID:** promote-to-project
- **PRD refs:** FR-005, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Deliberately thin: in the MVP a Project is just a destination bucket (FR-005 resolution). Linking next actions to projects (FR-012) is Parked, so this slice must not grow project hierarchy — that scope creep is the main risk.
- **Status:** proposed

### S-05: View all 8 buckets

- **Outcome:** user can open and view the items in each of the 8 GTD buckets (Inbox, Next Actions, Projects, Calendar/Dates, Delegation, Someday/Maybe, Reference, Trash).
- **Change ID:** eight-bucket-views
- **PRD refs:** FR-009
- **Prerequisites:** S-01
- **Parallel with:** S-02, S-07
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Generalizes the Inbox list from S-01 into navigation across all 8 buckets. Read-only views over the item spine — low risk; the full 8-bucket set IS the "GTD out-of-the-box" promise, so none can be dropped for UI economy.
- **Status:** proposed

### S-06: Assign dates; Calendar/Dates bucket

- **Outcome:** user can assign a date/deadline to an item, and date-specific items appear in the Calendar/Dates bucket.
- **Change ID:** dates-and-calendar-bucket
- **PRD refs:** FR-011
- **Prerequisites:** S-01, S-05
- **Parallel with:** S-07
- **Blockers:** —
- **Unknowns:** —
- **Risk:** A date is both a field on any item AND the membership rule for the Calendar/Dates view (FR-011 keeps both). Use the single date-format constant (`app/Const/`) to avoid drift across validation and display.
- **Status:** proposed

### S-07: Tags, contexts, priorities, and flags

- **Outcome:** user can assign tags, contexts, priorities, and flags to an item.
- **Change ID:** item-metadata
- **PRD refs:** FR-013
- **Prerequisites:** S-01
- **Parallel with:** S-02, S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Cheap-win metadata fields, but they are the inputs the Eisenhower view (S-08) reads, so the important/urgent encoding must be modelled deliberately here rather than improvised. Low risk; mostly additive item fields.
- **Status:** proposed

### S-08: Eisenhower quadrants for Next Actions

- **Outcome:** user can view Next Actions arranged in Eisenhower quadrants (important × urgent).
- **Change ID:** eisenhower-quadrants
- **PRD refs:** FR-014
- **Prerequisites:** S-02, S-07
- **Parallel with:** S-06, S-09
- **Blockers:** —
- **Unknowns:** —
- **Risk:** The quadrant is a derived view from important × urgent, not stored state (per `CLAUDE.md`). The risk is materializing it as a persisted field instead of a computed projection over S-07's metadata and S-02's Next Actions.
- **Status:** proposed

### S-09: Guided weekly review

- **Outcome:** user can run a guided weekly review across the buckets.
- **Change ID:** weekly-review
- **PRD refs:** FR-015
- **Prerequisites:** S-05
- **Parallel with:** S-06, S-08
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Value is in the guided step-by-step ritual over the bucket views, not in reminders (push is a Non-Goal). The risk is scope creep into notifications; the slice must stay a structured walk across existing bucket views.
- **Status:** proposed

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
| S-05       | eight-bucket-views        | View all 8 GTD buckets                                | no                    | Needs S-01                             |
| S-06       | dates-and-calendar-bucket | Assign dates; Calendar/Dates bucket                   | no                    | Needs S-01, S-05                       |
| S-07       | item-metadata             | Tags, contexts, priorities, and flags                 | no                    | Needs S-01                             |
| S-08       | eisenhower-quadrants      | Eisenhower quadrants for Next Actions                 | no                    | Needs S-02, S-07                       |
| S-09       | weekly-review             | Guided weekly review                                   | no                    | Needs S-05                             |

## Open Roadmap Questions

1. **Data privacy for cloud-stored items** — Owner: user. Block: roadmap-wide (none currently). Deliberately not committed as an MVP NFR (PRD Open Questions); revisit before any non-personal use.
2. **List-view responsiveness target** — Owner: user. Block: none. Not committed for MVP (PRD Open Questions); revisit if task volume grows. Touches S-05/S-08.
3. **SPA auth model across two Railway origins** — Owner: user. Block: F-02 (resolve before building auth). Sanctum cookie vs. token + CORS tightening from the current `*` (per `infrastructure.md`).

## Parked

- **Manual re-filing between buckets (FR-010)** — Why parked: PRD demotes to nice-to-have / v2; in-clarify quick-pick (FR-002) covers the MVP need.
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

(Empty on first generation. `/10x-archive` appends here — and flips the item's Status to `done` — when a change whose Change ID matches a roadmap item is archived. Do NOT pre-populate.)
