<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Capture an Idea into the Inbox (S-01) — Phase 2

- **Plan**: `context/changes/capture-to-inbox/plan.md`
- **Scope**: Phase 2 of 4 — "Capture and list API"
- **Commit**: `6de4e76`
- **Date**: 2026-09-07
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 4 warnings, 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | WARNING |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

All six automated criteria re-run independently and pass: item feature tests 22/54,
service unit test 3/6, full suite 59/142, Larastan level 6 zero errors, Pint clean,
`scramble:export` succeeds with both operations. No scope creep: all 11 files in the
commit map onto planned phase 2 items plus the two agreed documentation edits. Phase 1's
domain spine was not touched, and every contract it declared is consumed as declared.

## Findings

### F1 — Captured free text reaches error logs, bypassing redaction

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Infrastructure/Item/ItemRepository.php:16-20`, entered from `app/Services/ItemService.php:28`
- **Detail**: `Item::query()->create([...])` throws `QueryException` on any DB failure, and
  Laravel builds that message by substituting the bindings into the SQL. Laravel's default
  handler then reports it with `Log::error($e->getMessage(), …)`. `RedactSensitiveData`
  (`app/Logging/Processors/RedactSensitiveData.php:36-47`) redacts by **key name** inside
  `context`/`extra` — it never inspects the message string. Reproduced directly:
  `SQLSTATE[HY000] … SQL: insert into "items" ("title","note","bucket",…) values
  (my bank pin is 4711, aws key AKIAEXAMPLE, inbox, …)`. A GTD inbox is precisely where a
  user pastes a password or API key "to sort later", and production ships logs off-box.
- **Fix A ⭐ Recommended**: Catch `QueryException` in `ItemRepository::create` and rethrow a
  domain exception that carries no bindings.
  - Strength: Fixes it at the seam that owns the Eloquent call, keeps the DTO boundary
    clean, and gives the service a typed failure to react to (see F6).
  - Tradeoff: One more exception class and a mapping entry in `bootstrap/app.php`.
  - Confidence: HIGH — mirrors the auth slice's `InvalidCredentialsException` pattern.
  - Blind spot: Other future write paths would each need the same treatment.
- **Fix B**: Add a Monolog processor that strips the `, SQL: …)` tail from every message.
  - Strength: Covers every query in the app at once, including ones not yet written.
  - Tradeoff: Blunt string surgery on log messages; can mangle legitimate text and hides
    debugging detail that is genuinely useful in development.
  - Confidence: MEDIUM — depends on the message format staying stable across Laravel versions.
  - Blind spot: Not verified against every driver's exception format.
- **Decision**: FIXED via Fix A — QueryException confined in ItemRepository::create and rethrown as ItemPersistenceException carrying only the SQLSTATE (no `previous` chain); mapped to a fixed-message 500. Two regression tests assert the captured text reaches neither the response nor the exception.

### F2 — "Capture cannot be steered by the client" has no test that can fail

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `tests/Feature/Item/CaptureItemTest.php`, `app/Models/Item.php:28`
- **Detail**: The path is safe today — `POST /api/items {"title":"x","bucket":"trash"}`
  returns 201 with `bucket: "inbox"`, because `CaptureItemRequest::rules()` whitelists only
  `title`/`note` and `CaptureItemPayload` has no bucket field. But `bucket` **is** in
  `#[Fillable(['title','note','bucket'])]` while the dormant columns were deliberately
  excluded, and the only test claiming the guarantee — "captures into the Inbox regardless
  of what the caller passes" (`tests/Unit/Item/ItemServiceTest.php:54`) — cannot fail,
  because the payload type it receives has no bucket field to carry. `grep` confirms no
  test posts `bucket` in a request body. A future edit adding `bucket` to `rules()` for the
  clarify endpoint would pass CI silently.
- **Fix**: Add a feature test that posts `bucket`, `id` and `important` in the body and
  asserts the stored item is still `inbox` with null metadata.
- **Decision**: FIXED — feature test posts bucket/id/important/dueDate in the body and asserts the stored item is still inbox with null metadata. Proven able to fail: pointing the service at Trash turns it red.

### F3 — `ListItemsRequest` puts a domain default at the HTTP edge and publishes a contradictory contract

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: `app/Http/Requests/ListItemsRequest.php:20-25`, `:38`
- **Detail**: Two symptoms, one cause. (a) The plan contracted
  `['nullable', Rule::enum(GtdBucket::class)]`; the implementation uses `'required'` plus a
  `prepareForValidation()` that merges `inbox`. Behaviour is equivalent, but the generated
  OpenAPI now says `bucket: required: true` while its own description reads "Defaults to
  the Inbox" — verified in the export. Any generated client, including phase 3's frontend
  which calls `GET /api/items` bare, is told the parameter is mandatory. (b) "Absent means
  Inbox" is a domain rule, and it is the *same* rule `ItemService::capture` already owns at
  `app/Services/ItemService.php:28`, applied there precisely because it "must not be client
  input". It now lives in two layers, and `app/CLAUDE.md`'s block map says a FormRequest
  holds "validation, custom rules; NOT business logic".
- **Fix A ⭐ Recommended**: Revert the rule to `['nullable', Rule::enum(…)]`, drop
  `prepareForValidation()`, and give the enum a `GtdBucket::default()` the accessor falls
  back to.
  - Strength: Fixes the spec and the layering in one edit; the default sits with the domain
    vocabulary, so changing it never means editing an `app/Http/Requests/` file. No test
    outcome changes.
  - Tradeoff: Adds a method to the enum that only one caller uses today.
  - Confidence: HIGH — the plan already specified `nullable`; this restores it.
  - Blind spot: None significant.
- **Fix B**: Keep the current shape and only swap `required` → `nullable` to fix the spec.
  - Strength: Smallest possible diff; the contract stops lying immediately.
  - Tradeoff: Leaves the domain default duplicated at the HTTP edge.
  - Confidence: HIGH.
  - Blind spot: The duplication is the part that bites later, not the spec flag.
- **Decision**: FIXED via Fix A — rule back to `nullable`, prepareForValidation removed, GtdBucket::default() added as the accessor fallback. Verified: the exported spec now says bucket required: false.

### F4 — Unbounded listing is now reachable over HTTP

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `app/Services/ItemService.php:38-41`, `routes/api.php:23`
- **Detail**: `listByBucket` loads every row in a bucket, maps each to a DTO and serializes
  the lot into one response. With a 10,000-character `note` cap and no write throttle, a
  long-lived Inbox becomes a multi-megabyte response and a proportional memory spike. This
  was assessed in the phase 1 review (finding F4 there) and deliberately deferred per PRD
  Open Question #2 — recorded again because the deferral now has an HTTP surface, not just
  a repository method.
- **Fix**: Add a hard `limit()` in the repository until real pagination lands, or reaffirm
  the deferral now that it is publicly reachable.
- **Decision**: ACCEPTED — deferral from the phase 1 review reaffirmed now that the method has an HTTP surface; PRD Open Question #2 stands.

### F5 — No rate limit on the item routes

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `routes/api.php:17-24`
- **Detail**: The group carries only `auth:sanctum` + `LogContextMiddleware`. Laravel 13
  emits no default `throttle:api` unless `throttleApi()` is called, and `bootstrap/app.php`
  never calls it — only `login` is a registered limiter. For a single-user app the
  authenticated abuse surface is the owner, so per-user throttling has low value, but two
  gaps remain: an unauthenticated flood still costs a `personal_access_tokens` lookup per
  request before the 401, and a leaked bearer token has unlimited write throughput while
  the credential that mints it is capped at 5/min.
- **Fix**: Register an `api` limiter keyed by user id or IP and apply `throttle:api` to the
  group — two lines, closes both gaps.
- **Decision**: FIXED — an `api` limiter (60/min, keyed by user id else IP) registered and applied to the protected group; a feature test proves the 61st request returns 429. CORRECTION: the middleware priority list runs Authenticate ahead of ThrottleRequests, so this caps a leaked token but does NOT shield the token lookup from an unauthenticated flood; the code comment was rewritten to say so.

### F6 — No failure-path domain event

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `app/Services/ItemService.php:26-33`
- **Detail**: `capture()` has no `try/catch`, so a repository failure produces only the
  framework's error log with no `item.captured.failure` event. Given the slice's guardrail
  ("capture never loses an entry"), the outcome most worth alerting on is the one with no
  structured event. The ordering is otherwise correct: the success event fires only after
  the write returns, so there are no false-positive success events.
- **Fix**: Add `LogEvent::itemCaptureFailed()` and emit it from a `try/catch` that rethrows
  — pairs naturally with F1's domain exception.
- **Decision**: FIXED — LogEvent::itemCaptureFailed() emitted from a try/catch that rethrows; unit test asserts the failure event fires at error level, carries the SQLSTATE, and contains none of the captured text.

### F7 — Boundary test hard-codes 256 instead of deriving it from the constant

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/Item/CaptureItemTest.php:75`
- **Detail**: `str_repeat('a', 256)` restates the limit as a literal. Raise
  `ItemConst::TITLE_MAX_LENGTH` and the test stays green while no longer probing the
  boundary — the exact drift the constant exists to prevent, and the same class of finding
  as phase 1's F7.
- **Fix**: `str_repeat('a', ItemConst::TITLE_MAX_LENGTH + 1)`.
- **Decision**: FIXED — boundary derived from ItemConst::TITLE_MAX_LENGTH + 1.

### F8 — Validation coverage gaps on `note`

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `tests/Feature/Item/CaptureItemTest.php`
- **Detail**: `prepareForValidation()` sanitizes both `title` and `note`, and `rules()`
  caps both, but the tests only exercise `title`: no `NOTE_MAX_LENGTH` boundary case and no
  control-character case on `note`. Half the sanitize/validate surface is unprotected.
- **Fix**: Mirror the two existing `title` cases for `note`.
- **Decision**: FIXED — added the NOTE_MAX_LENGTH boundary case and a control-character case on note.

### F9 — Dead code, and a doc example the slice deliberately did not follow

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Filters/FreeTextSanitizer.php:27`, `app/Dto/Payload/CaptureItemPayload.php:32-39`, `app/CLAUDE.md:201`
- **Detail**: `CaptureItemRequest` guards both fields with `is_string()` rather than calling
  `sanitizeNullable` on `note` — a deliberate improvement (a `{"note": []}` payload yields
  422 instead of a `TypeError` → 500), but it leaves `sanitizeNullable()` with no production
  caller, and `app/CLAUDE.md:201` still shows the `sanitizeNullable` pattern as the way to
  do this. Separately, `CaptureItemPayload::toArray()` has no caller anywhere — the
  repository builds its insert array by hand.
- **Fix**: Update the `app/CLAUDE.md` example to the `is_string()` guard and say why; decide
  whether the two unused methods stay as API surface or go.
- **Decision**: FIXED — app/CLAUDE.md example rewritten to the is_string() guard with the rationale; FreeTextSanitizer::sanitizeNullable() and CaptureItemPayload::toArray() deleted along with the two tests that were their only callers.

### F10 — Global test helper functions will fatal if a later slice reuses the names

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/Item/ListItemsTest.php:10`, `tests/Unit/Item/ItemServiceTest.php:20`
- **Detail**: Both declare file-scope global functions (`seedItem`, `fakeItemRepository`).
  Pest loads every test file into one process, so a future slice declaring its own
  `seedItem()` is a fatal redeclaration error, not a test failure. Names are unique today;
  S-02…S-08 will add many more such helpers.
- **Fix**: Move shared helpers into `tests/Pest.php` (where `makeLogRecord` already lives)
  or bind them as closures in `beforeEach`.
- **Decision**: FIXED — seedItem() and fakeItemRepository() moved into tests/Pest.php beside makeLogRecord.
