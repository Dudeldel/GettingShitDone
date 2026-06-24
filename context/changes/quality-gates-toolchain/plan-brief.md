# Quality Gates Toolchain (+ Observability Baseline) — Plan Brief

> Full plan: `context/changes/quality-gates-toolchain/plan.md`
> Roadmap: `context/foundation/roadmap.md` (F-01; observability remainder in F-03)

## What & Why

Install the three quality tools the project's conventions mandate but the scaffold
ships without — **Pest**, **Larastan (level 6)**, **Scramble** — and wire them into CI,
then build the buildable-now slice of the **observability baseline** (JSON/ECS logging,
request-id correlation, `LogEvent`, Octane-safe flush). This is roadmap **F-01**, first
because the sequencing goal is `quality` (gates before features); the observability work
was pulled forward because the #1 blocker is `skills` (Octane/Railway fluency) and
request-level logs are what make debugging the unfamiliar runtime tractable.

## Starting Point

The walking skeleton is live on Railway, but `composer.json` ships PHPUnit + Pint only;
CI has a reserved "Pest + Larastan slot" comment (`ci.yml:34`) and runs `php artisan
test` against the lone `/api/health` endpoint. No `phpstan.neon`, no `config/scramble.php`,
no `json` log channel, no `app/Http/Middleware/` dir. `config/octane.php` exists (Octane
is live). Only class-based `ExampleTest`s exist.

## Desired End State

Every push runs Pint → Larastan (0 errors) → Pest (`--parallel`) gates; `composer
quality` mirrors them locally in one command. `/docs/api` serves live OpenAPI with a
Bearer scheme. Every request carries an `X-Request-Id`, production logs are redacted
single-line ECS JSON on stdout, domain events go through `LogEvent`, and per-request
state does not leak across Octane workers.

## Key Decisions Made

| Decision                          | Choice                                            | Why (1 sentence)                                                              | Source |
| --------------------------------- | ------------------------------------------------- | ---------------------------------------------------------------------------- | ------ |
| Pest migration scope              | Convert ExampleTests + add real health test       | Leaves the suite in the `it()` convention with a genuine assertion.          | Plan   |
| Larastan first-run handling       | Fix to 0 errors (no baseline)                     | Honors the `CLAUDE.md` "0 errors" checklist; scaffold surface is tiny.       | Plan   |
| Larastan analysis scope           | `app/` only                                       | Matches documented usage; smallest clean surface to start.                   | Plan   |
| CI layout                         | Steps in the existing backend job                 | Matches the current file + reserved slot; one shared `composer install`.     | Plan   |
| Scramble                          | Install + configure only, **no CI gate**          | Scramble generates the spec on the fly at `/docs/api` — no artifact to gate. | Plan (user correction) |
| Local DX                          | `composer quality` aggregate script               | One command mirrors CI; no hook machinery to maintain.                       | Plan   |
| Scope expansion                   | Fold buildable-now observability (part of F-03) in | The `skills` blocker makes request correlation valuable now.                 | Plan (user) |
| Observability extent              | JSON/ECS + request-id + processors + LogEvent + Octane flush | Everything buildable without auth/jobs; matches `app/CLAUDE.md` spec. | Plan   |
| ECS implementation                | First-party formatter + 3 processors (no `elastic/ecs-logging`) | Matches the documented processor design without a new dependency.           | Plan   |

## Scope

**In scope:** Pest + Larastan L6 + Scramble install/config/CI; convert + add tests;
`json` log channel + `EcsFormatter` + redact/ECS-map processors; `AssignRequestId`
middleware + `X-Request-Id`; `LogEvent` helper; Octane per-request flush; `composer
quality` script.

**Out of scope:** `user_id` log context (needs F-02 auth); `request_id`→queued-job
propagation (no jobs in MVP); `CommandStarting` request-id listener; Scramble CI gate;
any GTD domain code; frontend changes; `pint.json`; PHPStan baseline file.

## Architecture / Approach

Three independent tool installs first (each CI-verifiable), so the full gate set is live
by Phase 3; then build the observability stack *under* those gates (it must pass
Larastan L6 and ship Pest tests — proving the gates work on real first-party code);
finish with the local mirror. `AssignRequestId` runs first in the `api` group; the
`json` channel = `StreamHandler(php://stdout)` + `EcsFormatter` + 3 processors; an
Octane `RequestTerminated` listener flushes per-request state.

## Phases at a Glance

| Phase                      | What it delivers                                          | Key risk                                                   |
| -------------------------- | -------------------------------------------------------- | --------------------------------------------------------- |
| 1. Pest                    | Pest runner, migrated + 1 real test, CI on Pest          | Mixed test styles if migration is half-done               |
| 2. Larastan L6             | `phpstan.neon`, 0 errors, CI gate                        | First-ever L6 run surfaces more scaffold findings than expected |
| 3. Scramble                | `/docs/api` live with Bearer scheme                      | Scramble config API shape for the security scheme         |
| 4. Observability baseline  | JSON/ECS logs, request-id, LogEvent, Octane flush        | **Per-request state leaking across Octane workers**       |
| 5. Local DX & consolidation| `composer quality`, full green CI, names registered      | None significant                                          |

**Prerequisites:** none (F-01 is the root foundation; deploy + CI scaffold already exist).
**Estimated effort:** ~2-3 focused sessions across 5 phases; Phase 4 is the largest.

## Open Risks & Assumptions

- Larastan level 6 has never run here — Phase 2 effort scales with how many findings the
  Octane/Sanctum scaffold surfaces (assumed few; fix-not-baseline).
- The Octane flush is the load-bearing correctness risk — must be verified with two
  sequential `octane:start` requests, and must no-op cleanly under `artisan serve`.
- Scramble's exact security-scheme configuration API is assumed stable; verify against
  the installed version during Phase 3.

## Success Criteria (Summary)

- `composer quality` exits 0 (Pint clean · Larastan 0 errors · Pest green) and CI mirrors it.
- `/docs/api` renders with a Bearer scheme; `/api/health` echoes `X-Request-Id`.
- Production-mode logs are redacted single-line ECS JSON; sequential Octane requests get
  distinct `request_id`s (no leak).
