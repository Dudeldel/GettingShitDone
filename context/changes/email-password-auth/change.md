---
change_id: email-password-auth
title: Email + password authentication (Sanctum, single user)
status: impl_reviewed
created: 2026-06-24
updated: 2026-06-24
archived_at: null
---

## Notes

Roadmap item **F-02** (`context/foundation/roadmap.md`). Foundation that unlocks the
north star **S-01 `capture-to-inbox`** and every data-bearing slice.

Scope (per roadmap + PRD Access Control): minimal email+password login for the one user
via Sanctum — login / register / logout + route protection + a login screen. NOT a full
account-management suite (no password reset, OAuth, or magic link — those are v2 / PRD
Non-Goals).

**Resolve before planning** — Open Roadmap Question: SPA auth model across the two Railway
origins — Sanctum cookie (SPA/stateful) vs. token (Bearer), and tightening CORS from the
current `*` to the frontend origin. This decision shapes the whole change.

Related: the observability baseline already shipped in F-01 maps `user_id` → `user.id` in
logs *if present* but nothing sets it yet — wiring `user_id` into `Log::shareContext` once
the authenticated user exists is the small remainder of F-03.
