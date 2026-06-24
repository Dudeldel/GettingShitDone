# Contract surfaces

Load-bearing names other code/plans depend on. When you rename or change the shape of
one of these, treat it as a breaking change and update the consumers. `/10x-plan-review`
greps plans against the H2 headings below.

## json log channel

`config/logging.php` — production structured-logging channel (`LOG_CHANNEL=json`):
`StreamHandler` → `php://stdout` + `App\Logging\EcsFormatter` + processors
(`RedactSensitiveData`, `MapContextToEcs`, `PsrLogMessageProcessor`). Emits single-line
ECS JSON for the log shipper. Local/staging stay on the text `stack` channel.

## EcsFormatter

`app/Logging/EcsFormatter.php` — Monolog formatter emitting ECS-shaped single-line JSON
(`@timestamp`, `log.level`, `message`, `ecs.version`, mapped fields at top level, other
context under `labels`). Used by the `json` channel.

## RedactSensitiveData

`app/Logging/Processors/RedactSensitiveData.php` — Monolog processor; replaces values of
sensitively-named keys with `[REDACTED]` (recursive, case-insensitive) across context/extra.

## MapContextToEcs

`app/Logging/Processors/MapContextToEcs.php` — Monolog processor; maps flat context keys
to ECS field names (`request_id` → `http.request.id`, `user_id` → `user.id`) and drops the
source keys. Extend the `MAP` constant as new flat keys appear (e.g. when auth sets `user_id`).

## AssignRequestId

`app/Http/Middleware/AssignRequestId.php` — first middleware in the `api` group. Resolves a
request id (validated incoming `X-Request-Id` UUID, else a generated v4), binds it to the
container (`AssignRequestId::CONTAINER_KEY` = `current_request_id`) and `Log::shareContext`,
and echoes `AssignRequestId::HEADER` (`X-Request-Id`) on the response.

## current_request_id

Container binding holding the current request's id (set by `AssignRequestId`). Listed in
`config('octane.flush')` so it is forgotten between Octane requests. Read it via
`app('current_request_id')` for queue/job propagation once jobs land.

## LogEvent

`app/Logging/LogEvent.php` — single entry point for domain events (`LogEvent::emit(action,
category, outcome, context, level)`), building the ECS `event.*` envelope. Never use raw
`Log::info` for domain events. Slices add specific named methods (e.g. `itemClarified()`)
that delegate here.

## composer quality

`composer.json` script — local mirror of the CI gates: `pint --test` → `phpstan analyse`
(level 6) → `artisan test` (Pest), fail-fast. Run before pushing.

## CI gates (.github/workflows/ci.yml)

Backend job: Pint (format) → Larastan level 6 → Pest (`--parallel`, SQLite `:memory:`).
Frontend job: `npm ci` → lint → build. All must pass before merge.

## Auth endpoints

`routes/api.php` — Sanctum **Bearer-token** auth (single user). Public, rate-limited
(`throttle:login`): `POST /api/login` → `{token, user}`; `POST /api/register` → first-run
only (403 once an account exists). Behind `auth:sanctum`: `GET /api/me` → the user;
`POST /api/logout` → revokes the current token (204). Future protected endpoints (S-01
capture, …) join the `['auth:sanctum', LogContextMiddleware]` group.

## AuthService

`app/Services/AuthService.php` — orchestrates `login` / `register` (gated) / `me` /
`logout`. HTTP-free; signals failure with domain exceptions
(`InvalidCredentialsException` → 401, `RegistrationClosedException` → 403) mapped in
`bootstrap/app.php`. Built from `LoginPayload` / `RegisterPayload`.

## UserRepositoryInterface

`app/Domain/Auth/UserRepositoryInterface.php` (impl `app/Infrastructure/Auth/UserRepository.php`,
bound in `AppServiceProvider::register`). Confines Eloquent + Sanctum; returns DTOs/scalars,
never a Model. Token issuance/revocation live here. `revokeCurrentToken` resolves the user
via the **sanctum** guard (the default web guard is null on a token request).

## LogContextMiddleware

`app/Http/Middleware/LogContextMiddleware.php` — pushes `user_id` into `Log::shareContext`
(mapped to `user.id` by `MapContextToEcs`). Registered INSIDE the `auth:sanctum` group, so
it only runs once a user is resolved. Closes the F-03 observability remainder.

## Frontend auth (AuthContext / token)

`frontend/src/auth/` — `AuthProvider` + `useAuth` (in `context.ts`), `LoginPage`,
`ProtectedRoute`. Token stored in `localStorage` under key `gsd_token`; `api.ts` injects
`Authorization: Bearer` and clears the token + signals logout on any 401. `VITE_API_BASE_URL`
sets the API origin; CORS on the backend must allow the SPA origin (`FRONTEND_URL`).

## login rate limiter

`AppServiceProvider::boot()` — named `RateLimiter::for('login')`, 5/min keyed by email+IP,
applied via `throttle:login` on the login + register routes. Exceeding → 429.
