---
bootstrapped_at: 2026-06-15T12:33:09Z
starter_id: laravel
starter_name: "Laravel"
project_name: getting-shit-done
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: "null"
---

## Hand-off

Verbatim copy of `context/foundation/tech-stack.md` frontmatter and `## Why this stack` body.

```yaml
starter_id: laravel
package_manager: composer
project_name: getting-shit-done
hints:
  language_family: php
  team_size: solo
  deployment_target: self-host
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: custom
  quality_override: true
  self_check_answers:
    typed: true
    from_official_starter: true
    conventions: false
    docs_current: true
    can_judge_agent: true
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: false
```

> A solo developer building a single-user GTD app in ~3 weeks of after-hours work,
> where the GTD clarify/routing domain logic lives in an API backend. The user
> designed a custom stack anchored to their production PHP environment, so Laravel
> leads as the REST API: Sanctum for auth (email+password now, OAuth later), Octane,
> PEST, Scramble docs, ECS/JSON logging, tenant isolation, and a Helm/K8s-shaped
> deploy. Laravel clears three agent-friendly gates but is dynamically typed; the
> user accepts this as a known-friction choice (quality_override) and compensates
> with strict Larastan, strong DTOs, enums, and a getXorNull-vs-exception
> convention — which bootstrapper bakes into the generated agent-instruction file.
> Bootstrapper confidence is verified, so backend scaffolding is smooth. The web
> front is a separate Vite + React SPA (TypeScript) consuming the REST API — chosen
> over the branded Astro starter, whose bundled Supabase backend would collide with
> Laravel+Sanctum; React also eases the later mobile path. Deploy is self-host on
> AWS Lightsail; CI runs on GitHub Actions with auto-deploy on merge. Auth is the
> only feature flag set; payments, realtime, AI, and background jobs are out of MVP
> scope per the PRD.

## Pre-scaffold verification

| Signal      | Value                                                   | Severity | Notes                                                                 |
| ----------- | ------------------------------------------------------- | -------- | --------------------------------------------------------------------- |
| npm package | not run                                                 | n/a      | Laravel uses `composer create-project`, not a JS `create-*` CLI       |
| GitHub repo | not run                                                 | n/a      | card `docs_url` is `https://laravel.com/docs`, not a `github.com` repo |

No recency signals are available for this ecosystem; this is expected for a Composer-based starter, not a fault. Toolchain note: PHP 8.3.31 was present, but Composer was not installed — the official `composer.phar` (2.10.1) was downloaded into the project root after signature verification and used to scaffold via `php composer.phar`.

## Scaffold log

**Resolved invocation**: `php composer.phar create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist` (template `composer` binary replaced with local `php composer.phar`, since Composer was not on PATH; `{name}` = `.bootstrap-scaffold`)
**Strategy**: subdir-then-move
**Exit code**: 0
**Files moved**: 22 (top-level entries: app/, bootstrap/, config/, database/, public/, resources/, routes/, storage/, tests/, vendor/, artisan, composer.json, composer.lock, package.json, phpunit.xml, vite.config.js, .editorconfig, .env, .env.example, .gitattributes, .gitignore, .npmrc)
**Conflicts (.scaffold siblings)**: README.md → README.md.scaffold (cwd README.md preserved per the conflict matrix)
**.gitignore handling**: moved silently (no pre-existing .gitignore in cwd)
**context/ handling**: preserved verbatim (scaffold shipped no context/; cwd's prd.md, shape-notes.md, tech-stack.md, README.md untouched)
**CLAUDE.md handling**: preserved (scaffold ships no CLAUDE.md)
**.bootstrap-scaffold cleanup**: deleted (empty after move-up)

Laravel installed: `laravel/laravel` v13.8.0, `laravel/framework` v13.15.0 (110 packages). The starter's own post-create steps ran: app key generated, `.env` created, SQLite database created, and initial migrations (users, cache, jobs tables) applied successfully.

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool wired for `php` in bootstrapper-config (`audit_commands.php: null`)
**Recommended external tool**: `composer audit` (built into Composer 2.4+) for advisory scanning, and `roave/security-advisories` as a dev dependency to block known-vulnerable packages at install time.
**Observed during scaffold**: Composer reported "No security vulnerability advisories found" while resolving dependencies during `create-project` — informational only, not a substitute for a dedicated audit run.

## Hints recorded but not acted on

v1 surfaces these hints but takes no compensating action (deferred to a future agent-context skill):

| Hint                    | Value                                                                 |
| ----------------------- | --------------------------------------------------------------------- |
| bootstrapper_confidence | verified                                                              |
| quality_override        | true — PHP `typed` gate compensation (Larastan strict, DTOs, enums, getXorNull-vs-exception) NOT yet written to any agent-instruction file |
| path_taken              | custom                                                                |
| self_check_answers      | typed:true, from_official_starter:true, conventions:false, docs_current:true, can_judge_agent:true |
| team_size               | solo                                                                  |
| deployment_target       | self-host (AWS Lightsail per hand-off rationale)                      |
| ci_provider             | github-actions                                                        |
| ci_default_flow         | auto-deploy-on-merge                                                   |
| has_auth                | true (Sanctum email+password planned)                                 |
| has_payments            | false                                                                 |
| has_realtime            | false                                                                 |
| has_ai                  | false                                                                 |
| has_background_jobs     | false                                                                 |

Note: the separate Vite + React frontend named in the hand-off rationale is NOT scaffolded by this run — this run scaffolded the Laravel API backend only. The frontend is a separate scaffold step.

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git init` is not needed — this directory is already a git repo; the scaffold's history was never imported (composer create-project does not create a `.git/`).
- Review `README.md.scaffold` (the Laravel default README) against your existing `README.md` and decide which to keep.
- The local `composer.phar` (2.10.1) lives in the project root; invoke Composer as `php composer.phar <cmd>` (or install Composer globally and remove the phar).
- Scaffold the separate Vite + React frontend when ready.
- When you set up agent context, fold in the `quality_override` compensation: Larastan at a pinned strict level in CI, DTOs/enums at boundaries, the getXorNull-vs-exception convention, PEST coverage, Conventional Commits, and the middleware/tenant-isolation pipeline.
- Address audit findings per your risk tolerance — run `php composer.phar audit` for a real advisory scan.

## Addendum — frontend scaffolded (follow-on, outside the single-starter run)

After the main run, the separate Vite + React frontend named in the hand-off
rationale was scaffolded on user request:

**Resolved invocation**: `npm create vite@latest frontend -- --template react-ts` (create-vite 9.0.7), then `npm install`
**Location**: `frontend/` subdirectory of this repo (monorepo layout: Laravel backend in root, React SPA in `frontend/`)
**Toolchain**: Node v20.20.2, npm 10.8.2
**Exit codes**: 0 (scaffold), 0 (install)
**Dependencies**: 152 packages installed
**Audit**: `npm audit` ran as part of install — **0 vulnerabilities** (JS has a built-in audit tool, unlike the PHP backend)
**Layout**: TypeScript React SPA — `package.json`, `tsconfig*.json`, `vite.config.ts`, `index.html`, `src/main.tsx`, `src/App.tsx`, own `.gitignore`

The SPA consumes the Laravel REST API. Both halves now live in this single repo.
