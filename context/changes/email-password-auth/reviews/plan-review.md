<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Email + Password Authentication (Sanctum Bearer)

- **Plan**: context/changes/email-password-auth/plan.md
- **Mode**: Deep
- **Date**: 2026-06-24
- **Verdict**: REVISE → SOUND (after triage)
- **Findings**: 0 critical · 2 warnings · 3 observations (all fixed)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING |

## Grounding
8/8 paths ✓, symbols ✓ (HasApiTokens; react-router absent), brief↔plan ✓, Progress↔Phase ✓. Deep verification (sub-agent) confirmed: CORS default `*` + `config:publish cors` to restrict; Sanctum Bearer needs no statefulApi/guard config; rate-limiter patterns valid (none exists yet); `/user` blast radius tiny; first layered stack (no existing Service/Repo/DTO/binding).

## Findings

### F1 — Rate-limiter approach left unresolved (two options, no pick)

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 §7
- **Detail**: Contract offered both a named limiter and inline throttle without choosing.
- **Fix**: Commit to a named `RateLimiter::for('login')` keyed by email+IP in `AppServiceProvider::boot()`, applied via `throttle:login` on login+register.
- **Decision**: FIXED — §7 rewritten to the named-limiter approach; inline alternative dropped.

### F2 — LoginPayload/RegisterPayload referenced but never defined

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 §5 vs §2/§4
- **Detail**: AuthService/Repository take Payload types not in the Changes file list (first layered stack sets the copied pattern).
- **Fix**: Add LoginPayload + RegisterPayload to `app/Dto/Payload/`, built from `FormRequest::validated()`; reference consistently.
- **Decision**: FIXED — §2 retitled "DTOs + Payloads", payloads added + controller usage specified.

### F3 — CORS publish command unspecified

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 1 §1
- **Detail**: "Publish CORS config" — correct command is `php artisan config:publish cors` (first-party), not vendor:publish.
- **Fix**: Name the command; set allowed_origins to the frontend origin (env), supports_credentials false.
- **Decision**: FIXED — §1 names `config:publish cors`.

### F4 — Publishing config/sanctum.php is unnecessary for the token model

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Lean Execution
- **Location**: Phase 1 §1
- **Detail**: Sanctum guard auto-registers; defaults apply for Bearer auth. Publishing adds a file with no required edits.
- **Fix**: Drop the config/sanctum.php publish (note: add later only for token expiry).
- **Decision**: FIXED — §1 retitled "CORS config"; sanctum publish removed.

### F5 — api.ts rewrite must preserve fetchHealth / HealthStatus

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Blind Spots
- **Location**: Phase 2 §3
- **Detail**: App.tsx:3 imports { fetchHealth, HealthStatus } from api.ts.
- **Fix**: Explicit note to keep those exports (additive change), or update App.tsx in lockstep.
- **Decision**: FIXED — §3 now requires preserving fetchHealth/HealthStatus.
