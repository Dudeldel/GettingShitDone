<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Quality Gates Toolchain (+ Observability Baseline)

- **Plan**: context/changes/quality-gates-toolchain/plan.md
- **Mode**: Deep
- **Date**: 2026-06-24
- **Verdict**: REVISE → SOUND (after triage)
- **Findings**: 0 critical · 3 warnings · 2 observations (all triaged)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | WARNING |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING |

## Grounding
10/10 paths ✓, symbols ✓ (pre-allowed pest plugin, CI reserved slot, withMiddleware, octane RequestTerminated listener), brief↔plan ✓.

## Findings

### F1 — Octane manual flush likely duplicates framework behavior

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Lean Execution
- **Location**: Phase 4 §5 + Critical Implementation Details
- **Detail**: `config/octane.php:73-75` runs `Octane::prepareApplicationForNextRequest()` on `RequestReceived` (incl. `FlushLogContext` in current Octane) and `:105-107` runs `FlushTemporaryContainerInstances` on `OperationTerminated`; `AssignRequestId` also overwrites the id every request. The plan's "load-bearing" manual flush is likely redundant.
- **Fix ⭐**: Reframe Phase 4 §5 to "verify Octane defaults first, build the flush only if step 4.6 proves a leak"; keep the two-sequential-requests gate as proof.
- **Decision**: FIXED (Fix applied — Phase 4 §5 + Critical Implementation Details rewritten to verify-before-build)

### F2 — Scramble Bearer-scheme wiring under-specified

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 3 §2
- **Detail**: Contract gestured at "withDocumentTransformers(... secdef-equivalent ...) per Scramble's API" — implementer must guess the call.
- **Fix**: Pin the concrete document-transformer call (push `SecurityScheme::http('bearer')`), verify against installed version, restrict `/docs` to non-prod.
- **Decision**: FIXED

### F3 — Progress↔Phase drift in Phase 5

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 5 Success Criteria ↔ ## Progress
- **Detail**: Phase 5 Manual SC "change.md status" had no Progress entry; Progress 5.4 (contract-surfaces) had no matching SC bullet.
- **Fix**: Replace the change.md-status SC bullet with the contract-surfaces verification so SC ↔ Progress 5.3/5.4 are 1:1.
- **Decision**: FIXED

### F4 — Pest --parallel may need brianium/paratest

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 1 §5 / Phase 5
- **Detail**: `pest --parallel` (CI + composer quality) needs paratest, which may not be pulled by default.
- **Fix**: Note in Phase 1 §1 — add `brianium/paratest --dev` if `--parallel` errors, or drop `--parallel` until the suite is large.
- **Decision**: FIXED

### F5 — LogEvent helper has no caller in this change

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Lean Execution
- **Location**: Phase 4 §6
- **Detail**: No domain events exist yet, so LogEvent ships without a caller.
- **Fix**: Keep as a thin convention seam (one generic method + unit test); slices add specific methods later.
- **Decision**: FIXED (kept as thin seam; clarifying note added to Phase 4 §6)
