# Email + Password Authentication (Sanctum Bearer) — Plan Brief

> Full plan: `context/changes/email-password-auth/plan.md`

## What & Why

Roadmap **F-02**: let the single user sign in with email + password via Sanctum
**Bearer tokens**, protect the API, and add a React login flow. It's the foundation
that unlocks the north star **S-01 `capture-to-inbox`** and every data-bearing slice,
and it closes the small **F-03** remainder (wire `user_id` into log context).

## Starting Point

Sanctum is installed but scaffold-only (`HasApiTokens` on `User`, `auth:sanctum` on a
`/user` closure) — no `config/sanctum.php`, no `config/cors.php` (CORS is `*`), no
`statefulApi()`. The default users table suffices. The backend has no Service/Repo/DTO
layering yet; the frontend has no router, no auth state, and a bare `fetch` client. The
SPA and API run on two different `*.up.railway.app` subdomains.

## Desired End State

`login` returns a Bearer token (wrong creds → 401, brute force → 429); `register` works
only on first run (else 403); `logout` revokes the calling token; `/me` returns the user
(401 when unauthenticated). The SPA has a `/login` page, stores the token in
localStorage, attaches it as `Authorization: Bearer`, routes to a protected area, and on
401 clears the token and returns to login. Authenticated logs carry `user.id`. CORS
allows only the frontend origin.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Auth model | Bearer token | Two different Railway subdomains make cookie/CSRF auth fiddly; tokens are simpler cross-origin | Plan |
| Account creation | First-run gated register | Self-serve first setup, then closes — no open signup (single-user non-goal) | Plan |
| Token storage | localStorage | Survives refresh, simple; acceptable XSS tradeoff for a personal tool | Plan |
| Frontend routing | Add react-router now | The north star + all GTD screens need it; introduce with the first real screen | Plan |
| Login throttling | Rate-limit login | Cheap, standard brute-force protection on the one account | Plan |
| Logout | Revoke current token | Precise; only this session ends | Plan |
| Protected scope | Formalize `/me` + auth group | Real (not throwaway); sets the pattern S-01 joins | Plan |
| `user_id` logging | Wire now (F-03 remainder) | Auth is the natural moment; one small middleware | Plan |
| Register gate location | Service, not FormRequest | DB-state checks live in the Service per app/CLAUDE.md | Plan |

## Scope

**In scope:** Sanctum + CORS config; login / gated-register / logout / `/me`; layered
`AuthService` + `UserRepository` + DTOs + FormRequests; login rate-limiting;
`LogContextMiddleware` (user_id); Scramble annotations; React router + AuthContext +
LoginPage + ProtectedRoute + token-aware API client; feature + unit tests.

**Out of scope:** open/multi-user registration; cookie/CSRF/stateful SPA auth; password
reset, email verification, OAuth, magic link; "log out everywhere"; token expiry/refresh;
any GTD/domain endpoints; a frontend component/styling system.

## Architecture / Approach

Strict layering per `app/CLAUDE.md`: FormRequest → AuthController → AuthService →
UserRepository(interface→impl) → User model, DTOs between layers, Eloquent confined to
the repository (token issue/revoke live there). The register zero-users gate is a
DB-state check in the Service (→ 403). `LogContextMiddleware` sits **inside** the
`auth:sanctum` group (user_id only exists post-auth). Frontend: `AuthContext`
(localStorage token) + react-router with a `ProtectedRoute`; a shared API helper injects
the Bearer header and centralizes 401 handling.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Backend auth core | Endpoints + config + layering + rate-limit + user_id logs + tests | First layered stack — getting Service/Repo boundaries right |
| 2. Frontend auth | Router + AuthContext + LoginPage + token-aware client | First routing/auth-state in the SPA |
| 3. Integration & hardening | CORS locked to frontend origin, e2e login, docs | Cross-origin CORS correctness on two subdomains |

**Prerequisites:** F-01 (done). Decide nothing else — the auth-model open question is resolved (token).
**Estimated effort:** ~2-3 sessions across 3 phases; Phase 1 is the largest.

## Open Risks & Assumptions

- Cross-origin CORS with a Bearer header must be verified on the two Railway subdomains (Phase 3) — the known two-origin gotcha.
- localStorage token = accepted XSS tradeoff for a single-user personal tool.
- Sanctum default non-expiring tokens; revisit expiry if the security posture tightens.

## Success Criteria (Summary)

- The user can register once, log in, stay logged in across refresh, and log out — from the SPA, cross-origin.
- Protected endpoints reject unauthenticated requests (401) and rate-limit brute force (429); authed logs carry `user.id`.
- `composer quality` + frontend build/lint green; `/docs/api` documents the auth surface.
