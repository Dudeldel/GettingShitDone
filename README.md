# Getting Shit Done (GSD)

A **single-user GTD app** that ships Getting Things Done correct out-of-the-box — zero
configuration. Dump an idea in seconds, then let the app walk you through the canonical
GTD **clarify** decision tree and file the item in exactly one of eight buckets.

The problem it attacks: three thresholds kill GTD adoption — **setup friction** (tools that
must be configured before they work), **capture friction** (the idea arrives while you are
driving or mid-task), and **clarify friction** (deciding what a raw note actually *is*).
GSD removes all three for one person: its own builder. Multi-user and sharing are a
**permanent** non-goal.

Full product rationale: [`context/foundation/prd.md`](context/foundation/prd.md).

## The core flow

```
capture (free text)  →  Inbox  →  guided clarify  →  exactly one bucket
```

Clarify walks the canonical GTD questions in a fixed order, and the destination is derived
from the answers — never sent by the client:

```
actionable?
├─ no  → Trash | Someday/Maybe | Reference
└─ yes → single step?
         ├─ no  → Projects
         └─ yes → < 2 min?
                  ├─ yes → timer runs → done      → Next Actions, marked completed
                  │                   → deferred  → rejoins the tree below
                  └─ delegable?
                     ├─ yes → Delegation (+ free-text who/what)
                     └─ no  → Next Actions
```

The eight buckets: `inbox`, `next_actions`, `projects`, `calendar`, `delegation`,
`someday_maybe`, `reference`, `trash`.

Two guardrails the code and tests are built to defend:

- **Capture never loses an entry.** A confirmed save is durably readable on a second request.
- **Clarify never leaves an item without a bucket.** Every path through the tree terminates,
  and no path returns the Inbox.

## Repository layout

One repo, two deployable halves:

| Path | What it is |
| --- | --- |
| repo root | **Laravel 13 REST API** (PHP 8.3) — holds all domain logic |
| `frontend/` | **Vite + React 19 SPA** (TypeScript) — a separate app consuming that API over HTTP |
| `context/` | the written foundation the app was built from (PRD, roadmap, test plan, …) |
| `docs/reference/` | contract surfaces: load-bearing names other code and plans depend on |

Inside `app/`, the backend follows a strict one-directional layering:

```
Request → FormRequest → Controller → Service → Domain (Entity / Decision) → Repository interface → Repository impl → Model → DB
```

A controller never touches Eloquent, a service never imports HTTP, and a repository returns
a DTO — no Eloquent model leaks past `app/Infrastructure/`. The rules live in
[`CLAUDE.md`](CLAUDE.md) and [`app/CLAUDE.md`](app/CLAUDE.md).

The GTD decision tree itself is pure PHP with no framework or database in sight:
[`app/Domain/Clarify/ClarifyDecision.php`](app/Domain/Clarify/ClarifyDecision.php).

## Stack

| Layer | Choice |
| --- | --- |
| API | Laravel 13, PHP 8.3, Laravel Octane (Swoole) in production |
| Auth | Laravel Sanctum — email + password, bearer token |
| Database | MySQL/MariaDB in production; SQLite in-memory for tests |
| API docs | Scramble — OpenAPI 3.1 generated from the code |
| Frontend | React 19 + React Router 7, Vite 8, TypeScript |
| Backend tests | Pest 4 (+ `pest-plugin-laravel`) |
| Frontend tests | Vitest 5 + React Testing Library, MSW for network mocking |
| Static analysis | Larastan (PHPStan) level 6 |
| Format | Laravel Pint |
| Deploy | Railway — two services from this repo, plus managed MySQL |

## Getting started

**Prerequisites:** PHP 8.3+, Node 22.12+, and a database (or SQLite for local work).
Composer is **not installed globally** here — the phar lives in the repo root and is invoked
as `php composer.phar`.

### Backend (repo root)

```bash
php composer.phar setup     # install deps, create .env, generate key, migrate, build assets
```

For a local SQLite database instead of MySQL, set `DB_CONNECTION=sqlite` in `.env` and
comment out the five `DB_*` lines, then `touch database/database.sqlite && php artisan migrate`.

```bash
php composer.phar dev       # concurrently: artisan serve + queue:listen + pail (logs) + vite
# or just the API:
php artisan serve           # http://localhost:8000
```

### Frontend (`frontend/`)

```bash
cd frontend
npm install
cp .env.example .env.local  # set VITE_API_BASE_URL, or leave empty for same-origin/proxy
npm run dev                 # http://localhost:5173
```

`VITE_API_BASE_URL` is inlined at **build** time, so it must be set before `npm run build`,
not at runtime. The backend's `FRONTEND_URL` must match the SPA origin — that is what CORS
allows.

### First run

Registration is open only until the first account exists. `POST /api/register` creates that
one account and then permanently closes (`UserRepository::createFirstUserOrNull` takes a
lock so two concurrent requests cannot both win). From then on, `POST /api/login`.

## Tests and quality gates

```bash
# backend
php composer.phar test                          # config:clear + php artisan test
php artisan test --filter=Name                  # a single test or class
php artisan test tests/Feature/Item/ClarifyItemTest.php
php composer.phar quality                       # pint --test + larastan + tests (the CI set)
./vendor/bin/pint                               # auto-format
./vendor/bin/phpstan analyse --memory-limit=512M

# frontend
cd frontend
npm test                                        # vitest run
npm run test:watch
npm run lint
npm run build                                   # tsc -b && vite build
```

Tests split as **Unit** (`tests/Unit/` — DTOs, services, the decision tree; no DB) and
**Feature** (`tests/Feature/` — booted app + SQLite in-memory via `RefreshDatabase`).
Frontend tests are colocated in `frontend/src`. Conventions: [`tests/CLAUDE.md`](tests/CLAUDE.md).

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs two parallel jobs, all of
which must pass before merge: Pint format check · Larastan level 6 · Pest (`--parallel`,
SQLite in-memory) · frontend lint + build + Vitest. Railway builds on push to `main`;
there is no deploy job in the workflow.

Tests are written against the PRD as the oracle, not against the implementation — see the
docblock at the top of `tests/Unit/Clarify/ClarifyDecisionTest.php`. Which risk each suite
defends is documented in [`context/foundation/test-plan.md`](context/foundation/test-plan.md).

## API surface

All routes are prefixed `/api`. Everything except health, login and register sits behind
`auth:sanctum` and is rate-limited.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/health` | public liveness probe |
| `POST` | `/api/register` | create the one account (closes afterwards) → `201` |
| `POST` | `/api/login` | exchange email + password for a bearer token → `200` |
| `GET` | `/api/me` | the authenticated user |
| `POST` | `/api/logout` | revoke the current token → `204` |
| `POST` | `/api/items` | capture free text; always lands in the Inbox → `201` |
| `GET` | `/api/items?bucket=` | list one bucket, newest first (defaults to the Inbox) |
| `POST` | `/api/items/{itemId}/clarify` | walk the decision tree and route the item out of the Inbox |
| `DELETE` | `/api/trash` | permanently empty the Trash → `{ "deleted": n }` |

Deliberate shapes worth knowing before you extend this:

- **Capture cannot choose its bucket.** A client-supplied `bucket` is ignored; the service
  applies the Inbox.
- **Clarify cannot choose its bucket either.** The destination is derived from the answers
  by `ClarifyDecision`, and the write is a single conditional `UPDATE … WHERE bucket = 'inbox'`
  so two concurrent clarifies cannot both succeed.
- **There is no per-item delete.** The only permanent discard is emptying the Trash, and
  `emptyTrash()` takes no arguments on purpose — a generic "delete this row" verb would
  become reachable from every bucket view.

The OpenAPI 3.1 spec is generated from the code by Scramble:

```bash
php artisan scramble:export     # writes api.json
```

## Observability

Requests carry a `request_id` (UUID v4, honoured from an incoming `X-Request-Id` only when
it is a valid UUID, and echoed back on the response). In production `LOG_CHANNEL=json`
emits single-line ECS JSON on stdout, with flat context keys mapped to ECS fields
(`user_id` → `user.id`, `request_id` → `http.request.id`) and sensitively-named keys
redacted. Domain events go through `App\Logging\LogEvent` with `domain.action.outcome`
naming — never a raw `Log::info`.

The user's captured text is treated as the most personal data in the app: a database
`QueryException` is never rethrown or chained, because Laravel interpolates the query
bindings into its message and that would carry the captured text past the redactor.

## Project status

Built and shipped: quality gates and CI, email + password auth, capture to Inbox, guided
clarify and routing, the two-minute-rule timer, all eight bucket views and emptying the Trash.

Next up (`proposed` in the roadmap): dates and the Calendar bucket, item metadata
(tags / contexts / priorities / flags), Eisenhower quadrants, the weekly review, and a
visual design pass. Slice-by-slice status lives in
[`context/foundation/roadmap.md`](context/foundation/roadmap.md).

## Documentation map

This project is generated from its written foundation, so the documents come first:

| File | What it holds |
| --- | --- |
| [`context/foundation/prd.md`](context/foundation/prd.md) | vision, persona, user stories, FR-001…FR-015, NFRs, access control, non-goals |
| [`context/foundation/shape-notes.md`](context/foundation/shape-notes.md) | the discovery conversation the PRD came out of |
| [`context/foundation/roadmap.md`](context/foundation/roadmap.md) | vertical slices, dependencies, status |
| [`context/foundation/test-plan.md`](context/foundation/test-plan.md) | risk map, per-risk response guidance, phased rollout |
| [`context/foundation/tech-stack.md`](context/foundation/tech-stack.md) | why this stack |
| [`context/foundation/infrastructure.md`](context/foundation/infrastructure.md) | platform comparison and the Railway decision |
| [`context/deployment/deploy-plan.md`](context/deployment/deploy-plan.md) | the two-service Railway deploy |
| [`context/foundation/lessons.md`](context/foundation/lessons.md) | recurring pitfalls worth surfacing in reviews |
| [`context/archive/`](context/archive/) | completed changes with their plans and implementation reviews |
| [`docs/reference/contract-surfaces.md`](docs/reference/contract-surfaces.md) | load-bearing names; renaming one is a breaking change |
| [`CLAUDE.md`](CLAUDE.md) · [`app/CLAUDE.md`](app/CLAUDE.md) · [`tests/CLAUDE.md`](tests/CLAUDE.md) | conventions for AI agents and humans alike |

## Conventions

Commits follow Conventional Commits — `<type>(TICKET): <description>`, branches
`type/TICKET-description`. Types: `feat`, `fix`, `refactor`, `test`, `chore`, `docs`,
`perf`, `ci`.

Before committing: tests pass · Pint clean · Larastan 0 errors · new features ship feature
**and** unit tests · `Response::HTTP_*` constants instead of integer status codes · Scramble
docs regenerated for new or changed endpoints.

## License

A personal project — no license file is committed. (`composer.json` still carries the Laravel
skeleton's MIT metadata; treat that as scaffold leftover, not a grant.)
