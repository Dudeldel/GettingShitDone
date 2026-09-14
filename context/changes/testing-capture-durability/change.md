---
change_id: testing-capture-durability
title: Test rollout phase 1 — capture durability and error surfacing
status: implemented
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

Open a change folder for rollout Phase 1 of context/foundation/test-plan.md: "Capture durability and error surfacing".
Risks covered: #1 (capture confirms the save, but the idea never lands durably in the Inbox), #2 (a failure is swallowed — the user is shown an empty or stale state and believes it, and typed text is destroyed on the way).
Test types planned: integration, client component. This phase also bootstraps frontend test infrastructure — frontend/src currently has zero tests.
Risk response intent:
- Risk #1: prove that after the success confirmation the item is readable in a fresh session, and that when the write fails no confirmation is shown.
- Risk #2: prove that a backend failure reaches the user as a distinguishable, actionable state, and never destroys text the user already typed.
After creating the folder, follow the downstream continuation rule.
