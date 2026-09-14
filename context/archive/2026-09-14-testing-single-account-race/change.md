---
change_id: testing-single-account-race
title: Prove a second account cannot be created, including under concurrency
status: archived
created: 2026-09-14
updated: 2026-09-14
archived_at: 2026-09-14T19:10:10Z
---

## Notes

Rollout Phase 3 of context/foundation/test-plan.md ("Single-account invariant"), scoped by the
§6.6 audit of 2026-09-14. Covers Risk #6 (a second account is created on a product whose
single-user model is a permanent non-goal).

What is already covered: tests/Feature/Auth/RegisterTest.php proves the SEQUENTIAL second
registration is refused — which §2 names as this risk's anti-pattern, not its coverage.

What remains: the gate is `lockForUpdate` inside a transaction
(UserRepository::createFirstUserOrNull), NOT a database constraint. `users.email UNIQUE` would
stop two concurrent registrations using the same address, but the invariant is "at most one user
at all" — two concurrent registrations with DIFFERENT addresses are the shape that has to be
proven, and nothing asserts it.

Test types: integration.
