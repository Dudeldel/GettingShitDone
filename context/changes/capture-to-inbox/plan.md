# Capture an Idea into the Inbox (S-01) Implementation Plan

## Overview

Introduce the GTD item domain spine and close the north-star flow vertically: a
logged-in user types a free-text idea on `/`, it is persisted to the Inbox, and it
appears in a read-only Inbox list with confirmation in ~2 seconds.

This is the first domain slice in the repo. Everything before it (F-01 quality gates,
F-02 auth, F-03 observability) is infrastructure; nothing under `app/` touches GTD yet.
The shapes chosen here — `GtdBucket`, `items`, `ItemDto`, `ItemRepositoryInterface` —
are what S-02…S-08 will build on, so they are treated as contracts, not scaffolding.

## Current State Analysis

**What exists.** A complete, tested five-layer reference implementation in the auth
slice: `AuthController` → `AuthService` → `UserRepositoryInterface`
(`app/Domain/Auth/`) → `UserRepository` (`app/Infrastructure/Auth/`) → `UserDto`, bound
in `AppServiceProvider::register()` (`app/Providers/AppServiceProvider.php:23`), with
domain exceptions mapped to HTTP in `bootstrap/app.php:31`. `routes/api.php:16` already
declares the `['auth:sanctum', LogContextMiddleware]` group that
`docs/reference/contract-surfaces.md` names as the home for "future protected endpoints
(S-01 capture, …)". The frontend has a working transport layer: `frontend/src/api.ts`
wraps `fetch` with Bearer injection and a global 401 handler; `AuthProvider` +
`ProtectedRoute` gate the `/` route.

**What is missing.** No `items` table, no enum anywhere in the codebase, no
`app/Const/`, no `app/Filters/`, no `app/Factory/`. `GtdBucket` will be the project's
first backed enum and sets the convention for the remaining seven buckets.
`app/Logging/LogEvent.php` exposes only a `protected static emit()` — it is designed to
be extended with named methods per slice, and currently has none.

**Constraints discovered.** Production runs MySQL on Railway; tests run SQLite
in-memory (`phpunit.xml:26`), so schema constructs must behave identically on both.
`main_goal: quality` in the roadmap and CI gates (Pint → Larastan level 6 → Pest) mean
every new surface needs annotations and tests, not just working code.
`frontend/src/App.tsx` is still the walking-skeleton health-check placeholder.

### Key Discoveries:

- Five-layer pattern to mirror exactly: `app/Services/AuthService.php:19` (HTTP-free
  orchestration) and `app/Infrastructure/Auth/UserRepository.php:15` (Eloquent confined,
  returns DTOs).
- `app/Logging/LogEvent.php:24` — `emit()` is protected on purpose; slices add named
  static methods that delegate to it. Never call a generic emitter from app code.
- `docs/reference/contract-surfaces.md` is load-bearing: `/10x-plan-review` greps plans
  against its H2 headings, so new contracts must be registered there.
- `app/CLAUDE.md` decision tree: plain CRUD with no business logic skips Entity and
  Strategy. Capture is a create with a fixed default bucket — the clarify decision tree
  (S-02) is where the Entity arrives.
- `app/CLAUDE.md` requires free text destined for stored content to be cleaned of
  control characters in `prepareForValidation()`, separate from validation.
- `tests/Pest.php:11` — Feature tests get `RefreshDatabase` automatically; Unit tests
  stay app-less and DB-less by default.

## Desired End State

A user who signs in at `/login` lands on `/`, sees a single text field, types an idea,
submits, and within ~2 seconds sees the idea at the top of an Inbox list below the
field. Reloading the page still shows it. `GET /api/items?bucket=inbox` returns the same
data over the API; `POST /api/items` without a Bearer token returns 401.

Verify with: `composer quality` green (Pint, Larastan level 6, full Pest suite),
`npm run build && npm run lint` green in `frontend/`, and the manual end-to-end pass in
Phase 4.

## What We're NOT Doing

- **No `user_id` column on `items`.** `CLAUDE.md` forbids per-user isolation ("One user,
  one database"); the single-account invariant is already enforced by registration
  closing after the first account. Adding the column would be speculative multi-tenancy.
- **No Entity, Strategy, or Factory.** Capture is plain CRUD per the `app/CLAUDE.md`
  decision tree. The clarify decision tree, routing strategies and the exactly-one-bucket
  invariant are S-02's job.
- **No clarify, no bucket transitions, no re-filing.** FR-010 is deferred to v2; S-02
  owns routing out of the Inbox.
- **No views for the other seven buckets.** The list endpoint accepts a `bucket` filter
  so S-05 can reuse it, but only the Inbox view ships here.
- **No writes to the dormant metadata columns.** `due_date`, `tags`, `context`,
  `important`, `urgent` exist in the schema and are read-only `null` in the API; S-06
  (FR-011) and S-07 (FR-013) add validation, write paths and their tests.
- **No delete, edit, or undo on items.**
- **No offline capture, draft queue, or client-side retry.** Durability in this slice is
  the server transaction plus never discarding the user's typed text on failure.
- **No E2E/Playwright tests.** Playwright is not installed; E2E lands via `/10x-e2e`
  after this slice ships.

## Implementation Approach

Mirror the auth slice layer for layer, and split the work so each phase is independently
verifiable: persistence first (no HTTP, unit-testable), then the API surface, then the
UI, then documentation and end-to-end verification.

The schema is provisioned wider than this slice uses — the dormant S-06/S-07 columns
ship nullable in the first migration — but the *behaviour* stays narrow: those columns
are absent from the write path and surface as `null` in `ItemDto`. This keeps the API
shape stable for the frontend across S-06/S-07 while leaving FR-011/FR-013 validation to
the slices that own them.

`GtdBucket` declares all eight buckets from day one even though only `inbox` is reachable
here. The enum is the vocabulary S-02…S-08 route into; introducing it partially would
mean editing it in every subsequent slice.

## Critical Implementation Details

**Bucket storage portability.** `bucket` is a plain indexed `string` column cast to the
`GtdBucket` enum in the model, not a native MySQL `enum`. SQLite (used by the whole test
suite) does not enforce native enums, so a DB-level constraint would be a guarantee that
exists in production and vanishes exactly where it is verified. The domain of valid
values is enforced by the enum cast plus `Rule::enum()` in validation.

**Sanitize and validate, both.** `CaptureItemRequest::prepareForValidation()` strips
control characters from `title` and `note` before `rules()` runs; the length rules then
reject what is left if it is still too long. Order matters: sanitizing after validation
would let a max-length payload of control characters through as an empty title.

## Phase 1: Item domain spine

### Overview

Everything below the HTTP layer: schema, enum, model, DTO, payload, repository contract
and implementation, domain constants, sanitizer. No controller, no routes, no service.
Phase ends with the spine unit-tested and the repository proven against a real database.

### Changes Required:

#### 1. Items table

**File**: `database/migrations/2026_09_07_100000_create_items_table.php`

**Intent**: Create the single table every GTD slice reads and writes. Ships the S-06/S-07
metadata columns nullable so later slices add behaviour, not schema.

**Contract**: `items` — `id` (auto-increment, matching the `users` convention),
`title` string(255) not null, `note` text nullable, `bucket` string(32) not null indexed,
`due_date` date nullable, `tags` json nullable, `context` string(64) nullable,
`important` boolean nullable, `urgent` boolean nullable, `timestamps`. The `bucket` index
serves the list query; `created_at` ordering is the list's sort key.

#### 2. The eight GTD buckets

**File**: `app/Domain/Item/GtdBucket.php`

**Intent**: The project's first backed enum and the routing vocabulary for S-02…S-08.
Placed in `app/Domain/Item/` mirroring `app/Domain/Auth/`.

**Contract**: `enum GtdBucket: string` with exactly eight cases — this list is a contract
other slices depend on, so it is spelled out:

```php
case Inbox = 'inbox';
case NextActions = 'next_actions';
case Projects = 'projects';
case Calendar = 'calendar';
case Delegation = 'delegation';
case SomedayMaybe = 'someday_maybe';
case Reference = 'reference';
case Trash = 'trash';
```

Each case carries a one-line PHPDoc comment — Scramble turns those into the OpenAPI schema
descriptions.

#### 3. Item model

**File**: `app/Models/Item.php`

**Intent**: ORM mapping only. No business logic.

**Contract**: `@property` PHPDoc for every column (Larastan level 6 needs it), a
`#[Fillable]` attribute covering `title`, `note`, `bucket`, and casts: `bucket` →
`GtdBucket::class`, `due_date` → `date`, `tags` → `array`, `important`/`urgent` →
`boolean`. Dormant columns are cast but not fillable — they have no write path in this
slice.

#### 4. Item DTO

**File**: `app/Dto/ItemDto.php`

**Intent**: The transport shape between layers and the API response body. Carries the
dormant fields as always-`null` reads so the frontend's TypeScript interface stays stable
through S-06/S-07.

**Contract**: implements `Arrayable` + `JsonSerializable` with
`@implements Arrayable<string, mixed>`; `fromArray()` takes camelCase, `toArray()` returns
camelCase. Field set and the typed `@return array{...}` on `jsonSerialize()`:
`id: int`, `title: string`, `note: string|null`, `bucket: string` (the constructor
property is the `GtdBucket` enum; only the serialized shape is a string),
`dueDate: string|null`, `tags: list<string>|null`, `context: string|null`,
`important: bool|null`, `urgent: bool|null`, `createdAt: string`, `updatedAt: string`
(timestamps as ISO-8601, matching `HealthController`'s `toIso8601String()`).

#### 5. Capture payload

**File**: `app/Dto/Payload/CaptureItemPayload.php`

**Intent**: The capture command carried from controller to service to repository,
mirroring `LoginPayload`.

**Contract**: readonly `title: string`, `note: ?string`, plus `fromArray()` / `toArray()`.
The bucket is not a payload field — capture always targets `GtdBucket::Inbox`, and that
default lives in the service, not in user input.

#### 6. Repository contract and implementation

**File**: `app/Domain/Item/ItemRepositoryInterface.php`, `app/Infrastructure/Item/ItemRepository.php`

**Intent**: Confine Eloquent behind an interface exactly as `UserRepositoryInterface` does.
Returns DTOs; no Model escapes `app/Infrastructure/`.

**Contract**: two methods —
`create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto` and
`listByBucket(GtdBucket $bucket): Collection` annotated `@return Collection<int, ItemDto>`,
ordered newest first (`created_at` desc, `id` desc as the tiebreaker so ordering is
deterministic when two items share a timestamp — realistic on fast captures and on
SQLite's second-resolution timestamps).

#### 7. Domain constants and sanitizer

**File**: `app/Const/ItemConst.php`, `app/Filters/FreeTextSanitizer.php`

**Intent**: Bind the length limits to one source so validation, the DTO and any later
display logic cannot drift, and provide the control-character scrub `app/CLAUDE.md`
requires for stored free text.

**Contract**: `ItemConst::TITLE_MAX_LENGTH = 255` (matches the column) and
`ItemConst::NOTE_MAX_LENGTH = 10000`, each with a PHPDoc "why".
`FreeTextSanitizer::sanitize(string): string` and `sanitizeNullable(?string): ?string`,
stripping `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` while keeping LF and TAB. First class in
`app/Filters/` — technical, not domain, per `app/CLAUDE.md`.

#### 8. Container binding

**File**: `app/Providers/AppServiceProvider.php`

**Intent**: Bind the new interface next to the existing `UserRepositoryInterface` binding.

**Contract**: `ItemRepositoryInterface` → `ItemRepository` in `register()`.

#### 9. Tests

**File**: `tests/Unit/Item/ItemDtoTest.php`, `tests/Unit/Item/GtdBucketTest.php`, `tests/Unit/Filters/FreeTextSanitizerTest.php`, `tests/Feature/Item/ItemRepositoryTest.php`

**Intent**: Prove the spine before any HTTP exists.

**Contract**: Unit — DTO round-trips `fromArray`/`toArray` including null dormant fields;
`GtdBucket` has exactly eight cases with the string values above (guards against a rename
silently breaking S-02); the sanitizer strips control characters and preserves LF/TAB.
Feature (needs DB) — `create()` returns a DTO with `bucket = inbox` and null dormant
fields; `listByBucket()` returns only the requested bucket, newest first; `it('...')`
naming and `Response::HTTP_*` constants per `tests/CLAUDE.md`.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly on a fresh database: `php artisan migrate:fresh`
- Unit tests pass: `php artisan test tests/Unit`
- Repository test passes: `php artisan test tests/Feature/Item/ItemRepositoryTest.php`
- Larastan level 6 reports 0 errors: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint reports no style issues: `./vendor/bin/pint --test`

#### Manual Verification:

- Schema inspection confirms `bucket` is indexed and all five S-06/S-07 columns are nullable

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding to the next phase.

---

## Phase 2: Capture and list API

### Overview

The HTTP surface: service, validation, controller, routes, domain logging and OpenAPI
annotations. Ends with both endpoints exercised end-to-end by feature tests.

### Changes Required:

#### 1. Item service

**File**: `app/Services/ItemService.php`

**Intent**: Orchestrate capture and listing. Owns the "captured items go to the Inbox"
default and emits the domain event. HTTP-free, like `AuthService`.

**Contract**: `capture(CaptureItemPayload $payload): ItemDto` — delegates to the
repository with `GtdBucket::Inbox`, then calls `LogEvent::itemCaptured()`.
`listByBucket(GtdBucket $bucket): Collection` annotated `@return Collection<int, ItemDto>`.
No `Illuminate\Http` import.

#### 2. Domain event

**File**: `app/Logging/LogEvent.php`

**Intent**: Add the first named domain event, establishing the pattern the docblock
describes for `itemClarified()` and the rest.

**Contract**: `public static function itemCaptured(int $itemId, GtdBucket $bucket): void`
delegating to `emit()` with action `item.captured.success`, category `database`, outcome
`success`, and flat context `item_id` + `bucket`. Never a raw `Log::info`.

#### 3. Request validation

**File**: `app/Http/Requests/CaptureItemRequest.php`, `app/Http/Requests/ListItemsRequest.php`

**Intent**: Validate and sanitize the two inputs. Rules as arrays, limits from
`ItemConst`, per `app/CLAUDE.md`.

**Contract**: `CaptureItemRequest` — `prepareForValidation()` runs
`FreeTextSanitizer::sanitize` on `title` and `sanitizeNullable` on `note`; rules
`title => ['required','string','max:'.ItemConst::TITLE_MAX_LENGTH]`,
`note => ['nullable','string','max:'.ItemConst::NOTE_MAX_LENGTH]`. Field comments become
OpenAPI descriptions. `ListItemsRequest` — `bucket` marked `@query`, rules
`['nullable', Rule::enum(GtdBucket::class)]`; absent means Inbox.

#### 4. Controller and routes

**File**: `app/Http/Controllers/Api/V1/ItemController.php`, `routes/api.php`

**Intent**: Map HTTP to the service and back. Two methods, ~3 lines each, no logic.

**Contract**: `store(CaptureItemRequest, ItemService): JsonResponse` → 201
(`Response::HTTP_CREATED`) with the created `ItemDto`;
`index(ListItemsRequest, ItemService): JsonResponse` → 200 with the list. Scramble
requires a summary line plus `@return JsonResponse<ItemDto>` and
`@return JsonResponse<list<ItemDto>>` respectively. Routes join the existing
`['auth:sanctum', LogContextMiddleware]` group in `routes/api.php`:
`POST /api/items` → `store`, `GET /api/items` → `index`.

#### 5. Tests

**File**: `tests/Feature/Item/CaptureItemTest.php`, `tests/Feature/Item/ListItemsTest.php`, `tests/Unit/Item/ItemServiceTest.php`

**Intent**: Cover the contract and the failure modes, not just the happy path.

**Contract**: Capture — 201 with the full item shape, persisted row, `bucket` defaults to
`inbox`, dormant fields `null`; control characters in `title` are stripped before storage;
422 on missing `title` and on over-length `title`; 401 without a token. List — returns
only Inbox items, newest first; 401 without a token; 422 on an unknown `bucket` value.
Unit — `ItemService` against a hand-rolled fake repository (no DB, no app): capture passes
`GtdBucket::Inbox` to the repository and returns its DTO.

### Success Criteria:

#### Automated Verification:

- Item feature tests pass: `php artisan test tests/Feature/Item`
- Service unit test passes: `php artisan test tests/Unit/Item/ItemServiceTest.php`
- Full suite passes: `php composer.phar test`
- Larastan level 6 reports 0 errors: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint reports no style issues: `./vendor/bin/pint --test`
- OpenAPI export succeeds and contains both operations: `php artisan scramble:export`

#### Manual Verification:

- `curl -H "Authorization: Bearer <token>" -d '{"title":"test"}' localhost:8000/api/items` returns 201 with the item
- The same call without the Authorization header returns 401

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 3: Capture UI and Inbox list

### Overview

Replace the walking-skeleton placeholder on `/` with the north-star screen: one text
field, a submit action, and the Inbox list beneath it.

### Changes Required:

#### 1. API client

**File**: `frontend/src/api.ts`

**Intent**: Add the two item calls on top of the existing `request<T>()` helper, which
already handles Bearer injection, 401 logout and JSON errors.

**Contract**: `export interface Item` mirroring `ItemDto` field-for-field (camelCase,
dormant fields typed nullable); `captureItem(title: string, note?: string): Promise<Item>`
→ `POST /api/items`; `listItems(bucket?: string): Promise<Item[]>` → `GET /api/items`.
Update the stale "App.tsx depends on these" comment above the health helpers.

#### 2. Capture form

**File**: `frontend/src/items/CaptureForm.tsx`

**Intent**: The lowest-friction capture surface: one field, submit on Enter, confirmation.
Implements the durability decision — a failed submit never discards what the user typed.

**Contract**: props `onCaptured(item: Item): void`. On submit: disable the control, call
`captureItem`, clear the field and hand the item up **only on success**. On failure: keep
the field contents, re-enable, and render the error message. Submitting empty or
whitespace-only input is a no-op.

#### 3. Inbox list

**File**: `frontend/src/items/InboxList.tsx`

**Intent**: Read-only proof that the capture survived.

**Contract**: props `items: Item[]`. Renders title, optional note, and a relative or ISO
timestamp per row, newest first (server-ordered — no client re-sort). Empty state when the
list is empty.

#### 4. Inbox screen

**File**: `frontend/src/App.tsx`

**Intent**: `/` becomes the capture screen. The health-check placeholder is removed from
the UI; the endpoint and its feature test stay.

**Contract**: keeps the existing header with the signed-in email and the logout button;
below it renders `CaptureForm` then `InboxList`. Loads the Inbox via `listItems()` on
mount; prepends the item returned by `onCaptured` rather than refetching, so confirmation
does not wait on a second round trip (the ~2s NFR).

### Success Criteria:

#### Automated Verification:

- Frontend build and typecheck pass: `cd frontend && npm run build`
- Frontend lint passes: `cd frontend && npm run lint`

#### Manual Verification:

- Sign in, type an idea, submit — confirmation and the item appear in under ~2 seconds
- Reload the page — the item is still listed (persistence, not local state)
- Stop the API and submit — an error is shown and the typed text is still in the field
- The Inbox list shows newest first

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 4: Documentation, gates and end-to-end verification

### Overview

Register the new contracts where later plans and reviews look for them, and prove the
whole slice green on a clean database.

### Changes Required:

#### 1. Contract surfaces

**File**: `docs/reference/contract-surfaces.md`

**Intent**: `/10x-plan-review` greps plans against this file's H2 headings, so the new
load-bearing names must be registered or S-02's plan will not be able to reference them.

**Contract**: new H2 sections — `Item endpoints` (the two routes, auth group, status
codes, the `bucket` query filter), `GtdBucket` (the eight case values and the
string-column-plus-cast decision), `ItemRepositoryInterface` (the two methods, DTO-only
return, ordering guarantee, and the deliberate absence of pagination — deferred per PRD
Open Question #2 "list-view responsiveness target"), `LogEvent::itemCaptured` (action name and context keys), and
`ItemDto` (the field set, including the dormant read-only fields and which slice fills
each).

#### 2. Full verification

**File**: — (no file change)

**Intent**: Run every gate the CI pipeline runs, locally, on a fresh database.

**Contract**: `php composer.phar quality` (Pint → Larastan level 6 → Pest) plus the two
frontend gates, against `php artisan migrate:fresh`.

### Success Criteria:

#### Automated Verification:

- All backend gates pass: `php composer.phar quality`
- Frontend gates pass: `cd frontend && npm run build && npm run lint`
- OpenAPI export succeeds: `php artisan scramble:export`

#### Manual Verification:

- Fresh-database walkthrough: `migrate:fresh` → register → sign in → capture → item listed
- `docs/reference/contract-surfaces.md` sections match the names actually shipped
- The generated OpenAPI document shows both item operations under the Bearer scheme

---

## Testing Strategy

### Unit Tests:

- `ItemDto` round-trip including null dormant fields
- `GtdBucket` has exactly eight cases with the agreed string values (guards S-02…S-08)
- `FreeTextSanitizer` strips control characters, preserves LF and TAB
- `ItemService::capture` targets `GtdBucket::Inbox` and returns the repository's DTO
  (fake repository, no DB)

### Integration Tests:

- `ItemRepository` against a real database: create returns an Inbox DTO with null dormant
  fields; `listByBucket` filters and orders newest first
- `POST /api/items`: 201 and persisted row; control characters stripped; 422 on missing and
  over-length `title`; 401 unauthenticated
- `GET /api/items`: Inbox-only by default, newest first; 422 on an unknown bucket; 401
  unauthenticated

### Manual Testing Steps:

1. `php artisan migrate:fresh`, then register and sign in through the SPA
2. Type an idea, submit, and confirm it appears in under ~2 seconds
3. Reload — the item is still there
4. Stop the API, submit again — error shown, typed text preserved
5. Capture two items in quick succession — the newer one sorts first

## Performance Considerations

The only committed NFR is ~2 second capture confirmation. The write is a single insert on
a table with one index; the round trip dominates. The UI prepends the item returned by the
`POST` response instead of refetching the list, so confirmation costs one request, not two.

Octane/Swoole keeps workers resident: nothing in this slice may hold request state in a
singleton or static property (`infrastructure.md` flags worker state leakage). The service
and repository are stateless per request; `ItemConst` holds only compile-time constants.

## Migration Notes

Additive only — one new table, no changes to existing tables, no data backfill. `down()`
drops `items`. Fresh installs and existing databases both reach the same state with
`php artisan migrate`.

## References

- Roadmap slice: `context/foundation/roadmap.md` → "S-01: Capture an idea into the Inbox"
- Product requirements: `context/foundation/prd.md` → FR-001, US-01, NFR, Guardrails
- Reference implementation to mirror: `context/archive/2026-06-24-email-password-auth/plan.md`
- Contracts to extend: `docs/reference/contract-surfaces.md`
- Layer rules: `app/CLAUDE.md`; test conventions: `tests/CLAUDE.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Item domain spine

#### Automated

- [x] 1.1 Migration applies cleanly on a fresh database — 37ba633
- [x] 1.2 Unit tests pass — 37ba633
- [x] 1.3 Repository test passes — 37ba633
- [x] 1.4 Larastan level 6 reports 0 errors — 37ba633
- [x] 1.5 Pint reports no style issues — 37ba633

#### Manual

- [x] 1.6 Schema inspection confirms indexed `bucket` and nullable S-06/S-07 columns — 37ba633

### Phase 2: Capture and list API

#### Automated

- [ ] 2.1 Item feature tests pass
- [ ] 2.2 Service unit test passes
- [ ] 2.3 Full suite passes
- [ ] 2.4 Larastan level 6 reports 0 errors
- [ ] 2.5 Pint reports no style issues
- [ ] 2.6 OpenAPI export succeeds and contains both operations

#### Manual

- [ ] 2.7 Authenticated curl returns 201 with the item
- [ ] 2.8 Unauthenticated curl returns 401

### Phase 3: Capture UI and Inbox list

#### Automated

- [ ] 3.1 Frontend build and typecheck pass
- [ ] 3.2 Frontend lint passes

#### Manual

- [ ] 3.3 Capture confirms and lists in under ~2 seconds
- [ ] 3.4 Item survives a page reload
- [ ] 3.5 API failure shows an error and preserves the typed text
- [ ] 3.6 Inbox list shows newest first

### Phase 4: Documentation, gates and end-to-end verification

#### Automated

- [ ] 4.1 All backend gates pass
- [ ] 4.2 Frontend gates pass
- [ ] 4.3 OpenAPI export succeeds

#### Manual

- [ ] 4.4 Fresh-database walkthrough completes
- [ ] 4.5 Contract surfaces match the shipped names
- [ ] 4.6 OpenAPI shows both item operations under the Bearer scheme
