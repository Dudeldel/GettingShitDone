---
change_id: testing-contract-parity
title: Prove the published API contract is the one actually enforced
status: implementing
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

Rollout Phase 2 of context/foundation/test-plan.md ("HTTP edge contract and sensitive
data"), narrowed by the §6.6 audit of 2026-09-14. Covers Risk #4 (published HTTP validation
contract diverges from what is actually enforced). Risk #7 (captured text reaching logs) and
the boundary-limit half of Risk #4 are already covered incidentally and are NOT in scope.
What remains: nothing compares the Scramble output (api.json) against the rules the
FormRequests really apply, so the published contract and the enforced one can drift silently.
Test types: integration, unit.
