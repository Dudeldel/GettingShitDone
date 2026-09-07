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

## 10xDevs AI Toolkit - Module 3, Lesson 3

Lesson 3 is about **hooks** — turning the quality gates from Lesson 1 and the tests from Lesson 2 into automatic, deterministic checks that fire while the agent works. A hook runs outside the model, so it survives context compression, instruction changes, and the model "forgetting". The payoff for agentic hooks specifically: a `PostToolUse` check can feed its result back into the agent's context, so the agent fixes trivial errors (formatting, a missing import, a wrong type) on its own in the next iteration instead of you discovering them minutes later.

```
context/foundation/test-plan.md  (§4 Quality Gates: which check, required when)
        │
        ▼  (assign each gate to the cheapest layer that still gives signal)
   per-edit (agent hooks)  →  pre-commit (git hooks)  →  pre-push  →  CI
        │ lint, format, scoped tests          │ staged       │ heavier    │ integration
        ▼
   exit code + stdout  →  additionalContext  →  agent reacts next turn
```

### Task Router — Which layer for this check

| You want to | Do this |
| --- | --- |
| React the instant the agent edits a file | A per-edit hook (`PostToolUse` matcher `Write\|Edit` in Claude Code). Right for fast checks: lint/format, and scoped tests on risk-area files. This is the **only** layer that can hand feedback to the agent mid-session. |
| Run only the tests that depend on the edited file | Parse the path from the hook's stdin (`jq -r .tool_input.file_path`) and run your runner's related-tests mode (`vitest related "$FILE" --run`, `jest --findRelatedTests $FILE`). Gate it on whether the file is a risk area in `test-plan.md`; don't run tests on every helper or config edit. |
| Catch changes that bypassed the agent (manual edits, a teammate's commit) | A pre-commit git hook (Lefthook or Husky+lint-staged) over staged files: lint + typecheck, and tests on staged risk files. |
| Run heavier checks before code leaves the machine | Pre-push: full typecheck or a broader test set. Anything too slow for per-edit moves here. |
| Decide where a given gate belongs | Ask: is it fast enough (a few seconds) for per-edit, or should it wait for commit/push/CI? Slow checks block the agent loop on every edit — push them up a layer. |
| Use the same hook across tools | The trigger → matcher → handler → signal pattern is the same in Cursor, Codex, Windsurf, and Copilot; only the config file and event names change. See the cross-tool table below. |

### Hook lifecycle — the universal pattern

Every tool's hooks follow four steps:

1. **Trigger** — an event in the tool (e.g. the agent just saved a file: `PostToolUse`).
2. **Matcher** — a filter deciding whether this hook runs (tool name like `Write`/`Edit`, file type, or a name pattern).
3. **Handler** — the action that runs, usually a shell command.
4. **Signal** — the result returns to the tool. The exit code says pass/fail; stdout can flow into the agent's context as feedback.

### Exit codes and the feedback loop

- **0** — success; the hook passed, continue.
- **2** — blocking error; the agent sees the feedback and should react.
- **anything else** — non-blocking error; logged, but does not interrupt work.

On a blocking failure, stdout flows into the agent's context (in Claude Code via `additionalContext`, capped at 10,000 characters; other tools have similar mechanisms with their own limits). That is why the agent can self-correct: it sees the concrete message — missing type, unimported module, badly formatted line — not just "something failed".

The boundary: the agent reliably fixes **trivial** corrections on its own. When a test fails because of wrong business logic, the hook surfaces it but the agent may not diagnose the real cause — it says "something is off" and tries a trivial fix. If that does not resolve in one or two tries, the signal comes back to you, and the problem may deserve its own change-id with the full `/10x-new → /10x-research → /10x-plan → /10x-implement` workflow.

### Three local layers (plus CI)

| Layer | Catches | Timing |
| --- | --- | --- |
| Per-edit (agent hooks) | Formatting, simple type errors, failing unit tests on risk files. Only layer that feeds the agent mid-work. | ms–s |
| Pre-commit (git hooks) | What slipped past per-edit: manual edits, files changed outside the hook, checks too slow for per-edit. Operates on staged files. | s |
| Pre-push | Heavier checks before pushing to remote (full typecheck, broader test set). | s–min |
| CI | Integration problems, cross-module dependencies, checks needing infra unavailable locally. | min |

Local layers do **not** replace CI — CI stays the key verification for shared repo state and environments you don't control. But each local layer that catches an error is one fewer CI round-trip. You don't need all layers from day one: start with one per-edit hook (lint) and one commit gate, add layers as you see what escapes. The quality gates in `test-plan.md §4` decide which checks are worth automating and when; a plan may legitimately defer per-edit hooks if the cost/signal ratio isn't there yet.

### Key rules

- Keep per-edit hooks fast. If a check takes more than a few seconds, move it to commit, push, or CI — a slow per-edit hook blocks the agent loop on every edit. Lint/format are ideal per-edit; full typecheck is often a commit gate in larger projects.
- Run scoped tests, not the whole suite, per edit — only tests related to the edited file, and only when that file is a risk area in `test-plan.md`.
- `related` is a subcommand, not a flag (`vitest related`, not `--related`). Use `--run` so the hook terminates instead of entering watch mode.
- `PostToolUse` fires once per tool use; three edits in one turn fire it three times independently — there is no built-in aggregation.
- The git hook tool (Lefthook vs Husky+lint-staged) is an implementation detail; the rule is the same — run checks on staged files before commit. If Husky already works, don't migrate.
- **Context injection is not universal.** Claude Code, Cursor, Codex, and Copilot (in VS Code) can pass a hook's result to the agent; Windsurf cannot — it can block (exit 2) but can't tell the agent what went wrong.

### The same pattern in every tool

| Tool | Events | Handlers | Context injection | Config |
| --- | --- | --- | --- | --- |
| Claude Code | ~30 | command, http, mcp_tool, prompt, agent | yes | `.claude/settings.json` |
| Cursor | ~18 | command, prompt | yes | `.cursor/hooks.json` |
| Codex | 10 | command | yes | `.codex/hooks.json` |
| Windsurf | 12 | command | **no** | `.windsurf/hooks.json` |
| Copilot | ~13 | command, http, prompt | yes (VS Code) | `.github/hooks/*.json` |

### Lesson boundaries

- This lesson configures hooks and local quality layers only. The hook JSON, `lefthook.yml`, and the per-edit/commit/push layering are the scope.
- Do not write E2E tests, configure Playwright/MCP, or run browser scenarios. That is Lesson 4.
- Do not run the bug-to-fix-to-regression-test debugging workflow. That is Lesson 5.
- Do not change the risk strategy or quality-gate definitions. That is Lesson 1 (`/10x-test-plan`); read current state with `/10x-test-plan --status`.
- Do not write unit/integration test code from scratch here. That is Lesson 2 — hooks only *run* the tests those lessons produced.
- Do not author CI/CD pipelines. That is Module 1 Lesson 5 / Module 2 Lesson 5; hooks are the local layers in front of CI.

### Paths used by this lesson

- `.claude/settings.json` — hook configuration (`~/.claude/settings.json` global, `.claude/settings.json` project, `.claude/settings.local.json` local overrides). Other tools use their own config file (see the table).
- `lefthook.yml` — pre-commit git hook config (lint + typecheck + tests on `{staged_files}`).
- `context/foundation/test-plan.md` — §4 quality gates decide which checks to automate and at which layer; risk areas decide which edits warrant scoped tests.

<!-- END @przeprogramowani/10x-cli -->
