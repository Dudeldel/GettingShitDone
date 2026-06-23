---
project: getting-shit-done
researched_at: 2026-06-23
recommended_platform: AWS Lightsail (existing instance, co-located MariaDB)
runner_up: Railway
context_type: mvp
tech_stack:
  language: php
  framework: laravel (REST API) + vite/react SPA
  runtime: php 8.3 (laravel octane) + node (frontend build)
---

## Recommendation

**Deploy on the existing AWS Lightsail instance, co-locating a MariaDB database for this app.**

Cost is the top priority, and the developer already pays for a small Lightsail VM running another app — so adding GSD alongside it is ~$0 marginal cost (resize only if the box lacks RAM headroom), which beats every managed PaaS's $4–8/mo of new spend. The developer prefers MariaDB/MySQL, which co-locates for free on the VM but is not first-class managed on Fly.io or Render (both Postgres-only). AWS familiarity (interview) breaks the remaining ties. This is a conscious trade: Lightsail scores lower on agent-friendliness (raw VM → manual TLS, Octane supervisor, snapshot-only rollback) than the managed three, but the real-world constraints — existing infra, MariaDB preference, cost, familiarity — outweigh that here. The agent-ops penalty is mitigated in the risk register below.

## Platform Comparison

Hard filter applied first: **Laravel Octane requires a persistent always-on process**, so serverless-only hosts (Cloudflare Workers — no PHP runtime at all; Vercel / Netlify functions — no long-lived PHP worker) are dropped before scoring. The four survivors run a persistent container or VM.

| Platform | CLI-first | Managed > raw | Agent docs | Stable deploy API | MCP / integration |
|---|---|---|---|---|---|
| **AWS Lightsail** | Partial | Partial | Pass | Partial | Pass |
| **Fly.io** | Pass | Pass | Partial | Pass | Partial |
| **Render** | Pass | Pass | Pass | Pass | Pass |
| **Railway** | Pass | Pass | Pass | Pass | Pass |

Per-platform notes:

- **AWS Lightsail** — CLI **Partial**: the VM path has no one-command app deploy (you SSH or build CI yourself); Lightsail *Containers* do have `push-container-image` → `create-container-service-deployment`. Managed **Partial**: a raw VM means OS patching, PHP/web-server install, TLS, and the Octane supervisor are all yours. Docs **Pass**: AWS docs now serve Markdown + `llms.txt` (GA 2025). Deploy API **Partial**: rollback is whole-VM snapshot, not app-versioned. MCP **Pass**: AWS MCP Server GA (May 2026), IAM-scoped. Cost: **~$5–7/mo fixed** standalone, **~$0 marginal** on the existing box. MariaDB co-locates free.

- **Fly.io** — strongest Octane fit: official `fly launch` Laravel detection and first-class Octane (FrankenPHP/Swoole/RoadRunner) docs. CLI/deploy GA (`fly deploy`, `fly logs`; rollback = re-deploy a prior image — no `fly rollback`). Docs **Partial** (no `llms.txt`); MCP **experimental**. Cheapest *with SQLite* (~$4–5/mo) but **managed Postgres floors at $38/mo** and there is **no managed MySQL** — disqualifying given the MariaDB preference. Auto-stop is on by default and conflicts with keeping Octane warm.

- **Render** — best agent-ops: ships `llms.txt` + `llms-full.txt`, official **MCP server GA** (works with Claude Code), full CLI, and **auto-rollback on failed deploy**. PHP is **Docker-only** (no native runtime, no official Octane recipe — you maintain the Dockerfile). Managed DB is **Postgres-only** (MySQL would be self-hosted in a container). Free tier spins down (incompatible with Octane). ~$7–8/mo.

- **Railway** — excellent DX, official MCP (**beta**), markdown/`llms.txt` docs, **native managed MySQL** (the only managed PaaS here that offers it first-class). Octane via custom Dockerfile (community `exaco/laravel-octane-dockerfile`). Billing is **usage-metered** (~$5–9/mo) — the least predictable for a cost-first decision, which is why it's runner-up rather than the pick.

### Shortlisted Platforms

#### 1. AWS Lightsail (Recommended)

Wins on the constraints that actually bind this project: an **already-paid-for instance** (near-zero marginal cost), a **free co-located MariaDB** matching the developer's stated DB preference, and **AWS familiarity**. It is a persistent VM, so Octane runs natively with a supervisor — no serverless/auto-stop footguns. The cost is fixed and predictable. Accepted trade: lower agent-friendliness than the managed three.

#### 2. Railway

The best managed fallback if the shared box runs out of headroom: it's the only managed PaaS here with **native MySQL**, has strong agent-ops (CLI + MCP beta + `llms.txt` docs), and runs persistent Octane containers. The gap vs. the recommendation is purely cost-shape — usage-metered billing is less predictable than Lightsail's flat fee, and it's *new* spend on top of an instance the developer already pays for.

#### 3. Render

Highest raw agent-friendliness score (GA MCP, best docs, auto-rollback), but two gaps push it to third for *this* project: managed DB is **Postgres-only** (MariaDB preference unmet without self-hosting), and PHP is Docker-only with **no official Octane guidance**. Better suited to a Postgres project that wants maximum hands-off ops.

## Anti-Bias Cross-Check: AWS Lightsail (shared instance + MariaDB + Octane)

### Devil's Advocate — Weaknesses

1. **Noisy-neighbor RAM contention.** Octane keeps the whole app booted in memory permanently; on a small box already running another app *and* MariaDB, the two apps can starve each other into OOM kills. Headline risk.
2. **Shared blast radius.** One bad deploy, an Octane memory leak, or an `apt upgrade` that bumps PHP takes down *both* apps. Zero isolation between tenants on the box.
3. **No app-versioned rollback.** Rollback is whole-VM snapshot — you cannot cleanly revert just this app's release without dragging the neighbor app and the database back with it.
4. **Manual operational surface for the second app:** a new nginx server block, a second certbot certificate + renewal, and the Octane supervisor (systemd/Supervisor) are all hand-wired and easy for an unattended agent to misconfigure (e.g. leaving Octane's `:8000` or MariaDB's `:3306` reachable).
5. **DB durability is self-owned.** Co-located MariaDB has no automated backups unless you build them; the PRD's capture-durability guarantee rests on a `mysqldump` cron that must not be forgotten.

### Pre-Mortem — How This Could Fail

The app shipped onto the existing box in an afternoon — no new bill, MariaDB already familiar. For weeks it was perfect. Then traffic on the *other* app spiked one evening; with Octane holding GSD's full framework in RAM, the box crossed its memory ceiling and the OOM killer reaped MariaDB. Both apps went down, and the morning capture — the one flow the PRD guards at ~2 seconds — returned 500s. The "fix" was resizing the instance, quietly doubling the bill the choice was meant to avoid. A month later a routine `apt upgrade` advanced PHP and broke the neighbor app's pinned extension; rolling back the VM snapshot to repair it also reverted three of GSD's migrations, corrupting state. Backups turned out to be whole-VM snapshots only — no per-database dump — so the clean restore everyone assumed existed didn't. None of it was a Lightsail fault; it was the absence of isolation and the manual operational surface a managed platform would have handled.

### Unknown Unknowns

- **Verify headroom before committing** (`free -m`): if the box has <~512 MB free, Octane won't fit and you'll resize — erasing the "$0 marginal cost" premise that justifies this choice.
- **PHP-version coupling:** if the neighbor app pins a different PHP version, running two on one box needs careful CLI/extension separation. Octane's own server helps, but `php.ini`/extensions still diverge.
- **GitHub Actions has no native Lightsail deploy** — wire an SSH key (or self-hosted runner); the deploy script must end in `php artisan octane:reload` (graceful) so it doesn't drop in-flight requests or disturb the neighbor.
- **Snapshots are instance-wide, not per-app** — backup/restore must be app-and-DB aware (`mysqldump` per database), not VM snapshots.
- **AWS MCP server is GA but broad** — pointing an agent at it grants far more than this one instance; scope the IAM credentials tightly to Lightsail + this instance.

## Operational Story

- **Preview deploys**: Lightsail has no built-in PR preview URLs. For an MVP on a shared box, preview locally (`php composer.phar dev`) and treat `main` as the only deployed environment; if previews become needed, spin a separate cheap `$5` Lightsail instance as a staging target rather than a second app on the production box.
- **Secrets**: app secrets live in `.env` on the instance (root/deploy-user readable only, `chmod 600`); CI/CD credentials (SSH key, host) live in **GitHub Secrets**. Rotate the SSH deploy key and `APP_KEY` by hand; MariaDB credentials are per-app (a dedicated DB user, not root).
- **Rollback**: redeploy the previous git tag via the Actions workflow (`git checkout <tag> && composer install --no-dev && php artisan migrate --force && php artisan octane:reload`). VM snapshots are the disaster-recovery floor, **not** the routine rollback — they revert the neighbor app and DB too. DB migrations do not auto-roll-back; keep `down()` methods correct or restore from `mysqldump`.
- **Approval**: a human approves resizing the instance, rotating `APP_KEY`/SSH keys, and any `apt upgrade` touching PHP (it affects the neighbor app). An agent may deploy app code, run forward migrations, and `octane:reload` unattended.
- **Logs**: read-only over SSH — `tail -f storage/logs/laravel.log` (text in local/staging; ECS JSON on stdout in prod per app conventions), `journalctl -u <octane-service>` for the Octane supervisor, and `journalctl -u nginx` / `mariadb` for the edge and DB. The AWS MCP server (GA, IAM-scoped) can surface instance metrics if wired.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Octane + neighbor app + MariaDB exhaust RAM → OOM kills | Devil's advocate / Pre-mortem | M | H | Check `free -m` before deploy; cap Octane workers (`--workers`); set MariaDB `innodb_buffer_pool_size` conservatively; resize instance if <~512 MB free; add a memory alarm |
| Whole-box blast radius (one app/upgrade breaks both) | Devil's advocate | M | H | Gate `apt upgrade`/PHP changes behind human approval; pin PHP version; consider a dedicated instance once budget allows |
| No clean per-app rollback (snapshot reverts everything) | Devil's advocate | M | M | Deploy by git tag and roll back by re-deploying the prior tag; keep migration `down()` correct; reserve VM snapshots for disaster recovery only |
| Co-located MariaDB has no automated backups | Devil's advocate | M | H | Nightly `mysqldump` cron of this app's DB to a Lightsail bucket/S3; test restore once; document retention |
| Octane stays warm but leaks state between requests | Research finding (Octane) | M | M | Avoid request state in singletons/static props; schedule periodic `octane:reload`; monitor memory growth |
| Exposed ports (Octane `:8000`, MariaDB `:3306`) | Unknown unknowns | L | H | Bind Octane and MariaDB to `127.0.0.1`; nginx is the only public listener; lock Lightsail firewall to 80/443 |
| Resize erases the "$0 marginal cost" premise | Unknown unknowns | M | M | Verify headroom first; if a resize is needed, re-compare against Railway (native MySQL) at that new price point |
| GitHub Actions deploy misconfig drops requests / disturbs neighbor | Unknown unknowns | L | M | Deploy script ends in `octane:reload` (graceful), not a hard restart; scope SSH deploy user to this app's directory |
| Over-broad AWS MCP IAM credentials | Unknown unknowns | L | M | Scope the IAM principal to Lightsail + this instance only; no account-wide admin for the agent |

## Getting Started

Validated against this stack's versions (Laravel 12, Laravel Octane current, PHP 8.3, MariaDB):

1. **Confirm headroom on the existing instance**: SSH in, run `free -m` and `df -h`. Ensure ~512 MB+ free RAM and disk for a warm Octane worker + MariaDB. Resize the Lightsail plan first if not.
2. **Provision the DB**: install MariaDB if absent; create a dedicated database and user for GSD (`CREATE DATABASE gsd; CREATE USER ...; GRANT ... ON gsd.*`); bind MariaDB to `127.0.0.1`. Set `DB_CONNECTION=mysql` + credentials in `.env`.
3. **Install Octane**: `composer require laravel/octane` then `php artisan octane:install` (choose **FrankenPHP** — the current Laravel-recommended Octane server). Add a systemd/Supervisor unit running `php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8000`.
4. **Edge + TLS**: add an nginx server block for the app's subdomain reverse-proxying to `127.0.0.1:8000` and serving the built SPA (`frontend/dist`) as static assets; issue a cert with `certbot --nginx` for the new subdomain.
5. **CI/CD (GitHub Actions, auto-deploy on merge)**: add a workflow that SSHes to the instance and runs `git pull` → `composer install --no-dev --optimize-autoloader` → `(cd frontend && npm ci && npm run build)` → `php artisan migrate --force` → `php artisan octane:reload`. Store the SSH key + host in GitHub Secrets.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration
- CI/CD pipeline setup (only the deploy step shape is sketched in Getting Started)
- Production-scale architecture (multi-region, HA, DR)
