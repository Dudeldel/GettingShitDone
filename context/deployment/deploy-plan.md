# Deploy Plan — Walking Skeleton (GSD → AWS Lightsail)

> Foundation-chain artifact (Module 1, Lesson 5). Consumed by later milestone planning as ground truth for "what's deployed and which secrets are wired". Decision source: `context/foundation/infrastructure.md`. Stack: `context/foundation/tech-stack.md`.

## Context

First deploy of the single-user GTD app. Target = the developer's **existing AWS Lightsail VM**, co-locating **MariaDB**, running the Laravel API under **Octane (Swoole)** behind nginx, with the **Vite/React SPA** served as static files. CI = **GitHub Actions, auto-deploy on merge to `main`**.

This milestone ships a **walking skeleton**, not features: one trivial endpoint exercised end-to-end (build → MariaDB → Octane → nginx → TLS → public URL) so every later feature deploys on a proven path.

**Decisions:** Octane engine = **Swoole** · scope = **minimal** (`GET /api/health` + SPA fetches it) · flow = **GitHub Actions from the start**.

**Execution split:** agent owns repo artifacts (Phase A — done); developer owns the one-time box bootstrap + secrets (Phase B); push triggers deploy #1 only after the box is ready (Phase C).

## Phase A — Repo artifacts (DONE, committed on `chore/GSD-1-walking-skeleton-deploy`)

- `php artisan install:api` → `routes/api.php` + Sanctum; `HasApiTokens` on `app/Models/User.php`.
- `laravel/octane` v2.17 + `laravel/sanctum` v4.3 installed; `config/octane.php` (Swoole); `OCTANE_SERVER=swoole` in `.env.example`.
- `GET /api/health` → `app/Http/Controllers/Api/V1/HealthController.php` (public, `Response::HTTP_OK`).
- `.env.example` switched to MariaDB (`DB_CONNECTION=mysql`, db/user `gsd`); `phpunit.xml` stays SQLite in-memory.
- Frontend: `frontend/src/api.ts` + `App.tsx` fetch `/api/health` on mount; `vite.config.ts` `base:'/'`, `outDir:dist`; `frontend/.env.example` with `VITE_API_BASE_URL` (empty = same-origin).
- `deploy/nginx/gsd.conf`, `deploy/systemd/gsd-octane.service` (`--workers=2 --max-requests=500`), `deploy/backup/mysqldump-gsd.sh`.
- `.github/workflows/deploy.yml` — CI job (composer install · pint --test · `php artisan test` · frontend build) then SSH deploy job (`git pull` → `composer install --no-dev` → frontend build → `migrate --force` → `config:cache`/`route:cache` → `octane:reload`).

## Phase B — One-time box bootstrap (developer; run on the Lightsail box)

- **B0. Headroom gate** — `free -m` / `df -h`. Need ~512 MB+ free. If less → resize first (re-evaluate vs Railway).
- **B1. Runtime** — PHP 8.3 + extensions, **`ext-swoole`** (`pecl install swoole`), Composer (global), Node 20+, nginx, MariaDB, certbot. Reuse the neighbor app's where present.
- **B2. MariaDB** — `CREATE DATABASE gsd; CREATE USER 'gsd'@'localhost'…; GRANT … ON gsd.*`; bind to `127.0.0.1`.
- **B3. Static IP + DNS** — attach a Lightsail static IP; A record `<subdomain> → IP`.
- **B4. Clone + deploy key** — clone to `/var/www/gsd` as `<deploy-user>`; read-only GitHub deploy key for `git pull`.
- **B5. Prod `.env`** — `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<subdomain>`, `php artisan key:generate`, MariaDB `DB_*`. `chmod 600`.
- **B6. nginx + TLS** — install `deploy/nginx/gsd.conf` (replace `<subdomain>`/root), `nginx -t`, reload; `certbot --nginx -d <subdomain>`. Firewall: **80/443 only**; never expose `:8000`/`:3306`.
- **B7. Octane service** — install `deploy/systemd/gsd-octane.service` (replace `<deploy-user>`), `daemon-reload`, `enable --now`. Verify `:8000` localhost-only.
- **B8. GitHub Secrets** — `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY` in repo settings. Scope any AWS/IAM creds to this instance.
- **B9. Backups** — install the nightly `mysqldump` cron.

## Phase C — Trigger deploy #1

Merge `chore/GSD-1-walking-skeleton-deploy` → `main` (or push). GitHub Actions runs CI then the SSH deploy job, ending on `octane:reload`. Watch the run logs.

## Verification (end-to-end)

- `curl -fsS https://<subdomain>/api/health` → `200` + `{"status":"ok",…}`.
- Open `https://<subdomain>/` → SPA renders the health status fetched from the API.
- `systemctl status gsd-octane` running; `:8000` bound to `127.0.0.1`.
- Neighbor app still healthy; `free -m` within limits.
- TLS valid; GitHub Action green.
- Then: share the public URL on the 10xDevs Arena (Circle).

## Required inputs

`<subdomain>` · `<deploy-user>` · B0 headroom result.

## Out of scope

GTD features, Sanctum auth UI, Larastan/Pest/Scramble install, preview envs, multi-region/HA — later milestones.
