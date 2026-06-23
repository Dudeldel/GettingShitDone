---
project: getting-shit-done
researched_at: 2026-06-23
recommended_platform: Railway (2 services — Laravel/Octane API + static React SPA, managed MySQL)
runner_up: AWS Lightsail (dedicated instance)
context_type: mvp
tech_stack:
  language: php
  framework: laravel (REST API) + vite/react SPA
  runtime: php 8.3 (laravel octane / swoole) + node (frontend build)
---

## Recommendation

**Deploy on Railway as two services: a Dockerfile-built Laravel + Octane (Swoole) API, a static React SPA, and Railway-managed MySQL.**

> **Decision history.** The first pass recommended co-locating on the developer's existing AWS Lightsail box (cost ≈ $0 marginal). The headroom gate failed it: the box is a 512 MB Bitnami instance with ~284 MB available and *already swapping* — Octane (which keeps the framework resident) plus a database does not fit, and co-tenancy means a shared blast radius. Resizing erases the cost premise, so the decision swapped to the documented runner-up, **Railway**, with the anti-bias cross-check re-run on it (below).

Railway wins on the two things the developer asked for after the gate failed — *easier to stand up, fewer headscratchers later*. It removes the VM ops long-tail (TLS renewal, OS patching, manual resize, snapshot-only rollback, self-owned backups, port discipline) that is exactly where a Lightsail instance's recurring pain lives. It offers **native managed MySQL** (matching the developer's DB preference — not first-class on Fly/Render), app-versioned deploys with one-click rollback, native GitHub auto-deploy, and an official CLI + MCP server for clean agent ops. Octane returns safely because each service gets its **own isolated, right-sized container** — no neighbor to fight for RAM. Accepted trade-offs: a one-time Octane Dockerfile, build-time-baked frontend config, and usage-metered billing (bounded ~$10–20/mo at single-user scale; set a spend cap).

## Platform Comparison

Hard filter: **Laravel Octane requires a persistent always-on process** → serverless-only hosts (Cloudflare Workers — no PHP; Vercel/Netlify functions — no long-lived PHP worker) dropped before scoring.

| Platform | CLI-first | Managed > raw | Agent docs | Stable deploy API | MCP / integration |
|---|---|---|---|---|---|
| **Railway** | Pass | Pass | Pass | Pass | Pass (MCP *beta*) |
| **Render** | Pass | Pass | Pass | Pass | Pass (MCP GA) |
| **Fly.io** | Pass | Pass | Partial | Pass | Partial (MCP *experimental*) |
| **AWS Lightsail** | Partial | Partial | Pass | Partial | Pass |

### Shortlisted Platforms

#### 1. Railway (Recommended)

Native managed **MySQL** (the only managed PaaS here with it first-class), persistent Octane containers, GitHub auto-deploy, app-versioned rollback, official CLI + MCP (beta), markdown/`llms.txt` docs. Per-service isolation lets the developer keep Octane without the shared-box RAM problem that sank the Lightsail co-location plan. Usage-metered billing is the one cost-shape caveat — bounded and cap-able at single-user scale.

#### 2. AWS Lightsail — dedicated instance

The familiar fallback: a fresh, right-sized Lightsail box (e.g. 2 GB, ~$12/mo flat) running Octane + MariaDB. Flat predictable price, AWS familiarity, native MariaDB, no lock-in. The gap vs. Railway is the entire ops long-tail it makes the developer own (TLS, patching, scaling, snapshot-only rollback, backups) — none of which Railway charges human attention for.

#### 3. Render

Highest raw agent-friendliness (GA MCP, best docs, auto-rollback), but managed DB is **Postgres-only** (MariaDB/MySQL preference unmet without self-hosting) and PHP is Docker-only with no official Octane recipe — pushing it behind Railway for this project.

## Anti-Bias Cross-Check: Railway (Octane/Swoole + managed MySQL, 2 services)

### Devil's Advocate — Weaknesses

1. **Usage-metered billing has no hard cap by default** — a crash-looping container or runaway query bills RAM-hours; a $10 month silently becomes $50 unless a spend limit is set.
2. **`VITE_API_BASE_URL` is baked at build time** — changing the backend domain needs a frontend *rebuild*, not an env tweak; easy to ship a front pointing at a stale API.
3. **You own the Swoole build** — a PHP minor bump or base-image change can break the `pecl install swoole` compile, failing at deploy time and blocking a hotfix.
4. **Managed MySQL is single-instance on hobby** — a maintenance restart drops connections that Octane's persistent workers hold; errors until workers recycle.
5. **App-sleeping** — if enabled, the Octane container cold-starts; first request after idle is slow/fails.

### Pre-Mortem — How This Could Fail

Six months in, none of it was fatal but each was a "new platform" papercut: usage crept because no spend cap was set; a service rename changed the backend domain and the frontend kept pointing at the old one for a day (the build still succeeded, so nothing screamed); a PHP base-image bump broke the swoole compile mid-deploy and blocked a fix; and a MySQL maintenance window recycled connections the Octane workers held, throwing errors until `--max-requests` recycled them. The team hit these because they knew AWS, not Railway — the managed model removed the *ops* headscratchers but introduced *platform-fluency* ones.

### Unknown Unknowns

- Build-arg vs runtime variables are **different scopes**: frontend needs `VITE_API_BASE_URL` at *build*, backend needs DB creds at *runtime* — mixing them = silent failure.
- **Reference variables** must link the MySQL creds into the backend service, or it boots with no DB.
- **Set a spend limit explicitly** — not on by default.
- **Pick the region at service creation** — moving later is non-trivial.
- Swoole + Octane: no request state in singletons; `--max-requests` masks leaks, doesn't fix them.

## Operational Story

- **Preview deploys**: Railway builds a deploy per push; enable PR environments for branch previews (each gets its own URL). The frontend's API base URL differs per environment — use Railway's per-environment variables.
- **Secrets**: live in Railway service variables (encrypted), injected at build (frontend `VITE_*`) or runtime (backend `DB_*`, `APP_KEY`). MySQL creds linked into the backend via reference variables. No secrets in the repo.
- **Rollback**: Railway keeps versioned deploys — redeploy a previous build from the dashboard or `railway redeploy`. DB migrations don't auto-roll-back; keep `down()` correct.
- **Approval**: a human sets the spend cap, deletes services, and rotates `APP_KEY`/DB credentials. The agent may deploy code, run forward migrations, and read logs unattended.
- **Logs**: `railway logs --service <name>` (or the dashboard / MCP server) — read-only, structured.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Usage billing has no default cap | Devil's advocate | M | M | Set a spend limit at project creation; alert at a threshold |
| Frontend ships pointing at stale backend domain | Devil's advocate / Unknown unknowns | M | M | `VITE_API_BASE_URL` as a build-arg variable; redeploy frontend after any backend domain change; smoke-test `/api/health` from the SPA post-deploy |
| `pecl install swoole` breaks on a base-image/PHP bump | Devil's advocate / Pre-mortem | L | M | Pin the PHP base image tag in the Dockerfile; bump deliberately, not implicitly; test build in a preview env first |
| MySQL restart drops Octane-held connections | Devil's advocate | L | M | `--max-requests=500` recycles workers; retry transient DB errors; rely on Laravel reconnect |
| App-sleeping cold-starts the API | Devil's advocate | L | M | Keep app-sleeping OFF on the backend service |
| Build-arg vs runtime variable confusion | Unknown unknowns | M | M | Document which vars are build vs runtime in deploy-plan.md; frontend = build, backend = runtime |
| MySQL creds not linked to backend | Unknown unknowns | L | H | Wire reference variables before first deploy; backend `entrypoint` fails fast if `DB_*` missing |
| Swoole state leakage between requests | Research finding (Octane) | M | M | No request state in singletons/static props; `--max-requests` recycling |
| CORS blocks SPA → API (cross-origin, 2 services) | Research finding | M | M | Default Laravel `allowed_origins:['*']` covers the public health endpoint; tighten to the frontend origin when Sanctum cookie auth lands |

## Getting Started

Validated against this stack (Laravel 13, Octane v2.17 / Swoole, PHP 8.3). Repo artifacts already committed: backend `Dockerfile` + `deploy/railway/entrypoint.sh`, `frontend/Dockerfile` + `frontend/Caddyfile`, `.dockerignore`s, `.github/workflows/ci.yml`.

1. **Create a Railway project**, pick the region nearest you, and **set a spend limit**.
2. **Add the MySQL plugin** (managed database).
3. **Backend service** — connect the GitHub repo, root `/`, builder = Dockerfile. Link MySQL creds via reference variables (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), set `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `OCTANE_SERVER=swoole`. Keep app-sleeping OFF.
4. **Frontend service** — same repo, root `/frontend`, builder = Dockerfile. Set `VITE_API_BASE_URL` (build variable) to the backend's public Railway domain.
5. **Deploy** — push to `main`; Railway builds both services. Verify `/api/health` (backend domain) and the SPA (frontend domain).

## Out of Scope

Docker image hardening beyond MVP, CI/CD beyond the gates workflow, production-scale architecture (multi-region, HA, read replicas).
