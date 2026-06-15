# app/ — backend code conventions

Rules for code under `app/` (the Laravel REST API). Read the root @../CLAUDE.md first for the project overview, the one-directional layering rules, the GTD domain mapping, and the "what NOT to copy from the twin" tripwires (no multi-tenancy, no sharding). This file is the detailed *how*.

## Where each kind of code goes (block map)

| Block | Directory | Has | Has NOT |
| --- | --- | --- | --- |
| FormRequest | `app/Http/Requests/` | validation, custom rules | business logic |
| Controller | `app/Http/Controllers/Api/V1/` | HTTP↔Service mapping, Scramble PHPDoc | logic, DB, validation |
| Service | `app/Services/` | orchestration, transactions, statuses, multi-repo calls | Eloquent, SQL, HTTP |
| Job | `app/Jobs/` | set context + call Service | business logic |
| Entity (domain) | `app/Domain/<Context>/` | business logic, state validation, ↔DTO | DB, framework |
| Strategy | `app/Domain/<Context>/Strategies/` | one concrete algorithm | other algorithms, DB |
| Value Object | `app/Domain/<Context>/ValueObjects/` | one immutable domain value | ID, persistence |
| Payload | `app/Dto/Payload/` | a business-operation command | entity state |
| DTO | `app/Dto/` | fields + getters + `fromArray`/`toArray`/`jsonSerialize` | logic, DB |
| Factory (DTO) | `app/Factory/` | build a complex DTO from a Model | business logic |
| Factory (Strategy) | `app/Factory/` | pick an implementation by enum | the algorithm |
| Repository interface | `app/Domain/Repositories/` or `app/Domain/<Context>/` | "what data access can do" contract | implementation |
| Repository impl | `app/Infrastructure/Repositories/` or `app/Infrastructure/<Context>/` | Eloquent, transactions, connection | business logic |
| Model | `app/Models/` | ORM mapping, relations, scopes | business logic |
| Validator | `app/Validators/` | reusable `Rule` (incl. `DataAwareRule`) | — |
| Domain constant | `app/Const/` | business-meaning constants | infra config (→ `config/`) |

Directory-naming: a large context (e.g. `Clarify`) gets its own `Domain/Clarify/` + `Infrastructure/Clarify/`. Small entities land flat in `Domain/Repositories/` + `Infrastructure/Repositories/`.

## Which block to use (decision tree)

```
Adding a new entity?
├─ Plain CRUD, no business logic?
│    → Migration + Model + DTO + Repository interface+impl + Service + Controller + FormRequest
│
├─ Business logic (validations, computations, rules)?
│    → + Entity in Domain/<Context>/ (business methods, fromDto/toDto)
│    → Service builds the Entity, calls methods, converts back to DTO
│
├─ Many algorithm variants (e.g. routing branches, scoring methods)?
│    → + Strategy interface in Domain/<Context>/Strategies/
│    → + one implementation per variant
│    → + Factory in Factory/ with a match on an enum
│
├─ Small immutable value (a period, a quadrant, a money amount)?
│    → Value Object in Domain/<Context>/ValueObjects/ (readonly class + fromArray + toArray + jsonSerialize)
│
├─ A business operation that changes entity state (clarify, complete, defer)?
│    → Payload in Dto/Payload/ (a command, not state)
│    → its own Controller + a Service method
│
├─ Long / batch / async work?
│    → Job in Jobs/ — Controller dispatches, returns 202 + a transaction id
│    → Service wraps the batch in a DB transaction with markPending → markCompleted/markFailed
│
└─ Needs data from several sources (Model + relations)?
     → Factory (DTO) in Factory/ — builds the composite DTO from a loaded Model
```

A CRUD-only entity (tags, contexts) skips Entity/Strategy and uses the plain five-layer stack.

## Controller shape (slim to ~3 lines)

```php
public function store(CreateItemRequest $request, ItemService $service): JsonResponse
{
    return response()->json(
        $service->create(ItemDto::fromArray($request->validated())),
        Response::HTTP_CREATED,
    );
}
```

If you see an `if`, a loop, or a calculation in a controller, that logic belongs in a Service or Entity.

## Reference patterns (CRUD vs async)

**Plain CRUD** — `POST /v1/items`:

```
ItemController::store
  ├─ CreateItemRequest (validation)
  ├─ ItemDto::fromArray($validated)
  └─ ItemService::create($dto)
        └─ ItemRepositoryInterface::create($dto)
              └─ ItemRepository::create  (Eloquent)
```

Five short files; the Service is nearly empty — the sign there's no business logic.

**Async / batch with business logic** — controller returns `202 Accepted` + a transaction id, a Job picks it off the queue and sets up its own context before calling the Service, the Service wraps the batch in a DB transaction with status transitions, and an Entity does the actual computation. Non-idempotent jobs set `tries = 1` and guard against re-runs (NO-OP if the transaction is already terminal).

## Rules often broken on day one

- Service must not import `Illuminate\Http`. If you see `Request` there, logic leaked from the controller.
- Repository returns DTO / `Collection<DTO>`, never Models.
- Transaction wraps the **Service**, not the Repository. (Repo may expose a `transaction(callable)` helper; the Service wraps the batch in it.)
- Transaction status: `markPending → try { … markCompleted } catch { markFailed; throw }`.
- DTO-from-request: `Dto::fromArray($request->validated())` — snake_case in (from validation), camelCase out (to API).
- `Response::HTTP_*` constants always. `201` → `Response::HTTP_CREATED`, `202` → `Response::HTTP_ACCEPTED`.

---

# Conventions

## HTTP status codes — constants, never integers

In production code **and** tests, use `Response` constants:

```php
// GOOD
return response()->json($data, Response::HTTP_CREATED);
$response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
// BAD
return response()->json($data, 201);
$response->assertStatus(409);
```

`Response::HTTP_CONFLICT` is self-documenting and typo-proof in a way `409` is not.

## Namespaces — always via `use`, never inline FQN

Reference classes via a `use` statement at the top of the file — never a fully-qualified namespace inline. This includes PHPDoc (`@property`, `@param`, `@return`, `@throws`, `@implements`, `@see`).

```php
// GOOD
use Illuminate\Support\Carbon;
use JsonSerializable;

/** @property Carbon|null $created_at */
class Foo implements JsonSerializable { /* … */ }

// BAD
class Foo implements \JsonSerializable { /** @property \Illuminate\Support\Carbon|null $created_at */ }
```

In Pest: `use Illuminate\Foundation\Testing\RefreshDatabase; uses(RefreshDatabase::class);` — not inline FQN in `uses()`. Why: dependencies visible in one `use` block; unused/duplicate imports obvious; a namespace refactor is a one-line edit.

## DTO — always `fromArray()` alongside `toArray()`

A DTO both serializes to an array (`toArray`) and constructs itself from one (`fromArray`). `fromArray` takes **camelCase** keys (from `FormRequest::validated()`); `toArray` returns **camelCase** (API output).

```php
class ItemDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $title,
        public readonly ?string $note,
    ) {}

    public static function fromArray(array $item): self
    {
        return new self(
            itemId: (string) $item['itemId'],
            title:  (string) $item['title'],
            note:   isset($item['note']) ? (string) $item['note'] : null,
        );
    }

    public function toArray(): array
    {
        return ['itemId' => $this->itemId, 'title' => $this->title, 'note' => $this->note];
    }

    public function jsonSerialize(): array { return $this->toArray(); }
}
```

## DTO Factory — listing vs detail variants

When the same resource has a light shape (listing/search) and a full shape (detail with relations), use **one** DTO class with nullable "heavy" fields plus a Factory in `app/Factory/`:

- DTO has all fields; heavy ones are `nullable` with default `null`.
- Factory exposes `createListing(Model $m): Dto` (no relations) and `createDetail(Model $m): Dto` (materializes relations).
- `Dto::fromArray($validated)` maps **request → DTO** (input); the Factory maps **Model → DTO** (output), especially with multiple shapes.

## Input sanitization in `prepareForValidation()`

Free text destined for stored content / generated documents / HTML is cleaned of control characters in the FormRequest's `prepareForValidation()` hook (before `rules()`), separate from validation — sanitize **and** validate (defense-in-depth). Sanitizer classes live in `app/Validators/` (domain) or `app/Filters/` (technical), with a static `sanitize(string $value): string`.

```php
final class FreeTextSanitizer
{
    public static function sanitize(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value); // keep LF, TAB
    }
    public static function sanitizeNullable(?string $value): ?string
    {
        return $value === null ? null : self::sanitize($value);
    }
}

// In the FormRequest
protected function prepareForValidation(): void
{
    $this->merge(['note' => FreeTextSanitizer::sanitizeNullable($this->input('note'))]);
}
```

A regex in `rules()` is a *check* (rejects with 422); sanitization in `prepareForValidation()` *fixes* the input before validation — keep both side by side.

## Domain constants — `app/Const/`

Numeric/string values that carry **business meaning** (not just infra config) live in `app/Const/<Context>Const.php`, each with a PHPDoc "why".

```php
namespace App\Const;

final class ClarifyConst
{
    /** Below this, the GTD "two-minute rule" fires the in-app timer instead of filing the item. */
    public const TWO_MINUTE_SECONDS = 120;

    /** Date format used in every date_format: rule and ->format() call. */
    public const DATE_FORMAT = 'Y-m-d';
}
```

`config/*` is for per-environment infra config (DB host, cache TTL). `app/Const/` is for domain/technical constants invariant across environments. Bind one format/regex/code to a single source so it can't silently drift across call sites.

## Date format — constants, not literals

```php
// GOOD
'dueDate' => ['nullable', 'date_format:' . ClarifyConst::DATE_FORMAT],
// BAD
'dueDate' => ['nullable', 'date_format:Y-m-d'],
```

## Validation rules — always an array, never a pipe-string

```php
// GOOD
'title'   => ['required', 'string', 'max:255'],
'dueDate' => ['nullable', 'date_format:' . ClarifyConst::DATE_FORMAT],
// BAD
'title' => 'required|string|max:255',
```

Cleaner diffs; survives args containing `|`/`:`; the only form that cleanly mixes strings, rule objects (`new FooRule`), and concatenations with a constant.

## Value Objects — `app/Domain/<context>/ValueObjects/`

Data that carries a combination (value + date, amount + currency) and is passed between layers is an immutable Value Object: `readonly` class, promoted private ctor props, no setters, implements `JsonSerializable`, `fromArray`/`toArray`, dates as `Carbon`. DTO transports entity/aggregate state between layers; a VO models a single domain concept (1–6 fields) or a business command.

## Cross-field invariants — built-ins first, custom rule second

When validating one field against a sibling field in the same payload, try Laravel built-ins before a custom rule — less code, and Scramble documents them in the OpenAPI output.

| Rule | Semantics |
| --- | --- |
| `required_if:other,value` | required when other = value |
| `required_unless:other,value` | required when other ≠ value (also for null/missing) |
| `prohibited_if:other,value` | must be empty/absent when other = value |
| `declined_if:other,value` | must be explicit `false` when other = value (implicit + requires presence) |
| `missing_if:other,value` | key must be absent when other = value |

Mutex pattern ("null when X, required when not X") — pair `required_unless` + `prohibited_if`, with `bail` to suppress duplicate messages and ordering so a format error beats a "prohibited" message:

```php
'commissionedAt' => [
    'bail', 'nullable',
    'required_unless:isDraft,true',
    'date_format:' . ClarifyConst::DATE_FORMAT,
    'prohibited_if:isDraft,true',
],
```

Drop to a custom rule only for sums/products, checks against DB state (those live in the Service, not the FormRequest), or regexes that depend on another field's value.

## Custom validation rules — `DataAwareRule`

For multi-field rules, implement `ValidationRule + DataAwareRule`; `setData()` stores the whole payload, `validate()` reads dependent fields via `data_get()`. Classes go in `app/Validators/`.

```php
class FactorRule implements ValidationRule, DataAwareRule
{
    protected array $data = [];
    public function __construct(private float $max = 100.0) {}
    public function setData(array $data): static { $this->data = $data; return $this; }
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $prefix = mb_substr($attribute, 0, (int) mb_strrpos($attribute, '.'));
        $rate   = (float) data_get($this->data, "{$prefix}.rate", 0);
        if ($rate * (float) $value > $this->max) {
            $fail("Product of rate × factor must not exceed {$this->max}.");
        }
    }
}
```

## Transactional jobs — shared trait, status discipline

Jobs that mutate data share a retry/timeout policy and `failed()` boilerplate via a trait:

```php
trait HandlesTransactionalFailure
{
    // tries=1: these jobs are non-idempotent (they create rows / side effects).
    // Retry after a partial failure would duplicate effects.
    public int $tries   = 1;
    public int $timeout = 60;

    public function failed(Throwable $e): void
    {
        logger()->error('Transactional job failed', ['job' => static::class, 'error' => $e->getMessage()]);
    }
}
```

`failed()` only logs. Status cleanup (`markFailed`) lives in the Service's `try/catch`, not in `failed()`. Idempotency guard: at the top of `handle()`, if the transaction is already terminal, NO-OP. With `tries=1` this gives "at-most-once-effect".

## Domain event logging — `LogEvent`, never raw `Log::info`

Business-meaningful events always go through a static `App\Logging\LogEvent` helper (action naming `domain.action.outcome`). Never raw `Log::info(...)` for domain events. `Log::debug(...)` for loose diagnostics is exempt. Full logging detail below.

---

# Typing & API-docs tooling (not yet installed)

These realize the `quality_override` compensation in @../context/foundation/tech-stack.md — PHP is dynamically typed; we patch that with static analysis + types + generated docs. Tests/Pest conventions live in @../tests/CLAUDE.md.

## Larastan (PHPStan) — level 6+

The load-bearing typing compensation. Annotate generics and model properties so the analyzer can reason about contracts:

```php
/** @return Collection<int, ItemDto> */
public function getAll(): Collection { /* … */ }

/** @return BelongsTo<Project, $this> */
public function project(): BelongsTo { /* … */ }

/**
 * @property string $id
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Item extends Model { /* … */ }

/** @return JsonResponse<ItemDto> */   // also drives Scramble
public function show(string $id): JsonResponse { /* … */ }
```

Run: `./vendor/bin/phpstan analyse --memory-limit=512M`.

## Scramble — auto OpenAPI from code

`dedoc/scramble` auto-generates OpenAPI 3.1 from code — request body from FormRequest `rules()`, response from DTOs implementing `Arrayable`, params from routes, errors from `auth:sanctum` + `@throws`, backed enums. Export `php artisan scramble:export`; Bearer (Sanctum) security scheme; config in `config/scramble.php` + `AppServiceProvider::boot()`. Annotations to add (the tool mechanics the agent already knows; these are the project requirements):

- **Controller**: first PHPDoc line = summary; **`@return JsonResponse<Dto>`** (or `<list<Dto>>`) **required on every method**; `@throws` documents error responses; `@unauthenticated` excludes from the global Bearer scheme; `@operationId` overrides the id.
- **FormRequest**: a comment above a field = its description; `@query` moves a field to the query string; `@var <shape>` overrides the inferred type; `@example`; `@ignoreParam` hides a field; `@requestMediaType multipart/form-data` for uploads.
- **DTO**: typed `@return array{...}` on `jsonSerialize()` (drives the response schema) + per-field `@example` / `@default` / `@format`.
- **Attributes** (alt to PHPDoc): `#[QueryParameter]`, `#[PathParameter]`, `#[Group('Tag', weight: N)]`. Group several controllers under one tag via `Scramble::configure()->afterOpenApiGenerated(...)` (set the tag per operation, then push `new Tag('Name', 'desc')` to `$openApi->tags`).
- **Backed enums**: a PHPDoc comment on each case becomes its schema description.

**Per-endpoint checklist:** controller summary PHPDoc · `@return JsonResponse<Dto>` · DTO implements `Arrayable` (+ `@implements Arrayable<string, mixed>`) with typed `@return array{...}` on `jsonSerialize()` · FormRequest has `rules()` · enums have PHPDoc on cases.

Pint is already present (Laravel preset, no `pint.json`).

---

# Structured logging (JSON / ECS)

Adopted from the twin, **adapted to single-user** — only `request_id` (and `user_id` once auth exists); no tenant/company context. Middleware lives in `app/Http/Middleware/`, helpers in `app/Logging/`.

## Format per environment

| Env | `LOG_CHANNEL` | Format | Purpose |
| --- | --- | --- | --- |
| local / dev | `stack` | text (`storage/logs/laravel.log`) | tail + grep |
| staging | `stack` | text | manual debug |
| production | `json` | ECS JSON on stdout | shipper → log store |

The `json` channel (`config/logging.php`) is a Monolog wrapper: `StreamHandler` on `php://stdout` + an ECS formatter + 3 processors — redact sensitive data, map flat context → ECS, interpolate `{placeholders}`.

## Request → log context pipeline (single-user)

```
Request → nginx → PHP-FPM
  ↓ AssignRequestId      (api global, prepend)   — request_id (UUID v4)
  ↓ auth:sanctum                                 — Bearer token → the one user
  ↓ LogContextMiddleware                         — user_id into Log::shareContext
  ↓ Controller / Service / Repository
  ↓ Response (header X-Request-Id)
```

`AssignRequestId` runs first so even auth/bootstrap errors carry a `request_id`. It honors an incoming `X-Request-Id` **only if it is a valid UUID** (guards against log forgery via injected newlines / control chars / huge strings) and echoes it back so clients can correlate.

## request_id → queued job propagation

`AppServiceProvider::boot()` registers `Queue::createPayloadUsing` to attach `requestId` (from `app('current_request_id')`) onto the queue payload; a queue middleware reads it back into `Log::shareContext` on the worker. Workers are long-lived, so **flush context on the way in** (in case a previous job was SIGKILL/OOM'd) and register a global cleanup:

```php
$flush = static function (): void {
    app()->forgetInstance('current_request_id');
    Log::flushSharedContext();
    Log::withoutContext();
};
Queue::after($flush);
Queue::failing($flush);
```

A `CommandStarting` listener generates a fresh `request_id` per artisan invocation (scheduler, migrations, `queue:work`) so a single command's logs are traceable.

## Flat context → ECS mapping

App code uses **flat** context keys; a processor maps them to ECS fields, then removes the source keys to avoid duplication:

| Flat key | ECS field |
| --- | --- |
| `user_id` | `user.id` |
| `request_id` | `http.request.id` |

## Redaction (defense-in-depth)

A processor replaces values of sensitively-named keys (case-insensitive, recursive) with `[REDACTED]`: `authorization, password, password_confirmation, secret, client_secret, api_key, apikey, token, access_token, refresh_token, bearer, cookie, set-cookie, x-api-key`. The structure stays without the value. Still: never manually log full request bodies, exception traces with credentials, or third-party responses with PII.

## LogEvent — domain event helper

Every domain event goes through `App\Logging\LogEvent` (action naming `domain.action.outcome`). Never raw `Log::info` for domain events (inconsistent action names, missing `event.outcome`, hard to filter). Add a new event = a static method on `LogEvent` with a PHPDoc `@param` per arg, building the `event.*` fields (`action`, `category`, `outcome`; optional `reason`, `kind`, `durationNs`) and calling the right level.

```php
// GOOD
LogEvent::itemClarified($itemId, GtdBucket::NextActions);
// BAD
Log::info('item clarified', ['item' => $itemId, 'bucket' => 'next_actions']);
```
