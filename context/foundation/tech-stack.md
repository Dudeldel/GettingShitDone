---
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
---

## Why this stack

A solo developer building a single-user GTD app in ~3 weeks of after-hours work,
where the GTD clarify/routing domain logic lives in an API backend. The user
designed a custom stack anchored to their production PHP environment, so Laravel
leads as the REST API: Sanctum for auth (email+password now, OAuth later), Octane,
PEST, Scramble docs, ECS/JSON logging, tenant isolation, and a Helm/K8s-shaped
deploy. Laravel clears three agent-friendly gates but is dynamically typed; the
user accepts this as a known-friction choice (quality_override) and compensates
with strict Larastan, strong DTOs, enums, and a getXorNull-vs-exception
convention — which bootstrapper bakes into the generated agent-instruction file.
Bootstrapper confidence is verified, so backend scaffolding is smooth. The web
front is a separate Vite + React SPA (TypeScript) consuming the REST API — chosen
over the branded Astro starter, whose bundled Supabase backend would collide with
Laravel+Sanctum; React also eases the later mobile path. Deploy is on Railway (two services —
Laravel/Octane API + static React SPA, with managed MySQL), chosen over the
originally-recorded AWS Lightsail co-location after the RAM-headroom gate failed
(see context/foundation/infrastructure.md and context/deployment/deploy-plan.md);
CI runs on GitHub Actions (quality gates) and Railway builds on push. Auth is the
only feature flag set; payments, realtime, AI, and background jobs are out of MVP
scope per the PRD.
