# Capture an Idea into the Inbox (S-01) — Plan Brief

> Full plan: `context/changes/capture-to-inbox/plan.md`

## What & Why

Build the north-star slice: a logged-in user types a free-text idea, it saves to the
Inbox, and confirmation appears in ~2 seconds. Everything downstream — clarify, routing,
the eight bucket views — is worthless if capture itself is not trustworthy, which is why
the roadmap places this first and both PRD guardrails point at it.

## Starting Point

Three foundation slices are done (quality gates, Sanctum auth, ECS logging) and nothing
under `app/` touches GTD yet. There is no `items` table, no enum anywhere in the codebase,
no `app/Const/` and no `app/Filters/`. What does exist is a complete five-layer reference
implementation in the auth slice, an `['auth:sanctum', LogContextMiddleware]` route group
that `contract-surfaces.md` explicitly reserves for "S-01 capture", a `LogEvent` helper
waiting for its first named event, and a frontend transport layer with Bearer injection
and 401 handling already working.

## Desired End State

Signing in lands the user on `/`, which is now a capture screen: one text field with the
Inbox list beneath it. Typing an idea and submitting shows it at the top of the list
within ~2 seconds, and it survives a page reload. The same data is reachable at
`GET /api/items?bucket=inbox`, and both item endpoints reject unauthenticated requests.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Inbox view scope | Read-only Inbox list ships with capture | Without it the "capture never loses an entry" guardrail cannot be observed until S-05. |
| `items` schema | Minimum plus dormant S-06/S-07 columns (`due_date`, `tags`, `context`, `important`, `urgent`) nullable | One migration instead of four; keeps the API shape stable for the frontend across later slices. |
| Dormant columns on the API | Present in `ItemDto` as read-only `null`, not writable | The TypeScript item interface never changes, while FR-011/FR-013 validation lands with the slices that own it. |
| Bucket storage | Indexed `string` column cast to a PHP backed enum | A native MySQL enum would enforce nothing under SQLite, where the whole suite runs. |
| Item content | Required `title` plus optional `note` | Matches the documented `ItemDto` example in `app/CLAUDE.md` and holds longer thoughts without a second capture step. |
| Durability | Server-side transaction plus a UI that never discards typed text on failure | Covers the real loss mode in a single-user web app without inventing an offline sync layer the PRD never asked for. |
| API contract | `POST /api/items` + `GET /api/items?bucket=inbox` | S-05 reuses the same endpoint with a different query value — no routing or client rework. |
| UI placement | Replace the health-check placeholder on `/` | The north star belongs on the landing route and the placeholder was disposable anyway. |
| No `user_id` on `items` | Omitted | `CLAUDE.md` forbids per-user isolation; the single-account invariant is already enforced by closed registration. |
| No Entity | Plain CRUD stack | Per the `app/CLAUDE.md` decision tree, capture has no business logic — the clarify tree arrives in S-02. |

## Scope

**In scope:** `items` table · `GtdBucket` enum (all eight cases) · `Item` model ·
`ItemDto` · `CaptureItemPayload` · `ItemRepositoryInterface` + implementation ·
`ItemService` · two FormRequests · `ItemController` (`store`/`index`) · two routes ·
`LogEvent::itemCaptured()` · `ItemConst` · `FreeTextSanitizer` · capture form and Inbox
list in the SPA · unit + feature tests · contract-surfaces entries.

**Out of scope:** clarify and bucket routing (S-02) · the other seven bucket views (S-05) ·
writing dates or metadata (S-06/S-07) · re-filing items (FR-010, v2) · delete, edit, undo ·
offline capture or client retry · Playwright/E2E · a `user_id` column.

## Architecture / Approach

Mirrors the auth slice layer for layer:

```
POST /api/items → CaptureItemRequest → ItemController → ItemService → ItemRepositoryInterface
                  (sanitize+validate)   (3 lines)        (defaults to        → ItemRepository (Eloquent)
                                                          GtdBucket::Inbox,      → Item model → items
                                                          emits LogEvent)
                                                                    ↑
                                        CaptureItemPayload / ItemDto carried between layers
```

The schema is provisioned wider than the behaviour: dormant columns exist but have no
write path, so later slices add validation and tests rather than migrations.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Item domain spine | Migration, enum, model, DTO, payload, repository, constants, sanitizer — no HTTP | The enum and DTO shapes are contracts for S-02…S-08; getting them wrong is expensive to unwind |
| 2. Capture and list API | Service, validation, controller, routes, domain event, OpenAPI annotations | Validation and sanitization order — sanitizing after validation would let control-character payloads through |
| 3. Capture UI and Inbox list | `/` becomes the capture screen; API client and two components | The ~2s NFR and the "never discard typed text" failure path are both manual-verification only |
| 4. Docs, gates, end-to-end | `contract-surfaces.md` entries, all gates green on a fresh database | Missing contract entries would block S-02's plan from referencing these names |

**Prerequisites:** F-02 (auth) — done. A running local API and database.
**Estimated effort:** ~3-4 sessions, one per phase.

## Open Risks & Assumptions

- The dormant-column choice trades a documented "horizontal schema" step (which the
  roadmap advises against) for a stable frontend contract; if S-06/S-07 change their field
  shape, the unused columns will need a migration anyway.
- Both the ~2 second NFR and the preserved-text failure path are verified by hand — no
  automated guard exists for either until E2E lands via `/10x-e2e`.
- Ordering by `created_at` desc with an `id` tiebreaker assumes second-resolution
  timestamps are acceptable; rapid successive captures rely on the id to sort correctly.
- No `context/foundation/test-plan.md` exists yet, so the risk ranking that would normally
  choose which of these behaviours deserve scoped per-edit test hooks is not available.

## Success Criteria (Summary)

- A signed-in user captures an idea and sees it in the Inbox within ~2 seconds, and it is
  still there after a reload.
- A failed capture never costs the user the text they typed.
- `php composer.phar quality` and the two frontend gates pass on a fresh database.
