<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Email + Password Authentication (Sanctum Bearer)

- **Plan**: context/changes/email-password-auth/plan.md
- **Scope**: All 3 phases
- **Date**: 2026-06-24
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical · 2 warnings · 4 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Register first-run gate is not atomic (check-then-act race)

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Services/AuthService.php (register) + app/Infrastructure/Auth/UserRepository.php
- **Detail**: anyUserExists() then createUser() are non-atomic; two concurrent registers with different emails can both pass the gate, defeating the single-account invariant (permanent PRD non-goal). Real-world probability is ~nil for a solo app, but the invariant is load-bearing.
- **Fix**: Wrap gate + create in a DB transaction in AuthService, re-checking anyUserExists() with a lock (lockForUpdate on MySQL; transaction serializes on SQLite).
- **Decision**: FIXED — atomic createFirstUserOrNull (DB transaction + lockForUpdate); Service maps null -> RegistrationClosedException

### F2 — me() masks a null identifier as 0 → confusing 404

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/Api/V1/AuthController.php:54-58
- **Detail**: `(int) $request->user()?->getAuthIdentifier()` → 0 if null → findOrFail(0) → 404, masking a guard misconfig. Route is auth-guarded so never null in practice.
- **Fix**: Guard explicitly (abort/throw 401 when user() is null), then pass the non-null id.
- **Decision**: FIXED — explicit null guard in me() (abort 401), no (int)null->0 masking

### F3 — Sanctum tokens never expire

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Safety & Quality
- **Location**: config/sanctum.php (by omission)
- **Detail**: PATs live forever; with localStorage storage a leaked token is valid indefinitely. NOTE: explicit plan decision (What We're NOT Doing: no token-expiry for MVP, revisit if needed).
- **Fix**: When revisited: set a finite expiration (publish config/sanctum.php) + optional log-out-everywhere.
- **Decision**: SKIPPED — token expiry is an explicit plan Non-Goal (revisit when hardening)

### F4 — Email not normalized (case-sensitive lookup + rate-limit bucket)

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Safety & Quality
- **Location**: UserRepository.php (where email) + AppServiceProvider rate limiter key
- **Detail**: Raw input email used for lookup + throttle key; case variants may auth but occupy different rate-limit buckets.
- **Fix**: Lowercase email in the FormRequest prepareForValidation().
- **Decision**: FIXED — email lowercased in Login/RegisterRequest prepareForValidation + rate-limiter key

### F5 — Frontend: a non-401 /me failure drops the user to login

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Safety & Quality
- **Location**: frontend/src/auth/AuthContext.tsx
- **Detail**: A 500/network error during rehydration clears the token → login, even if the token is valid. Acceptable for a solo app.
- **Fix**: Only clear the token on 401; treat other errors as transient.
- **Decision**: FIXED — rehydration only clears token on 401 (transient errors keep the session)

### F6 — Controllers lack @return JsonResponse<Dto> Scramble annotation

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Controllers/Api/V1/AuthController.php
- **Detail**: app/CLAUDE.md asks for it; omitted because PHPStan L6 rejects type-args on non-generic JsonResponse (deliberate adaptation). Scramble infers responses from returned DTOs.
- **Fix**: Add a JsonResponse<T> PHPStan stub later if the explicit annotation is wanted.
- **Decision**: SKIPPED — deliberate PHPStan-L6 adaptation; Scramble infers responses from DTOs
