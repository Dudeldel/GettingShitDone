# Contract Parity — Plan Brief

> Full plan: `context/changes/testing-contract-parity/plan.md`
> Grounding: `context/foundation/test-plan.md` §2 Risk #4 + §6.6 audit of 2026-09-14 (stands in for a research doc)

## What & Why

Rollout Phase 2 of the test plan. Make it impossible for the published HTTP contract and the rules
the application actually enforces to drift apart without a test going red. That is Risk #4's exact
failure mode — and it is not hypothetical: four instances already exist in today's output.

## Starting Point

Scramble generates `api.json` from the FormRequests, so the two are supposed to agree. Verified
against live code, they do not: `tags` enforces a 20-item cap the document never mentions;
`bucket`, `quickRouteBucket` and `twoMinuteOutcome` publish a literally empty schema because their
legal set is computed at runtime; and `ClarifyItemRequest` publishes `required: []` although a
client sending `{}` gets a 422. Nothing would notice — there is no parity test, CI has no Scramble
step, and `api.json` is git-ignored.

Boundary limits and log redaction — the other half of this phase as originally written — are
already covered incidentally and are out of scope.

## Desired End State

A published document that tells the truth, and two test layers that keep it that way. Changing a
constant without updating its annotation turns a test red instead of shipping a silent
under-promise.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Scope | Only the contract-vs-enforcement half | Limits and redaction already covered; the audit says so and it re-verified | §6.6 |
| The oracle | Neither document wins — the test demands they **agree** | The only shape that cannot be satisfied by quietly changing one side | Plan |
| Existing drift | Fix it in this change | A green test pinning a known-wrong document is the oracle problem §2 warns about | Plan |
| Direction of fix | Correct the document, never weaken a rule | The rule is enforced behaviour; the document is a projection of it | Plan |
| Layers | Structural **and** behavioural | Structural cannot prove a limit is real; behavioural cannot see what the document omits — which is all four current drifts | Plan |
| Document under test | The test generates it itself | One mechanism, identical locally and in CI, nothing to forget and no generated file committed | Plan |
| Behavioural scope | Endpoints with cheap state only | Multi-step setup for clarify/refile/complete is most of the code for the least marginal signal | Plan |
| Assertion | Field-keyed, both sides | Message text is the named anti-pattern; a bare 422 could come from any field | Plan |
| Unknown rule tokens | Fail the test by name | A check that silently ignores what it does not understand degrades quietly into checking nothing | Plan |

## Scope

**In scope:** structural rules-to-schema parity across every FormRequest; behavioural at-limit /
over-limit checks on capture, register, login and item attributes; annotations closing the four
known drifts; the rollout tracker and cookbook update.

**Out of scope:** boundary-limit and redaction re-testing; a committed `api.json` snapshot; a new
CI step; weakening any rule; behavioural coverage of clarify / refile / complete; a general
rule-to-OpenAPI translator; anything browser-level (that is Phase 4, deferred).

## Architecture / Approach

```
rules()  ──(Scramble)──>  api.json
   │                          │
   └── structural check ──────┘     "every enforced rule survived translation"
                              │
                              └── behavioural check ──> real HTTP request
                                    "the published boundary is the enforced one, both sides"
```

The structural layer is not circular despite the document being generated from the rules: the link
under test is Scramble's **translation**, and all four current failures live exactly there.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Contract parity + document fixes | Both test layers; four drifts closed | A check that silently skips rule tokens it does not understand — guarded by failing on unknown tokens |
| 2. Close the rollout phase | §3 → complete, §6.6 rewritten as a record | Recording only the win and not the honest limit |

**Prerequisites:** none — Scramble, Pest and the test helpers are all in place.
**Estimated effort:** ~1–2 sessions; Phase 2 is doc-only.

## Open Risks & Assumptions

- Scramble may not be able to publish a **derived** enum at all. If so, the structural check's enum
  comparison is the gate, and a hand-copied list stays forbidden — that would be the second source
  of truth this change exists to prevent.
- Conditional requirements are unrepresentable in OpenAPI's `required` array. They are handled as
  prose plus an allowlist entry, which is a documentation fix rather than a schema one.
- The behavioural layer leaves clarify / refile / complete proven *complete* but not proven
  *enforced*. Recorded in §6.6 rather than hidden.

## Success Criteria (Summary)

- Changing a limit without updating its annotation turns a test red
- The exported document states legal values for the three fields that publish nothing today
- A new FormRequest is covered by the structural check automatically; a new exotic rule forces an
  explicit decision rather than a silent gap
