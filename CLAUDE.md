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
- **Deploy is Railway + GitHub Actions** (@context/foundation/tech-stack.md) — NOT the twin's Helm/K8s + Bitbucket Pipelines. Two Railway services (Laravel/Octane API + static React SPA) with managed MySQL; CI runs the quality gates, Railway builds on push. Keep the *gates* (below), change the *host*. (An AWS Lightsail co-location was the original plan and lost on a RAM-headroom gate — see @context/foundation/infrastructure.md. Do not reintroduce it.)

## Environment tripwires

- **Composer is not global** — invoke it as `php composer.phar` from repo root (the phar lives there, git-ignored). Plain `composer` will fail.
- **No `Makefile`** — the twin uses `make` targets; use the commands below instead.
- **Tests run on SQLite `:memory:`, production runs MySQL** (`phpunit.xml`, `config/database.php`). Two consequences that have already bitten: `lockForUpdate` compiles to a no-op on SQLite, so a test can never prove a `FOR UPDATE` gate; and `:memory:` is one connection per test, so genuine concurrency is unreachable. CI has no MySQL service.
- **The OpenAPI document is git-ignored and never generated in CI.** `php artisan scramble:export` writes `api.json` locally only. `tests/Feature/Api/ContractParityTest.php` generates it in process instead, which is what keeps the published contract honest — read that before changing a FormRequest's rules.

## Commands

Backend (repo root):

```bash
php composer.phar setup        # install deps, .env, key:generate, migrate, build (first run)
php composer.phar dev          # concurrent: artisan serve + queue:listen + pail (logs) + vite
php composer.phar test         # config:clear + php artisan test  (Pest)
php artisan test --filter=Name # run a single test (method or class name)
php artisan test tests/Feature/FooTest.php   # run one file
./vendor/bin/pint              # format (auto-fix)
./vendor/bin/pint --test       # format check only (CI gate)
./vendor/bin/phpstan analyse --memory-limit=512M   # Larastan level 6 (CI gate)
php artisan scramble:export    # regenerate api.json (git-ignored, local only)
```

Frontend (`cd frontend`):

```bash
npm run dev      # vite dev server
npm run build    # tsc -b && vite build  (CI gate)
npm run lint     # eslint                (CI gate)
npm run test     # vitest                (CI gate)
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

## 10xDevs AI Toolkit - Module 3, Lesson 4 (E2E Tests)

**For E2E tests, use the `/10x-e2e` skill.** It is the single source of truth
for the workflow — risk → seed test + rules → generate → review against the five
anti-patterns → re-prompt → verify. The skill's `references/` carry the full
rules, anti-patterns, seed pattern, and prompt-template.

A few hard rules that hold even before you invoke the skill:

- **Locators:** `getByRole` / `getByLabel` / `getByText` first; `getByTestId`
  only when accessibility attributes are ambiguous. Never CSS selectors, XPath,
  or DOM structure.
- **Never `page.waitForTimeout()`.** Wait for state: `toBeVisible()`,
  `waitForURL()`, `waitForResponse()`.
- **Test independence + cleanup.** Each test runs standalone — its own setup,
  action, assertion, and cleanup; unique ids (timestamp suffix) so parallel runs
  and re-runs don't collide.

Two boundaries to keep straight:

- **DOM (snapshot) is the default.** Vision (`--caps=vision`) is a supplement for
  visual-only risks (layout, z-index, animation); for pixel regression prefer
  deterministic tools (`toMatchSnapshot`, Argos, Lost Pixel). VLM model
  selection/cost is a debugging topic (Lesson 5), not testing.
- **Healer helps on selectors, harms on logic.** A changed selector → healer
  re-finds it (route through PR review). A changed business behavior → healer
  masks the bug; that failing-test-to-fix case is Lesson 5.

<!-- END @przeprogramowani/10x-cli -->
