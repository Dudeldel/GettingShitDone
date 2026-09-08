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
`POST /api/logout` → revokes the current token (204). Protected endpoints live in the
`['auth:sanctum', 'throttle:api', LogContextMiddleware]` group — S-01's item endpoints
joined it; later slices do the same.

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

## Item endpoints

`routes/api.php` — inside the `['auth:sanctum', 'throttle:api', LogContextMiddleware]` group.
`POST /api/items` → **201** with an `ItemDto`; body is `title` (required, ≤ `ItemConst::TITLE_MAX_LENGTH`)
and `note` (optional, ≤ `ItemConst::NOTE_MAX_LENGTH`), both scrubbed of control characters in
`CaptureItemRequest::prepareForValidation()`. The destination is **not** client input — `bucket`
in the body is ignored and `ItemService` applies `GtdBucket::Inbox`.
`GET /api/items?bucket=<value>` → **200** with a JSON array of `ItemDto`, newest first; `bucket`
is optional (`nullable`, so the OpenAPI parameter is `required: false`) and resolves to
`GtdBucket::default()` when absent. Unknown value → 422. Both endpoints: 401 without a Bearer
token, 429 past 60 req/min.

## GtdBucket

`app/Domain/Item/GtdBucket.php` — backed string enum; the routing vocabulary S-02…S-08 build on:
`inbox`, `next_actions`, `projects`, `calendar`, `delegation`, `someday_maybe`, `reference`,
`trash`. Persisted as a plain indexed `string` column, **not** a native MySQL enum: SQLite (the
whole test suite) does not enforce native enums, so a DB-level guarantee would exist only in
production. `GtdBucket::default()` is where an unspecified bucket resolves (Inbox) — it lives on
the enum so changing it stays a domain decision. Mirrored in TypeScript as the `GtdBucket` union
in `frontend/src/api.ts`; the per-case PHPDoc becomes each value's OpenAPI description.

## ItemDto

`app/Dto/ItemDto.php` — `id`, `title`, `note`, `bucket` (serialized as the backing string; the
constructor property is the enum), `dueDate`, `tags`, `context`, `important`, `urgent`,
`createdAt`, `updatedAt` (ISO-8601). The five metadata fields are **read-only and always null**
until S-06 (FR-011, dates) and S-07 (FR-013, tags/contexts/flags) add their write paths — they
ship early so the client shape stays stable. camelCase in (`fromArray`) and out (`toArray`).
Mirrored field-for-field by the `Item` interface in `frontend/src/api.ts`.

## ItemRepositoryInterface

`app/Domain/Item/ItemRepositoryInterface.php` (impl `app/Infrastructure/Item/ItemRepository.php`,
bound in `AppServiceProvider::register`). Two methods: `create(CaptureItemPayload, GtdBucket)` and
`listByBucket(GtdBucket)`. Returns DTOs only — no Model leaves `app/Infrastructure/`. Ordering is
`created_at desc, id desc`; the `id` tiebreaker keeps it deterministic when two rows share a
second, and the composite `(bucket, created_at, id)` index serves filter + ordering without a
filesort. **No pagination or limit** — deliberately deferred per PRD Open Question #2 ("list-view
responsiveness target"), revisit if volume grows.

## ItemPersistenceException

`app/Exceptions/ItemPersistenceException.php` — thrown by `ItemRepository::create` in place of a
`QueryException`, mapped to a fixed-message **500** in `bootstrap/app.php`. Carries only the
driver SQLSTATE and never chains the original as `previous`: Laravel interpolates query bindings
into a `QueryException`'s message, so rethrowing it would put the user's captured text into the
error log, where `RedactSensitiveData` cannot reach it (that processor redacts by context key,
not by message content).

## LogEvent item events

`app/Logging/LogEvent.php` — `itemCaptured(int, GtdBucket)` emits `item.captured.success`
(category `database`, outcome `success`, context `item_id` + `bucket`) at info level;
`itemCaptureFailed(GtdBucket, string $sqlState)` emits `item.captured.failure` at error level with
context `bucket` + `reason`. Neither ever carries the captured text.

## api rate limiter

`AppServiceProvider::boot()` — named `RateLimiter::for('api')`, 60/min keyed by user id, falling
back to IP; applied via `throttle:api` on the authenticated group. Note Laravel's middleware
priority runs `Authenticate` **ahead of** `ThrottleRequests`, so this caps an authenticated or
leaked-token caller; it does not shield the token lookup from an unauthenticated flood.

## Frontend capture draft

`frontend/src/items/CaptureForm.tsx` — the capture field is mirrored to `sessionStorage` under
`gsd_capture_draft` on every change and cleared only after a confirmed 201. This exists because a
401 clears the token, flips the app to logged-out and unmounts the form before an error can paint;
without the draft the typed idea would be lost, breaking the PRD guardrail "capture never loses an
entry". Do not remove it without replacing the guarantee. `frontend/src/items/InboxPage.tsx` is the
`/` screen (named export, replaced the scaffold `App`); its load effect merges by id rather than
replacing, so a capture landing mid-load is not erased.
