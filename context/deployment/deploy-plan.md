# Deploy Plan — Walking Skeleton (GSD → Railway, 2 services)

> Foundation-chain artifact (Module 1, Lesson 5). Decision source: `context/foundation/infrastructure.md`. Stack: `context/foundation/tech-stack.md`.

## Context

First deploy of the single-user GTD app. Target = **Railway**, two services from one repo:
- **Backend** — Laravel REST API under **Octane (Swoole)**, Dockerfile-built, talks to **Railway-managed MySQL**.
- **Frontend** — React/Vite **SPA**, built and served static (Caddy) as its own service.

Why Railway (not the originally-recorded Lightsail co-location): the existing 512 MB Bitnami box failed the RAM headroom gate (~284 MB available, already swapping) — Octane + a DB won't fit and co-tenancy is a shared blast radius. The developer chose a managed path over resizing. See `infrastructure.md` for the re-run anti-bias cross-check.

This ships a **walking skeleton**, not features: `GET /api/health` exercised end-to-end (build → MySQL → Octane → public URL) + the SPA fetching it. Features come later.

## Phase A — Repo artifacts (DONE, on `chore/GSD-1-walking-skeleton-deploy`)

Platform-agnostic (kept from the first pass):
- `install:api` → `routes/api.php` + Sanctum; `HasApiTokens` on `User`.
- `laravel/octane` (Swoole) + `config/octane.php`; `OCTANE_SERVER=swoole`.
- Public `GET /api/health` → `app/Http/Controllers/Api/V1/HealthController.php`.
- Frontend `api.ts` + `App.tsx` fetch `/api/health`; `VITE_API_BASE_URL` support.

Railway-specific (this pass):
- **Backend `Dockerfile`** (php:8.3-cli + ext-swoole + pdo_mysql; pinned tag) + **`deploy/railway/entrypoint.sh`** (fails fast if `DB_*` unlinked → `migrate --force` → `config:cache`/`route:cache` → `octane:start --host=0.0.0.0 --port=$PORT --workers=2 --max-requests=500`).
- **`frontend/Dockerfile`** (node build → Caddy serve `dist` on `$PORT`) + **`frontend/Caddyfile`** (SPA fallback).
- `.dockerignore` (root + frontend).
- **`.github/workflows/ci.yml`** — gates only (Pint, `php artisan test`, frontend lint+build). **Railway's GitHub integration owns the deploy.**

Removed: `deploy/nginx`, `deploy/systemd`, `deploy/backup`, the SSH `deploy.yml` (VM-only).

## Phase B — Railway setup (developer; one-time)

- **B1. Project** — create a Railway project; **pick the nearest region**; **set a spend limit** (not on by default).
- **B2. MySQL** — add the managed MySQL plugin.
- **B3. Backend service** — connect the GitHub repo, **root `/`**, builder = **Dockerfile**. Variables (runtime):
  - Link MySQL via **reference variables**: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
  - `APP_KEY` (generate: `php artisan key:generate --show`), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<backend-domain>`, `OCTANE_SERVER=swoole`.
  - **App-sleeping OFF.** Generate a public domain.
- **B4. Frontend service** — same repo, **root `/frontend`**, builder = **Dockerfile**. **Build** variable `VITE_API_BASE_URL=https://<backend-domain>`. Generate a public domain.
- **B5. (optional)** PR/preview environments; per-environment `VITE_API_BASE_URL`.

> **Variable scopes (gotcha):** frontend `VITE_API_BASE_URL` is **build-time**; backend `DB_*`/`APP_*` are **runtime**. Mixing them = silent failures.

## Phase C — Trigger deploy #1

Merge `chore/GSD-1-walking-skeleton-deploy` → `main` (or push). Railway auto-builds both services; GitHub Actions runs the gates in parallel.

## Verification (end-to-end)

- `curl -fsS https://<backend-domain>/api/health` → `200` + `{"status":"ok",…}`.
- Open `https://<frontend-domain>/` → SPA renders the health status fetched from the API (proves SPA → API → MySQL → Octane).
- `railway logs --service backend` clean; deploy marked active.
- CI run green; spend limit visible.
- Then: share the frontend public URL on the 10xDevs Arena (Circle).

## Known first-build risks (from the cross-check)

- **Swoole compile** — if `pecl install swoole` fails on the pinned base image, adjust the Dockerfile and rebuild in a preview env first (can't be built in the planning sandbox).
- **CORS** — default Laravel `allowed_origins:['*']` covers the public health endpoint cross-origin; tighten to the frontend origin when Sanctum cookie auth lands.
- **Stale frontend API URL** — changing the backend domain requires a frontend **rebuild** (build-time inlining).

## Required inputs

`<backend-domain>` and `<frontend-domain>` (Railway-generated) · region · spend cap.

## Out of scope

GTD features, Sanctum auth UI, Larastan/Pest/Scramble install, Docker hardening, multi-region/HA.
