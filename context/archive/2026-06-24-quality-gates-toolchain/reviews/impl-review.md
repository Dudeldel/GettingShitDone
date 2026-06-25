<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Quality Gates Toolchain (+ Observability Baseline)

- **Plan**: context/changes/quality-gates-toolchain/plan.md
- **Scope**: All 5 phases
- **Date**: 2026-06-24
- **Verdict**: APPROVED
- **Findings**: 0 critical · 1 warning · 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — LogEvent generic emit() invites the anti-pattern the convention forbids

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: app/Logging/LogEvent.php:27-41
- **Detail**: app/CLAUDE.md wants named static methods per event; shipped is a generic emit() (documented thin seam) that invites free-form calls and omits optional event.* fields.
- **Fix A ⭐ Recommended**: Keep the seam, record a lesson to enforce named methods when the first domain event lands.
- **Fix B**: Tighten emit() visibility (protected/internal) now.
- **Decision**: FIXED via Fix B — emit() made protected; LogEventTest exercises it via a named-method fixture

### F2 — Redaction is name-based (value-blind), recurses arrays not objects

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Safety & Quality
- **Location**: app/Logging/Processors/RedactSensitiveData.php:36-47
- **Detail**: Secrets under non-sensitive keys or inside objects aren't redacted. Documented design.
- **Fix**: Note the limitation; optionally add numeric-nested + extra-array redaction tests.
- **Decision**: SKIPPED — documented defense-in-depth design (key-based; convention says don't log secrets in values)

### F3 — "UUID v4" docstring vs Str::isUuid() accepting any UUID version

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Middleware/AssignRequestId.php:12,45
- **Detail**: Generated ids are v4; an incoming valid non-v4 UUID would be honored. No security impact.
- **Fix**: Soften docstring to "valid UUID" or tighten guard to v4.
- **Decision**: FIXED — docstring clarified ('valid UUID, any version'; generated ids are v4)

### F4 — EcsFormatter relies on NormalizerFormatter default JSON flags for bad UTF-8

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Safety & Quality
- **Location**: app/Logging/EcsFormatter.php:30-41
- **Detail**: Invalid-UTF-8 mitigated by Monolog 3 NormalizerFormatter defaults (JSON_INVALID_UTF8_SUBSTITUTE). Conscious dependency on parent.
- **Fix**: Add a regression test feeding invalid UTF-8 through the formatter.
- **Decision**: FIXED — added invalid-UTF-8 regression test to EcsFormatterTest

### F5 — HealthController missing @return JsonResponse annotation (strict checklist)

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Controllers/Api/V1/HealthController.php
- **Detail**: app/CLAUDE.md Scramble checklist wants @return JsonResponse<...> on every method.
- **Fix**: Add `@return JsonResponse` to the docblock.
- **Decision**: SKIPPED — attempted, reverted: Pint no_superfluous_phpdoc_tags forbids a bare @return duplicating the type hint, and the probe has no DTO to parametrize @return JsonResponse<...>

### F6 — composer.json has `laravel/pao` (possible typo for `laravel/pail`)

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Scope Discipline
- **Location**: composer.json:21 (require-dev)
- **Detail**: Unusual name alongside laravel/pail. PRE-EXISTING, not introduced here; CI green / installs + discovers, so not breaking. Verify intent.
- **Fix**: Confirm intentional; if a typo, fix in a separate change.
- **Decision**: SKIPPED — pre-existing, not introduced here, CI green; out of scope

### F7 — Foundation-doc drift: tech-stack.md says AWS Lightsail; repo uses Railway

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Architecture
- **Location**: context/foundation/tech-stack.md vs ci.yml / infrastructure.md
- **Detail**: Pre-existing drift from the GSD-1 Railway migration. Out of scope for this change.
- **Fix**: Update tech-stack.md to Railway in a separate docs change.
- **Decision**: FIXED — tech-stack.md deploy line reconciled to Railway
