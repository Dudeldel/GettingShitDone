# Email + Password Authentication (Sanctum Bearer) Implementation Plan

## Overview

Give the single user email+password sign-in via Sanctum **personal-access tokens
(Bearer)**, protect the API behind an `auth:sanctum` route group, and add a React
login flow (router + auth state + token storage). This is roadmap **F-02** — the
foundation that unlocks the north star **S-01 `capture-to-inbox`** and every
data-bearing slice. It also closes the small **F-03** remainder by wiring `user_id`
into log context once an authenticated user exists.

## Current State Analysis

- **Sanctum installed, scaffold-only.** `laravel/sanctum ^4.3` in `composer.json`;
  `HasApiTokens` on `app/Models/User.php`; `routes/api.php:10-12` guards a `GET /user`
  closure with `auth:sanctum`. **No `config/sanctum.php`, no `config/cors.php`, no
  `statefulApi()`** — so only token (Bearer) auth would work as-is, which matches the
  chosen model.
- **Users table is the Laravel default** (`name, email, password, remember_token,
  email_verified_at`; `password` cast `hashed`, `email`/`password` fillable). No
  domain migration needed; the account is created by a gated first-run register.
- **No backend layering yet** — `app/Services`, `app/Dto`, `app/Domain`,
  `app/Infrastructure`, `app/Http/Requests` are empty/absent. This change introduces
  the first Service + Repository + DTO + FormRequest stack (per `app/CLAUDE.md`).
- **Observability baseline (F-01) is live**: `MapContextToEcs` already maps
  `user_id` → `user.id` *if present*, but nothing sets `user_id` yet. `AssignRequestId`
  is first in the `api` group; the `json` channel + `LogEvent` exist.
- **Frontend has no auth surface**: `frontend/src/api.ts` is a bare `fetch` wrapper
  (no auth header), `App.tsx` renders the health screen, **no router** (react-router
  not installed), no auth state.
- **Two-origin deploy**: SPA and API are separate `*.up.railway.app` services; CORS
  currently `*`. Token model avoids cookie/CSRF cross-subdomain issues; CORS must be
  tightened to the frontend origin.

### Key Discoveries:

- `routes/api.php:10-12` — the `/user` closure is the seed to formalize into `GET /me`.
- `app/CLAUDE.md` — DB-state checks belong in the **Service**, not the FormRequest; so
  the register zero-users gate lives in `AuthService` (throws → `HTTP_FORBIDDEN`).
- `app/CLAUDE.md` "Structured logging" — `LogContextMiddleware` adds `user_id` to
  `Log::shareContext`; it must run **after** `auth:sanctum`, so it lives **inside** the
  protected route group, not globally.
- `bootstrap/app.php:15-17` — middleware groups are configured here; the protected
  group will be expressed in `routes/api.php` via `->middleware([...])`.
- Token model → `config/cors.php` `supports_credentials` stays **false** (no cookies).

## Desired End State

`POST /api/login` with correct credentials returns a Bearer token + the user; wrong
credentials → 401; too many attempts → 429. `POST /api/register` creates the account
**only while zero users exist**, otherwise 403. `POST /api/logout` (authed) revokes the
calling token. `GET /api/me` (authed) returns the user; unauthenticated → 401. The SPA
has a `/login` page; on success it stores the token in localStorage, attaches it as
`Authorization: Bearer`, and routes to a protected area; a 401 clears the token and
returns to `/login`. Authenticated request logs carry `user.id`. CORS allows only the
frontend origin.

Verify: `composer quality` green; `npm run build` + `npm run lint` green; manual SPA
login → `/me` round-trip works cross-origin.

## What We're NOT Doing

- **No open registration / multi-user.** Register is gated to first-run only; multi-user
  is a permanent non-goal (PRD).
- **No cookie/stateful SPA auth, no CSRF, no `statefulApi()`** — token model chosen.
- **No password reset, email verification, OAuth, or magic link** — v2 / PRD Non-Goals.
- **No "log out everywhere"** — logout revokes only the current token.
- **No GTD/domain endpoints** — only the auth surface + a formalized `/me`; S-01 adds
  capture later onto the established protected group.
- **No token-expiry/refresh scheme** — Sanctum default (non-expiring) for the MVP;
  revisit if needed.
- **No frontend component library / styling system** — a minimal functional login form.

## Implementation Approach

Backend first (Phase 1): stand up the layered auth stack and config so the API is
real and tested, including the `user_id` log tie-in. Then the frontend (Phase 2)
consumes it: router + auth context + token storage + login page. Finally integration
(Phase 3): tighten and verify CORS cross-origin, run the end-to-end login round-trip,
and update docs. Auth follows the strict layering from `app/CLAUDE.md`: FormRequest →
Controller → Service → Repository(interface→impl) → Model, with DTOs between layers and
the Eloquent model confined to the repository.

## Critical Implementation Details

- **`LogContextMiddleware` ordering.** It must resolve `auth()->id()` *after*
  `auth:sanctum` authenticates, so it belongs **inside** the protected route group
  (e.g. `Route::middleware(['auth:sanctum', LogContextMiddleware::class])`), never as a
  global `api` middleware. `AssignRequestId` stays first (already prepended). Under
  Octane, `Log::shareContext` is reset per request by the built-in `FlushLogContext`
  (verified in F-01), so no extra flush is needed for `user_id`.
- **Register gate is a DB-state check → Service.** `AuthService::register` checks
  "any user exists?" and throws a domain exception the controller maps to
  `Response::HTTP_FORBIDDEN`. Do not put the gate in the FormRequest (per `app/CLAUDE.md`).
- **Token issuance/revocation stay in the Repository.** `createToken()` /
  `currentAccessToken()->delete()` need the Eloquent model; keep them in
  `UserRepository` (Infrastructure) so the model never leaks past it. The repo returns
  the plain-text token string + a `UserDto`.
- **CORS two-origin.** `config/cors.php` `allowed_origins` = the frontend origin
  (env-driven, e.g. `FRONTEND_URL`), `paths` includes `api/*`, `supports_credentials`
  false (Bearer, no cookies). Changing the frontend domain means updating this env.

## Phase 1: Backend auth core

### Overview

Layered auth endpoints (login / gated register / logout / me), Sanctum + CORS config,
login rate-limiting, and the `user_id` log-context tie-in, with feature + unit tests.

### Changes Required:

#### 1. CORS config

**File**: `config/cors.php`

**Intent**: Publish the first-party CORS config with `php artisan config:publish cors`
and restrict `allowed_origins` to the frontend origin via env; cover `api/*`;
`supports_credentials` false (Bearer, no cookies). Sanctum needs **no** published config
or `config/auth.php` guard entry for token auth — the `sanctum` guard auto-registers and
defaults apply, so `config/sanctum.php` is **not** published here (add it later only if a
token expiry is wanted).

**Contract**: `config/cors.php` `paths` includes `api/*`; `allowed_origins` reads an env
(e.g. `FRONTEND_URL`) with a sensible local default; `allowed_methods`/`headers` permit
JSON + `Authorization`; `supports_credentials` false. `.env.example` documents `FRONTEND_URL`.

#### 2. DTOs + Payloads

**File**: `app/Dto/UserDto.php`, `app/Dto/AuthResultDto.php`,
`app/Dto/Payload/LoginPayload.php`, `app/Dto/Payload/RegisterPayload.php`

**Intent**: `UserDto` (id, name, email) and `AuthResultDto` (token + UserDto) carried
service → controller. `LoginPayload` (email, password) and `RegisterPayload` (name,
email, password) are the command objects the controller builds from
`FormRequest::validated()` and passes into the Service (per `app/CLAUDE.md` command-object
convention — `app/Dto/Payload/`). All implement `fromArray`/`toArray`/`jsonSerialize`
(camelCase); the response DTOs (`UserDto`, `AuthResultDto`) also add `Arrayable` + typed
`@return array{...}` for Scramble.

**Contract**: controller does
`AuthService::login(LoginPayload::fromArray($request->validated()))`; Model→DTO
construction happens in the repository, not the DTO; all objects stay logic-free per
`app/CLAUDE.md`.

#### 3. FormRequests

**File**: `app/Http/Requests/LoginRequest.php`, `app/Http/Requests/RegisterRequest.php`

**Intent**: Validate credentials. Array-form rules (per convention). `LoginRequest`:
`email` (required,email), `password` (required,string). `RegisterRequest`: `name`,
`email` (required,email,unique:users), `password` (required, confirmed, min). Sanitize
free-text via `prepareForValidation()` where relevant.

**Contract**: rules return arrays, not pipe-strings; no DB-state gate here (that's the
Service).

#### 4. UserRepository (interface + impl)

**File**: `app/Domain/Auth/UserRepositoryInterface.php`,
`app/Infrastructure/Auth/UserRepository.php`

**Intent**: Encapsulate Eloquent + Sanctum. Methods: `anyUserExists(): bool`,
`findByEmail(string): ?UserDto` (or a credential-verify helper), `create(RegisterPayload):
UserDto`, `issueToken(userId): string`, `revokeCurrentToken(User): void`. Returns
DTOs/scalars — never a Model past this boundary.

**Contract**: implements the interface; bound in a service provider (or
`AppServiceProvider::register`). Token creation via `$user->createToken('spa')->plainTextToken`.

#### 5. AuthService

**File**: `app/Services/AuthService.php`

**Intent**: Orchestrate login (verify credentials via `Hash::check`/`Auth`, issue
token), register (**gate: throw if `anyUserExists()`**, else create + issue), logout
(revoke current token). No `Illuminate\Http` import.

**Contract**: `login(LoginPayload): AuthResultDto` (throws on bad credentials →
401-mapped exception), `register(RegisterPayload): AuthResultDto` (throws
RegistrationClosed → 403-mapped), `logout(User): void`. A domain exception type (e.g.
`App\Exceptions\RegistrationClosedException`) maps to `HTTP_FORBIDDEN`.

#### 6. AuthController + routes

**File**: `app/Http/Controllers/Api/V1/AuthController.php`, `routes/api.php`

**Intent**: Slim controllers mapping HTTP ↔ AuthService. `login` (public, throttled),
`register` (public, throttled), `logout` (auth), `me` (auth). Replace the `/user`
closure with `me`. Establish the protected group `['auth:sanctum', LogContextMiddleware]`.

**Contract**: `POST /api/login` → `HTTP_OK` + AuthResultDto; bad creds → `HTTP_UNAUTHORIZED`.
`POST /api/register` → `HTTP_CREATED`; closed → `HTTP_FORBIDDEN`. `POST /api/logout` →
`HTTP_NO_CONTENT`. `GET /api/me` → `HTTP_OK` + UserDto. Scramble PHPDoc on each
(`@return JsonResponse<...>`, `@unauthenticated` on login/register, `@throws`).

#### 7. Login rate limiter

**File**: `app/Providers/AppServiceProvider.php`, `routes/api.php`

**Intent**: Throttle login and register to deter brute force on the one account.

**Contract**: register a named `RateLimiter::for('login', ...)` keyed by email+IP in
`AppServiceProvider::boot()`; apply it via `->middleware('throttle:login')` on the login
and register routes. Exceeding → `HTTP_TOO_MANY_REQUESTS`. (Verified: no named limiter
exists yet, so this is net-new.)

#### 8. LogContextMiddleware (user_id → log context)

**File**: `app/Http/Middleware/LogContextMiddleware.php`

**Intent**: After auth, push `user_id` into `Log::shareContext` so authenticated-request
logs carry `user.id` (mapped by the existing `MapContextToEcs`). Closes the F-03 remainder.

**Contract**: `handle()` reads `$request->user()?->getAuthIdentifier()`, calls
`Log::shareContext(['user_id' => $id])` when present; registered **inside** the
`auth:sanctum` group in `routes/api.php`.

#### 9. Backend tests

**File**: `tests/Feature/Auth/*.php`, `tests/Unit/...`

**Intent**: Cover success + failure/security paths.

**Contract**: Feature — login success (token + user), wrong password → `HTTP_UNAUTHORIZED`,
throttle → `HTTP_TOO_MANY_REQUESTS`, register opens once then `HTTP_FORBIDDEN`, logout
revokes (subsequent call with old token → 401), `/me` 401-vs-200. Unit — AuthService
gate logic / DTO shape. `Response::HTTP_*` constants; `it()` naming; `use` imports.

### Success Criteria:

#### Automated Verification:

- Larastan level 6 clean: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pest green incl. new auth tests: `php artisan test`
- Pint clean: `./vendor/bin/pint --test`
- `composer quality` exits 0

#### Manual Verification:

- `POST /api/register` creates the account once, then returns 403 on a second call
- `POST /api/login` returns a usable Bearer token; `GET /api/me` with it returns the user
- Wrong password → 401; hammering login → 429
- An authenticated request's JSON log line includes `user.id`

**Implementation Note**: After automated verification passes, pause for manual confirmation before Phase 2.

---

## Phase 2: Frontend auth

### Overview

Introduce routing and auth state in the SPA; a login page that obtains and stores the
token; a protected area; an API client that attaches the token and handles 401.

### Changes Required:

#### 1. Router + dependency

**File**: `frontend/package.json`, `frontend/src/main.tsx`

**Intent**: Add `react-router-dom`; wrap the app in a router with `/login` and a
protected root route.

**Contract**: `react-router-dom` in deps; `main.tsx` mounts `<BrowserRouter>` (or
`createBrowserRouter`); routes: `/login` → `LoginPage`, `/` → protected shell.

#### 2. Auth context + token storage

**File**: `frontend/src/auth/AuthContext.tsx`

**Intent**: Hold token (localStorage) + user; expose `login`, `logout`, `isAuthenticated`.
Hydrate from localStorage on load.

**Contract**: `login(email,password)` calls the API, stores token, sets user;
`logout()` calls `/api/logout`, clears localStorage; context value typed.

#### 3. API client

**File**: `frontend/src/api.ts`

**Intent**: Attach `Authorization: Bearer <token>` from storage; add `login`,
`register`, `logout`, `me`; on 401 clear token + signal logout. **Preserve** the existing
`fetchHealth` export + `HealthStatus` type — `App.tsx:3` imports them (additive change,
not a rewrite that drops them).

**Contract**: a shared `request()` helper injecting the header and centralizing 401
handling; typed request/response shapes; `fetchHealth`/`HealthStatus` remain exported.

#### 4. LoginPage + ProtectedRoute + shell

**File**: `frontend/src/auth/LoginPage.tsx`, `frontend/src/auth/ProtectedRoute.tsx`,
`frontend/src/App.tsx`

**Intent**: Minimal email+password form (calls `login`, shows error on 401, redirects on
success); `ProtectedRoute` redirects to `/login` when unauthenticated; `App` becomes the
authenticated shell (shows "signed in as …" + a logout button; keep the health check as
a placeholder).

**Contract**: form submits to `AuthContext.login`; `ProtectedRoute` wraps the root;
logout returns to `/login`.

### Success Criteria:

#### Automated Verification:

- Type check + build: `npm run build` (tsc -b + vite)
- Lint: `npm run lint`

#### Manual Verification:

- Visiting `/` while logged out redirects to `/login`
- Logging in stores the token and lands on the authenticated shell
- Refresh keeps the session (token rehydrated from localStorage)
- Logout clears state and returns to `/login`

**Implementation Note**: Pause for manual confirmation before Phase 3.

---

## Phase 3: Integration & hardening

### Overview

Verify the two-origin flow end-to-end, lock CORS to the frontend origin, and update docs.

### Changes Required:

#### 1. CORS origin verification

**File**: `config/cors.php`, `.env.example` (+ deploy note)

**Intent**: Confirm `allowed_origins` is the frontend origin (not `*`) and that the SPA
can call the API cross-origin with the Bearer header; document the env for Railway.

**Contract**: cross-origin `OPTIONS`/`GET`/`POST` from the frontend origin succeed; a
disallowed origin is rejected. `deploy-plan.md`/`.env.example` note `FRONTEND_URL`.

#### 2. Docs + contract surfaces

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Register the new load-bearing names: the auth endpoints, `AuthService`,
`UserRepository(Interface)`, `LogContextMiddleware`, `AuthContext`/token-storage key.

**Contract**: one H2 section per new surface (file + one-line purpose), matching the
existing registry format.

#### 3. Full-suite green

**File**: — (verification only)

**Intent**: Run the whole gate set (backend + frontend) and the manual e2e round-trip.

**Contract**: `composer quality` + `npm run build`/`lint` all green; SPA login →
token → `/me` works against the running API.

### Success Criteria:

#### Automated Verification:

- `composer quality` exits 0
- `npm run build` and `npm run lint` green
- Scramble export still succeeds: `php artisan scramble:export`

#### Manual Verification:

- End-to-end: SPA `/login` → token stored → protected call to `/me` succeeds cross-origin
- A non-allowed origin is blocked by CORS
- `/docs/api` shows the auth endpoints (login/register `@unauthenticated`; `/me`, logout secured)

**Implementation Note**: Final phase — confirm the e2e round-trip before marking done.

---

## Testing Strategy

### Unit Tests:

- `AuthService` register gate (throws when a user already exists)
- DTO shapes (`UserDto`, `AuthResultDto` serialize as expected)

### Integration Tests:

- Login success → token usable on `/me`
- Wrong password → 401; throttle → 429
- Register opens once, then 403
- Logout revokes the current token (reuse → 401)
- `/me` and `/logout` require auth (401 when absent)

### Manual Testing Steps:

1. Fresh DB: `register` once (201), again (403).
2. `login` → copy token → `GET /me` with `Authorization: Bearer` (200).
3. Wrong password (401); spam login (429).
4. SPA: log in, refresh (still in), logout (back to /login).
5. Inspect a `json`-channel log line for an authed request → `user.id` present.

## Performance Considerations

Negligible at single-user scale. Rate limiter is in-memory/cache-backed. Token lookups
are indexed by Sanctum. No N+1 surfaces.

## Migration Notes

No schema migration — the default users table suffices. The account is created via the
gated first-run register (or a seeder for local dev). CORS env (`FRONTEND_URL`) must be
set per environment on Railway; changing the frontend domain requires updating it.

## References

- Change identity: `context/changes/email-password-auth/change.md`
- Roadmap: `context/foundation/roadmap.md` (F-02; F-03 user_id remainder)
- Conventions: `app/CLAUDE.md` (layering, FormRequest-vs-Service gate, structured logging), `tests/CLAUDE.md`
- Seed route to formalize: `routes/api.php:10-12` (`/user` → `/me`)
- Two-origin deploy + CORS note: `context/foundation/infrastructure.md`, `context/deployment/deploy-plan.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend auth core

#### Automated

- [x] 1.1 Larastan level 6 clean: `./vendor/bin/phpstan analyse --memory-limit=512M` — bf41d60
- [x] 1.2 Pest green incl. new auth tests: `php artisan test` — bf41d60
- [x] 1.3 Pint clean: `./vendor/bin/pint --test` — bf41d60
- [x] 1.4 `composer quality` exits 0 — bf41d60

#### Manual

- [x] 1.5 `register` creates the account once, then 403 on a second call — bf41d60
- [x] 1.6 `login` returns a usable Bearer token; `/me` with it returns the user — bf41d60
- [x] 1.7 Wrong password → 401; hammering login → 429 — bf41d60
- [x] 1.8 An authenticated request's JSON log line includes `user.id` — bf41d60

### Phase 2: Frontend auth

#### Automated

- [x] 2.1 Type check + build: `npm run build` — b84c86b
- [x] 2.2 Lint: `npm run lint` — b84c86b

#### Manual

- [x] 2.3 Visiting `/` logged out redirects to `/login` — b84c86b
- [x] 2.4 Login stores the token and lands on the authenticated shell — b84c86b
- [x] 2.5 Refresh keeps the session (token rehydrated) — b84c86b
- [x] 2.6 Logout clears state and returns to `/login` — b84c86b

### Phase 3: Integration & hardening

#### Automated

- [x] 3.1 `composer quality` exits 0
- [x] 3.2 `npm run build` and `npm run lint` green
- [x] 3.3 Scramble export still succeeds: `php artisan scramble:export`

#### Manual

- [x] 3.4 End-to-end: SPA login → token → `/me` succeeds cross-origin
- [x] 3.5 A non-allowed origin is blocked by CORS
- [x] 3.6 `/docs/api` shows auth endpoints (login/register unauth; `/me`, logout secured)
