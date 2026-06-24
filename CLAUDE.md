# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"Getting Shit Done" (GSD) — a **single-user** GTD app. Two halves in one repo:

- **Backend** (repo root): Laravel REST API. Holds all domain logic.
- **Frontend** (`frontend/`): separate Vite + React (TypeScript) SPA that consumes the REST API.

Core domain: capture an idea → guided **clarify** (the GTD decision tree: actionable? → single-step? → < 2 min? → delegable?) → route it to **exactly one** of 8 buckets (inbox, next actions, projects, calendar/dates, delegation, someday/maybe, reference, trash). Product spec: @context/foundation/prd.md. Stack rationale: @context/foundation/tech-stack.md.

Architecture and conventions are adopted from a sibling production Laravel service ("the twin", WikroST), adapted to this app. Self-contained — do not assume the twin's wiki is reachable.

## CRITICAL — what NOT to copy from the twin (read before adding infra)

The twin is a multi-tenant sharded microservice. This app is the opposite shape. Do **not** transplant:

- **No multi-tenancy.** Multi-user/sharing is a **permanent** non-goal (@context/foundation/prd.md). Never add `BelongsToTenant` / `BelongsToCompany`, `PartConnectionManager`, Central-vs-Part DB sharding, `ResolveTenantAuth` / `ResolveCompany` middleware, or tenant/company isolation. One user, one database.
- **No tenant-scoped ability split.** The twin's `fixed-assets:read|write` per-tenant abilities and `ApiClient` model do not apply. Auth here is one account via Sanctum.
- **Deploy is self-host on AWS Lightsail + GitHub Actions** (@context/foundation/tech-stack.md) — NOT the twin's Helm/K8s + Bitbucket Pipelines. Keep the *gates* (below), change the *host*.

## Environment tripwires

- **Composer is not global** — invoke it as `php composer.phar` from repo root (the phar lives there, git-ignored). Plain `composer` will fail.
- **The REST API is not scaffolded yet.** Fresh scaffold has only `routes/web.php`. Run `php artisan install:api` to add `routes/api.php` + Sanctum before building endpoints (PRD requires a REST API with email+password auth).
- **Most adopted tooling is not installed yet** (Pest, Larastan, Scramble). The scaffold ships PHPUnit + Pint only. There is no `Makefile` (the twin uses `make` targets; use the commands below).

## Commands

Backend (repo root):

```bash
php composer.phar setup        # install deps, .env, key:generate, migrate, build (first run)
php composer.phar dev          # concurrent: artisan serve + queue:listen + pail (logs) + vite
php composer.phar test         # config:clear + php artisan test  (currently PHPUnit)
php artisan test --filter=Name # run a single test (method or class name)
php artisan test tests/Feature/FooTest.php   # run one file
./vendor/bin/pint              # format (auto-fix)
./vendor/bin/pint --test       # format check only (CI gate)
```

Frontend (`cd frontend`):

```bash
npm run dev      # vite dev server
npm run build    # tsc -b && vite build
npm run lint     # eslint
```

## Nested rule files — read the one for the area you touch

Detailed rules live next to the code they govern, so each loads near the top of its own context instead of being buried here:

- **@app/CLAUDE.md** — backend conventions (DTOs, services, validation, constants), the block map + "which block to use" decision tree, controller shape, CRUD-vs-async patterns, Larastan/Scramble annotations, and structured logging. **Read before writing anything under `app/`.**
- **@tests/CLAUDE.md** — test framework and conventions (Pest, Unit/Feature split).
- `frontend/` — React/Vite SPA with its own ESLint/TS config; no extra agent rules yet.

## Architecture — strict one-directional layering

The scaffold is vanilla Laravel today; **build into this structure** as features land.

```
Request → FormRequest → Controller → Service → [Entity / Strategy] → Repository(interface) → Repository(impl) → Model → DB
                                                       ▲
                                            DTO / VO / Payload carried between layers
                                                       ▼
                                                    Job (optional, on a queue)
```

Non-negotiable rules (what an agent gets wrong by default):

- Controller **NEVER** calls a Repository directly — always through a Service.
- Controller **NEVER** touches Eloquent (no `Model::find()` in a controller). It is ~3 lines: map HTTP ↔ Service, return JSON.
- Service does **NOT** import the HTTP framework (no `Request`, no `response()`), and calls repositories **through an interface**.
- Repository returns a **DTO**, never an Eloquent Model. A Model must never leak past `app/Infrastructure/`.
- Entity / Strategy / Value Object are **pure PHP** — no Eloquent, no DB, no framework.

How the GTD domain maps onto the layers:

- **The 8 buckets** → a backed enum (`enum GtdBucket: string`).
- **The clarify decision tree + routing + the 2-minute rule** are the core business rule (@context/foundation/prd.md "Business Logic") → an **Entity** (e.g. `InboxItemEntity`) or domain service, **never** a controller or model. Growing branch variants → a **Strategy** per branch + a Factory.
- **Eisenhower quadrant** = derived from `important × urgent` — a computed view, not stored state.
- **Delegation** = a free-text "who/what" note + a done flag (PRD FR-007). **Not** a user/contact entity.

The full block map, the decision tree, controller shape, the CRUD-vs-async reference patterns, and the day-one tripwires are in @app/CLAUDE.md.

## Commits

Conventional Commits — `<type>(TICKET): <description>`; branch `type/TICKET-description`. Types: `feat`, `fix`, `refactor`, `test`, `chore`, `docs`, `perf`, `ci`.

## CI gates

GitHub Actions (@context/foundation/tech-stack.md), auto-deploy on merge to main. Flow: install deps → run in **parallel** (all must pass before merge):

- `./vendor/bin/pint --test` (format check)
- Larastan **level 6**
- **Pest** (SQLite in-memory; `--parallel`)
- Frontend: `npm run build` (includes `tsc -b`) + `npm run lint`

Mirror this as a local pre-push check. Pre-commit checklist: tests pass · Pint clean · Larastan 0 errors · new features have feature + unit tests · `Response::HTTP_*` not integers · Scramble docs updated for new/changed endpoints.

<!-- BEGIN @przeprogramowani/10x-cli -->

## 10xDevs AI Toolkit - Module 2, Lesson 2

Turn one roadmap item into the first implementation cycle with the **change planning chain**:

```
/10x-roadmap -> /10x-new -> /10x-plan -> /10x-plan-review -> /10x-implement
```

`/10x-new`, `/10x-plan`, `/10x-plan-review`, and `/10x-implement` are the lesson focus. `/10x-frame` and `/10x-research` are not required rituals here; they are escalation paths introduced in the next lesson.

### Task Router - Where to start

| Skill | Use it when |
| --- | --- |
| **Change setup (lesson focus)** | |
| `/10x-new <change-id>` | You selected a roadmap item and need a stable change folder. Creates `context/changes/<change-id>/change.md` so planning, implementation, progress, commits, and later review all share one identity. Use AFTER roadmap selection, BEFORE `/10x-plan`. |
| **Planning (lesson focus)** | |
| `/10x-plan <change-id>` | You have a change folder and need a reviewable implementation plan. Reads roadmap context, foundation docs, codebase evidence, and any existing change notes; writes `plan.md` and `plan-brief.md` with phases, file contracts, success criteria, and `## Progress`. |
| **Plan readiness (lesson focus)** | |
| `/10x-plan-review <change-id>` | You have `plan.md` and need a light pre-code readiness check. Use it to catch missing end state, weak contracts, malformed progress, scope drift, or blind spots before code changes begin. |
| **Implementation (lesson focus)** | |
| `/10x-implement <change-id> phase <n>` | You have an approved plan and want to execute one phase with verification, manual gate, commit ritual, and SHA write-back to `## Progress`. |
| **Lifecycle closure** | |
| `/10x-archive <change-id>` | A change is merged or intentionally closed. Move it out of active `context/changes/` into archive state. |

### How the chain hands off

- `/10x-new` creates the durable change identity.
- `/10x-plan` turns that identity into an implementation contract.
- `/10x-plan-review` checks the plan before the agent mutates code.
- `/10x-implement` executes one planned phase, verifies, asks for manual confirmation when needed, commits, and records progress.

### Lesson boundaries

- Plan is the default router after roadmap selection. Start with `/10x-plan` unless the problem is unclear or external evidence is blocking.
- Do not run `/10x-frame + /10x-research` as ceremony for every change.
- Do not turn this lesson into a full end-to-end product build. A checkpoint with a planned and partially or fully implemented stream is valid.
- Code review of the implemented diff belongs to Lesson 3 via `/10x-impl-review`.
- Lifecycle closure via `/10x-archive` after a change is merged or intentionally closed.

### Paths used by this lesson

- `context/foundation/roadmap.md` - upstream roadmap
- `context/changes/<change-id>/change.md` - change identity
- `context/changes/<change-id>/plan.md` - implementation contract
- `context/changes/<change-id>/plan-brief.md` - compressed handoff
- `context/foundation/lessons.md` - recurring rules and pitfalls
- `docs/reference/contract-surfaces.md` - load-bearing names registry

Skills must not write to `context/archive/`. Archived changes are immutable; if a resolved target path starts with `context/archive/`, abort with: "This change is archived. Open a new change with `/10x-new` instead."

<!-- END @przeprogramowani/10x-cli -->
