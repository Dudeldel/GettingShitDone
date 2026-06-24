# Quality Gates Toolchain (+ Observability Baseline) Implementation Plan

## Overview

Install and wire the three quality tools the project's own conventions mandate but
that the scaffold ships without — **Pest**, **Larastan (level 6)**, and **Scramble** —
into GitHub Actions CI, then build the **buildable-now** slice of the observability
baseline (JSON/ECS structured logging, request-id correlation, the `LogEvent`
domain-event helper, and an Octane-safe per-request flush). The result: every push
runs format → static-analysis → test gates, the API self-documents at `/docs/api`,
and feature work lands on an unfamiliar Octane runtime with request-level
observability — directly targeting the `quality` sequencing goal and the `skills`
(#1) blocker recorded in the roadmap.

## Current State Analysis

- **`composer.json:15-23`** ships PHPUnit 12.5 + Pint only. Neither Pest, Larastan,
  nor Scramble is present. `pestphp/pest-plugin` is **pre-allowed** at
  `composer.json:82`, so Pest installs without an `allow-plugins` prompt.
- **Composer is not global** — must be invoked as `php composer.phar` from the repo
  root (the phar is committed, git-ignored deps). Plain `composer` fails.
- **`.github/workflows/ci.yml:31-39`** — the `backend` job runs Pint then
  `php artisan test` (SQLite `:memory:`); line 34 is a reserved comment:
  *"Pest + Larastan (level 6) slot in here once installed"*. The `frontend` job
  (`:41-57`) runs `npm ci && npm run lint && npm run build` — unchanged by this plan.
- **Tests**: only `tests/Feature/ExampleTest.php` (class-based, asserts `/` → 200) and
  `tests/Unit/ExampleTest.php` (extends `PHPUnit\Framework\TestCase` directly, asserts
  `true`). `tests/TestCase.php` is the empty base. No `Pest.php`.
- **Only one real endpoint exists**: `GET /api/health` → `HealthController`
  (`app/Http/Controllers/Api/V1/HealthController.php`), returning an inline JSON array.
  `routes/api.php:10-12` also has a `/user` probe behind `auth:sanctum`.
- **`bootstrap/app.php:15-17`** uses the slim skeleton — middleware is registered in
  the `->withMiddleware(...)` closure (currently empty). There is **no
  `app/Http/Middleware/` directory** yet. Exceptions already render JSON for `api/*`.
- **`app/Providers/AppServiceProvider.php:20-23`** — `boot()` is empty.
- **`config/logging.php`** has stock channels (`stack`, `single`, `daily`, `stderr`,
  …) — no `json`/ECS channel. **`config/octane.php` already exists** (Octane is
  installed and live on Railway/Swoole).
- No `phpstan.neon`, no `config/scramble.php`, no `pint.json` (Pint uses the Laravel
  preset by default — leave as-is).

### Key Discoveries:

- The CI reserved slot (`ci.yml:34`) and convention (`CLAUDE.md` "CI gates") fix the
  target exactly: Pint · Larastan **level 6** · **Pest** (`--parallel`) · frontend.
- The structured-logging design is fully specified in `app/CLAUDE.md` ("Structured
  logging") — `AssignRequestId` first in the `api` group, a `json` Monolog channel =
  `StreamHandler(php://stdout)` + ECS formatter + **3 processors** (redact / flat→ECS
  / interpolate), and the `LogEvent` helper (`domain.action.outcome`). It is
  **single-user adapted**: only `request_id` now; `user_id` waits for auth.
- Octane keeps the framework resident, so anything bound per-request
  (`current_request_id`, `Log::shareContext`) **must be flushed between requests** or
  it leaks across users of the same worker — the load-bearing gotcha of this change.

## Desired End State

A push to a branch / PR runs CI that fails on any of: Pint format drift, a Larastan
level-6 error in `app/`, or a failing/again-non-`it()` Pest test. `php artisan test`
(Pest) is green locally and in CI; `./vendor/bin/phpstan analyse` reports **0 errors**;
`composer quality` runs all three in one command. Hitting `/docs/api` renders the
OpenAPI UI with a Bearer security scheme. Every request gets an `X-Request-Id`
response header, production logs are single-line ECS JSON on stdout with secrets
redacted, domain events go through `LogEvent`, and none of the per-request state
leaks across Octane requests (verified by test).

Verify:
- `composer quality` exits 0 (Pint clean, Larastan 0 errors, Pest green).
- `curl -i /api/health` shows an `X-Request-Id` header echoed/generated.
- `LOG_CHANNEL=json php artisan tinker --execute="Log::info('t', ['password'=>'x'])"`
  emits one ECS JSON line with `password` redacted.
- `/docs/api` loads with a Bearer auth scheme.

## What We're NOT Doing

- **No `user_id` log context** — needs the authenticated user from **F-02** (auth).
  The flat→ECS processor will map `user_id` → `user.id` *when present*, but nothing
  sets it yet.
- **No `request_id → queued-job` propagation** (`Queue::createPayloadUsing`, queue
  middleware, `Queue::after/failing` flush) — background jobs are out of MVP scope
  (tech-stack.md). Deferred to F-03 / whenever jobs land.
- **No `CommandStarting` per-artisan request_id listener** — minor; deferred to F-03.
- **No Scramble CI gate** — Scramble generates the spec on the fly at `/docs/api`;
  there is no build artifact to gate (user decision). Per-endpoint doc annotations
  are added by the slices that introduce endpoints, not here.
- **No GTD domain code** — no models/migrations/DTOs/services beyond what already
  exists. The gates run against the health endpoint + the new logging classes.
- **No frontend changes** — `frontend` already gates on `lint` + `build` (tsc).
- **No `pint.json`** — the Laravel preset default stays.
- **No PHPStan baseline file** — Larastan starts at a true 0 errors (fix, don't
  grandfather).

## Implementation Approach

Sequence the three mandated tools first (each independently installable and
CI-verifiable), so by Phase 3 the full gate set is live; then build the observability
baseline *under* those gates (it must pass Larastan L6 and ship Pest tests — proving
the gates work on real first-party code); finish with the local `composer quality`
mirror and a full-green consolidation. Larastan is fixed to a genuine 0 errors rather
than baselined, honoring the `CLAUDE.md` "0 errors" checklist. The `json` channel is
dependency-free: a first-party `EcsFormatter` + 3 processors in `app/Logging/`,
matching the documented design without adding `elastic/ecs-logging`.

## Critical Implementation Details

- **Octane per-request state (verify-before-build).** `AssignRequestId` binds the id
  via `app()->instance('current_request_id', $id)` and `Log::shareContext(['request_id'
  => $id])`. The concern is leakage between requests on a resident Octane worker — BUT
  Octane's defaults likely already close this: `config/octane.php:73-75` runs
  `Octane::prepareApplicationForNextRequest()` on `RequestReceived` (which in current
  Octane includes a `FlushLogContext` listener resetting `Log::shareContext`), and
  `:105-107` runs `FlushTemporaryContainerInstances` on `OperationTerminated`
  (flushing per-request container bindings). On top of that, `AssignRequestId`
  overwrites both values at the start of every `api` request. **So do NOT add a custom
  flush by default.** First confirm against the installed `octane ^2.17` whether
  `FlushLogContext` is in `prepareApplicationForNextRequest()` and whether
  `current_request_id` is flushed by `FlushTemporaryContainerInstances`. Add a custom
  `RequestTerminated`/`OperationTerminated` listener (`app()->forgetInstance(
  'current_request_id')`, `Log::flushSharedContext()`, `Log::withoutContext()`) **only
  if** the two-sequential-requests verification (4.6) proves a real leak — e.g. for
  routes that bypass `AssignRequestId`. If added, guard it so it no-ops under
  `artisan serve` (Octane absent).
- **Request-id forgery guard.** `AssignRequestId` honors an incoming `X-Request-Id`
  **only if it is a valid UUID** (reject newlines / control chars / oversized strings
  that would enable log injection); otherwise generate a v4 UUID. Always echo the
  resolved id back on the response `X-Request-Id` header.
- **Middleware ordering.** `AssignRequestId` must be the **first** middleware in the
  `api` group (prepend) so even auth/bootstrap failures carry a `request_id`. Register
  with `$middleware->prependToGroup('api', AssignRequestId::class)` in
  `bootstrap/app.php`.
- **ECS shaping lives in the processor, not the formatter.** The `EcsFormatter` emits
  ECS top-level keys (`@timestamp`, `log.level`, `message`, `ecs.version`); the
  flat→ECS processor maps flat context keys (`request_id`→`http.request.id`,
  `user_id`→`user.id`) and removes the source keys. Redaction runs as its own
  processor over the whole record (case-insensitive, recursive).

## Phase 1: Pest

### Overview

Make Pest the test runner, migrate the example tests to the `it()` convention, and add
a real Feature test against `/api/health`, then point CI at Pest.

### Changes Required:

#### 1. Install Pest

**File**: `composer.json` (require-dev) + lockfile

**Intent**: Add Pest as the dev test runner (the plugin is already allowed). Run
`php composer.phar require pestphp/pest pestphp/pest-plugin-laravel --dev` then
`php artisan pest:install` to generate `tests/Pest.php`.

**Contract**: `require-dev` gains `pestphp/pest` (^3) and `pestphp/pest-plugin-laravel`.
`./vendor/bin/pest` exists. `php artisan test` is delegated to Pest. **Note:**
`--parallel` (used in CI + `composer quality`) needs `brianium/paratest` — if it isn't
pulled transitively, add `brianium/paratest --dev`, or drop `--parallel` until the
suite is large enough to benefit.

#### 2. Bind the test case + RefreshDatabase in `tests/Pest.php`

**File**: `tests/Pest.php`

**Intent**: Bind `Tests\TestCase` to the `Feature` (and `Unit` where it needs the app)
suites and apply `RefreshDatabase` to Feature tests, via `use`, never inline FQN
(`tests/CLAUDE.md`).

**Contract**: `uses(Tests\TestCase::class)->in('Feature')`;
`uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature')` — imported
with `use` statements at the top.

#### 3. Migrate the example tests

**File**: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`

**Intent**: Rewrite both in `it('...')` style describing observable behaviour; delete
the class-based PHPUnit versions. Replace the trivial Unit assertion with a genuine
unit-level check (keep it real, not `true is true`).

**Contract**: Each file uses `it(...)` closures; no `class … extends TestCase`. Feature
test asserts via `expect()` / `$this->getJson()`.

#### 4. Real health-endpoint Feature test

**File**: `tests/Feature/HealthEndpointTest.php`

**Intent**: Prove the gate end-to-end against the one existing endpoint.

**Contract**: `it('responds ok on the health endpoint', …)` calls
`$this->getJson('/api/health')`, asserts `Response::HTTP_OK` (constant, not `200` —
`tests/CLAUDE.md`) and the JSON shape (`status => ok`). `Response` imported via `use`.

#### 5. Point CI at Pest

**File**: `.github/workflows/ci.yml`

**Intent**: Replace the `php artisan test` step with Pest in parallel; keep the SQLite
`:memory:` env.

**Contract**: backend job step runs `./vendor/bin/pest --parallel` (or
`php artisan test --parallel`) under `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.

### Success Criteria:

#### Automated Verification:

- Pest installed: `./vendor/bin/pest --version` succeeds
- Suite green locally: `php artisan test`
- Suite green in parallel: `./vendor/bin/pest --parallel`
- Pint clean: `./vendor/bin/pint --test`

#### Manual Verification:

- No class-based `ExampleTest` files remain; tests read as `it('...')` behaviour specs
- The health Feature test fails if `/api/health` is broken (sanity-check by temporarily breaking it)

**Implementation Note**: After this phase and all automated verification passes, pause for manual confirmation before Phase 2.

---

## Phase 2: Larastan level 6

### Overview

Add Larastan, configure level 6 over `app/`, drive the scaffold to **0 errors**, and
gate CI.

### Changes Required:

#### 1. Install Larastan

**File**: `composer.json` (require-dev) + lockfile

**Intent**: `php composer.phar require larastan/larastan --dev` (pulls PHPStan).

**Contract**: `./vendor/bin/phpstan` exists.

#### 2. `phpstan.neon`

**File**: `phpstan.neon`

**Intent**: Level 6 over first-party `app/` only (matches `app/CLAUDE.md`'s documented
usage), including the Larastan extension.

**Contract**:
```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 6
    paths:
        - app
```

#### 3. Resolve scaffold findings to zero

**File**: various under `app/` (only if the first run reports errors)

**Intent**: Fix each level-6 finding with the minimal correct change — add a missing
return type, a generic annotation (`@return Collection<int, …>`), or a model
`@property` PHPDoc per `app/CLAUDE.md`. **No baseline file.** If a finding is a genuine
framework-stub gap, prefer a typed annotation over an ignore; use `ignoreErrors` only
as a last resort with a one-line justification comment.

**Contract**: `./vendor/bin/phpstan analyse --memory-limit=512M` → `[OK] No errors`.

#### 4. CI step

**File**: `.github/workflows/ci.yml`

**Intent**: Add a Larastan step in the backend job (after Pint, before/with Pest),
reusing the same `composer install`.

**Contract**: step runs `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress`.

### Success Criteria:

#### Automated Verification:

- Static analysis clean: `./vendor/bin/phpstan analyse --memory-limit=512M` → 0 errors
- Pint still clean: `./vendor/bin/pint --test`
- Pest still green: `php artisan test`

#### Manual Verification:

- No `phpstan-baseline.neon` was created (errors fixed, not grandfathered)
- Spot-check that fixes are real type improvements, not blanket `ignoreErrors`

**Implementation Note**: Pause for manual confirmation before Phase 3.

---

## Phase 3: Scramble

### Overview

Install Scramble, configure it, and add the Sanctum Bearer security scheme so
`/docs/api` serves live OpenAPI. No CI gate.

### Changes Required:

#### 1. Install + publish config

**File**: `composer.json` + `config/scramble.php`

**Intent**: `php composer.phar require dedoc/scramble`, then publish
`config/scramble.php`.

**Contract**: `config/scramble.php` present; `/docs/api` route registered by the
package.

#### 2. Bearer (Sanctum) security scheme

**File**: `app/Providers/AppServiceProvider.php` (`boot()`)

**Intent**: Register a global Bearer security scheme so protected endpoints document
the Sanctum token (`app/CLAUDE.md` "Scramble"), and restrict docs exposure to non-prod.

**Contract**: in `boot()`, register a Scramble document transformer that adds an
`http`/`bearer` security scheme to the OpenAPI document and sets it as the default
requirement — for `dedoc/scramble` this is
`Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) { … })`
where the closure does
`$openApi->components->securitySchemes['bearer'] = SecurityScheme::http('bearer')` (or
the equivalent `secure(...)` helper) — **verify the exact class/method names against the
installed Scramble version** during this phase, as the transformer API has shifted
across releases. Classes (`OpenApi`, `SecurityScheme`, `Scramble`) imported via `use`.
Restrict `/docs` to non-production by gating Scramble's route/middleware in
`config/scramble.php` (e.g. only enable the docs UI when `app()->environment` is not
`production`).

#### 3. Health endpoint annotation (demonstrative)

**File**: `app/Http/Controllers/Api/V1/HealthController.php`

**Intent**: Add the controller summary + `@return JsonResponse<…>` PHPDoc Scramble
reads (establishes the per-endpoint pattern future slices follow). Keep the inline
array response — no DTO required for a liveness probe; mark `@unauthenticated`.

**Contract**: PHPDoc first line = summary; `@unauthenticated`; no behavioural change.

### Success Criteria:

#### Automated Verification:

- Spec builds without error: `php artisan scramble:export` exits 0 (sanity, not a gate)
- Larastan still 0 errors; Pint clean; Pest green

#### Manual Verification:

- `/docs/api` renders the UI with the health endpoint and a Bearer auth scheme
- The `/user` (auth:sanctum) route shows as secured in the docs

**Implementation Note**: Pause for manual confirmation before Phase 4.

---

## Phase 4: Observability baseline (buildable-now)

### Overview

Add the structured-logging stack: a `json`/ECS channel, the request-id middleware, the
`LogEvent` helper, and the Octane-safe per-request flush. Single-user adapted —
`request_id` now, `user_id` mapped-if-present (set later by F-02).

### Changes Required:

#### 1. `json` log channel

**File**: `config/logging.php`

**Intent**: Add a `json` channel = Monolog `StreamHandler` on `php://stdout` with the
first-party `EcsFormatter` and the 3 processors. Production sets `LOG_CHANNEL=json`
(documented in `.env.example`); local/staging stay text.

**Contract**: new `channels.json` entry (driver `monolog`, `StreamHandler` →
`php://stdout`, `formatter` = `App\Logging\EcsFormatter`, `processors` = the three
below). `.env.example` notes `LOG_CHANNEL` per env.

#### 2. ECS formatter + 3 processors

**File**: `app/Logging/EcsFormatter.php`, `app/Logging/Processors/RedactSensitiveData.php`,
`app/Logging/Processors/MapContextToEcs.php`, (interpolation via Monolog's
`PsrLogMessageProcessor`)

**Intent**: `EcsFormatter` emits ECS-shaped single-line JSON. `RedactSensitiveData`
replaces values of sensitively-named keys (`authorization, password, token, secret,
cookie, …` per `app/CLAUDE.md`) with `[REDACTED]`, recursive + case-insensitive.
`MapContextToEcs` maps flat keys (`request_id`→`http.request.id`, `user_id`→`user.id`)
and drops the source keys.

**Contract**: formatter implements Monolog `FormatterInterface`; processors are
invokable (`__invoke(LogRecord $record): LogRecord`). No request/global state held.

#### 3. `AssignRequestId` middleware

**File**: `app/Http/Middleware/AssignRequestId.php` (new dir)

**Intent**: Resolve a request id (validated incoming `X-Request-Id` UUID, else new v4),
bind it to `current_request_id` + `Log::shareContext(['request_id' => $id])`, and echo
`X-Request-Id` on the response.

**Contract**: `handle(Request, Closure): Response`; UUID validation rejects non-UUID
input; sets the response header. Registered first in the `api` group (next change).

#### 4. Register middleware

**File**: `bootstrap/app.php`

**Intent**: Prepend `AssignRequestId` to the `api` group so it runs before auth.

**Contract**: `->withMiddleware(fn (Middleware $m) =>
$m->prependToGroup('api', AssignRequestId::class))`; imported via `use`.

#### 5. Octane per-request state — verify, build only if a gap is proven

**File**: `config/octane.php` (listeners) and/or `app/Providers/AppServiceProvider.php`
— **only if step 4.6 proves a leak**

**Intent**: Confirm Octane's defaults already reset `Log::shareContext`
(`prepareApplicationForNextRequest` → `FlushLogContext`) and `current_request_id`
(`FlushTemporaryContainerInstances`) between requests. Since `AssignRequestId` also
overwrites both every `api` request, no custom flush is expected. Add one **only if**
the two-sequential-requests check (4.6) shows a real leak.

**Contract**: if needed, a listener on the Octane `RequestTerminated` event calling
`app()->forgetInstance('current_request_id')`, `Log::flushSharedContext()`,
`Log::withoutContext()`, guarded to no-op when Octane is absent. Otherwise this step is
a verified no-op (document the finding in the commit). See Critical Implementation
Details.

#### 6. `LogEvent` helper

**File**: `app/Logging/LogEvent.php`

**Intent**: Static helper for domain events (`domain.action.outcome`), building
`event.*` fields (`action`, `category`, `outcome`; optional `reason`, `kind`,
`durationNs`). Seed with one generic method usable now; slices add specific methods
(e.g. `itemClarified`) later.

**Contract**: `LogEvent::emit(string $action, string $category, string $outcome,
array $context = [])` (or similar) routed to the appropriate level; PHPDoc `@param`
per arg. No raw `Log::info` for domain events anywhere. **Thin seam:** ships with one
generic method + a unit test only — there is no domain caller in this change yet;
slices add specific methods (e.g. `itemClarified`) as they introduce domain events.

### Success Criteria:

#### Automated Verification:

- Larastan 0 errors over the new `app/Logging` + `app/Http/Middleware` code
- Pest green incl. new tests: request-id middleware sets/echoes `X-Request-Id` and
  rejects a forged (non-UUID) incoming id; `RedactSensitiveData` redacts a known key;
  `MapContextToEcs` maps `request_id`→`http.request.id`
- Pint clean
- `composer quality` (added Phase 5) — deferred to Phase 5

#### Manual Verification:

- `LOG_CHANNEL=json` emits one-line ECS JSON on stdout with secrets redacted
- `curl -i /api/health` returns an `X-Request-Id` header
- Under `php artisan octane:start`, two sequential requests get **different**
  `request_id`s (no leak) — the load-bearing Octane check

**Implementation Note**: Pause for manual confirmation before Phase 5.

---

## Phase 5: Local DX & consolidation

### Overview

Add the one-command local mirror of the CI gates and verify the full suite is green.

### Changes Required:

#### 1. `composer quality` script

**File**: `composer.json` (scripts)

**Intent**: One command that mirrors CI: Pint check → Larastan → Pest (`CLAUDE.md`:
"Mirror this as a local pre-push check").

**Contract**: `scripts.quality` runs `./vendor/bin/pint --test`,
`./vendor/bin/phpstan analyse --memory-limit=512M`, and `php artisan test` in sequence,
failing fast.

#### 2. Final CI review

**File**: `.github/workflows/ci.yml`

**Intent**: Confirm the backend job runs Pint → Larastan → Pest (parallel) and the
reserved comment at the old `:34` is resolved; frontend job untouched.

**Contract**: backend job has three green gate steps; no leftover placeholder comment.

#### 3. Register load-bearing names

**File**: `docs/reference/contract-surfaces.md` (create if absent)

**Intent**: Record the new load-bearing names so future slices reuse them: `EcsFormatter`,
`AssignRequestId`, `LogEvent`, the `json` channel, `current_request_id`, `composer quality`.

**Contract**: a short section listing each name + its file + one-line purpose.

### Success Criteria:

#### Automated Verification:

- `composer quality` exits 0
- A pushed branch shows all CI gates green (Pint, Larastan, Pest, frontend)

#### Manual Verification:

- `composer quality` output clearly shows all three gates running and passing
- `docs/reference/contract-surfaces.md` exists and lists the new load-bearing names

**Implementation Note**: Final phase — confirm the full green run before marking the change done.

---

## Testing Strategy

### Unit Tests:

- `RedactSensitiveData` redacts sensitive keys (case-insensitive, nested) and leaves
  others intact
- `MapContextToEcs` maps `request_id`→`http.request.id`, `user_id`→`user.id`, drops
  source keys
- `LogEvent` builds the expected `event.*` structure for a sample event

### Integration Tests:

- `GET /api/health` returns `Response::HTTP_OK` and the expected JSON shape
- A request without `X-Request-Id` gets a generated UUID echoed on the response
- A request with a **forged** (non-UUID) `X-Request-Id` is replaced, not trusted

### Manual Testing Steps:

1. `composer quality` — all three gates pass.
2. `LOG_CHANNEL=json php artisan tinker` → `Log::info('hi', ['password' => 'secret',
   'request_id' => 'abc'])` → one ECS JSON line, `password` redacted, `http.request.id`
   present.
3. `php artisan octane:start`, curl `/api/health` twice → two distinct `X-Request-Id`s.
4. Open `/docs/api` → UI renders with a Bearer scheme; `/user` shows as secured.

## Performance Considerations

Negligible at single-user scale. The request-id middleware is O(1); the JSON formatter
+ processors run once per log record. Larastan/Pest cost is CI-time only. Pest
`--parallel` keeps the suite fast as it grows.

## Migration Notes

No data migration. The only runtime behavioural change is the `json` log channel,
gated behind `LOG_CHANNEL=json` (production only — set on Railway). Local/staging keep
the text `stack` channel, so developer logs are unchanged. Larastan fixes are
type-annotation-only; no behavioural change.

## References

- Roadmap: `context/foundation/roadmap.md` (F-01; observability remainder in F-03)
- Conventions: `app/CLAUDE.md` (Larastan / Scramble / Structured logging), `tests/CLAUDE.md` (Pest)
- CI reserved slot: `.github/workflows/ci.yml:34`
- Health endpoint: `app/Http/Controllers/Api/V1/HealthController.php`
- Middleware registration: `bootstrap/app.php:15-17`
- Infra risks (Octane/Swoole): `context/foundation/infrastructure.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Pest

#### Automated

- [x] 1.1 Pest installed: `./vendor/bin/pest --version` succeeds — 83adfec
- [x] 1.2 Suite green locally: `php artisan test` — 83adfec
- [x] 1.3 Suite green in parallel: `./vendor/bin/pest --parallel` — 83adfec
- [x] 1.4 Pint clean: `./vendor/bin/pint --test` — 83adfec

#### Manual

- [x] 1.5 No class-based `ExampleTest` files remain; tests read as `it('...')` specs — 83adfec
- [x] 1.6 Health Feature test fails if `/api/health` is broken (sanity check) — 83adfec

### Phase 2: Larastan level 6

#### Automated

- [x] 2.1 Static analysis clean: `phpstan analyse --memory-limit=512M` → 0 errors — aeae4e9
- [x] 2.2 Pint still clean: `./vendor/bin/pint --test` — aeae4e9
- [x] 2.3 Pest still green: `php artisan test` — aeae4e9

#### Manual

- [x] 2.4 No `phpstan-baseline.neon` created (errors fixed, not grandfathered) — aeae4e9
- [x] 2.5 Fixes are real type improvements, not blanket `ignoreErrors` — aeae4e9

### Phase 3: Scramble

#### Automated

- [x] 3.1 Spec builds: `php artisan scramble:export` exits 0 (sanity) — 74552e9
- [x] 3.2 Larastan 0 errors; Pint clean; Pest green — 74552e9

#### Manual

- [x] 3.3 `/docs/api` renders with health endpoint + Bearer scheme — 74552e9
- [x] 3.4 `/user` (auth:sanctum) shows as secured in the docs — 74552e9

### Phase 4: Observability baseline

#### Automated

- [x] 4.1 Larastan 0 errors over new `app/Logging` + `app/Http/Middleware` — 1c1d685
- [x] 4.2 Pest green incl. request-id (set/echo/forgery), redaction, ECS-mapping tests — 1c1d685
- [x] 4.3 Pint clean — 1c1d685

#### Manual

- [x] 4.4 `LOG_CHANNEL=json` emits one-line ECS JSON with secrets redacted — 1c1d685
- [x] 4.5 `curl -i /api/health` returns an `X-Request-Id` header — 1c1d685
- [x] 4.6 Two sequential Octane requests get different `request_id`s (no leak) — accepted on design guarantee (FlushLogContext + octane.flush + per-request overwrite); verify on deployed Octane runtime — 1c1d685

### Phase 5: Local DX & consolidation

#### Automated

- [x] 5.1 `composer quality` exits 0
- [ ] 5.2 Pushed branch shows all CI gates green (Pint, Larastan, Pest, frontend)

#### Manual

- [x] 5.3 `composer quality` output shows all three gates running and passing
- [x] 5.4 `contract-surfaces.md` lists the new load-bearing names
