---
change_id: quality-gates-toolchain
roadmap_ref: F-01
title: Quality gates & analysis toolchain (+ buildable observability baseline)
status: implementing
created: 2026-06-24
updated: 2026-06-24
---

# Change: quality-gates-toolchain

## What

Install and wire the three quality tools the project's conventions mandate but that
aren't installed yet — **Pest**, **Larastan (level 6)**, and **Scramble** — into CI so
every push runs format + static-analysis + test gates and serves live API docs. Then
build the **buildable-now slice of the observability baseline** (roadmap F-03 partial):
JSON/ECS log channel, request-id correlation, the `LogEvent` domain-event helper, and
the Octane-safe per-request flush.

## Why (roadmap lineage)

- Roadmap item **F-01** (`context/foundation/roadmap.md`). Sequencing goal: `quality` —
  gates come first and everything ships under them. #1 blocker: `skills` (Octane/Railway
  fluency) — the observability slice was pulled forward into this change (user decision in
  `/10x-plan`) because request correlation + structured logs are what make debugging the
  unfamiliar Octane runtime tractable.

## Scope note (expanded beyond roadmap F-01)

This change folds the **buildable-now** part of roadmap **F-03 (observability-baseline)**
into F-01. Deferred to F-03 (not buildable yet): `user_id` log context (needs **F-02**
auth) and `request_id → queued-job` propagation (no jobs in MVP per tech-stack.md).
After this lands, F-03's remainder shrinks to "wire `user_id` context when auth exists".

## Links

- Plan: `context/changes/quality-gates-toolchain/plan.md`
- Plan brief: `context/changes/quality-gates-toolchain/plan-brief.md`
- Roadmap: `context/foundation/roadmap.md` (F-01)
