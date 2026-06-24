# Contract surfaces

Load-bearing names other code/plans depend on. When you rename or change the shape of
one of these, treat it as a breaking change and update the consumers. `/10x-plan-review`
greps plans against the H2 headings below.

## json log channel

`config/logging.php` — production structured-logging channel (`LOG_CHANNEL=json`):
`StreamHandler` → `php://stdout` + `App\Logging\EcsFormatter` + processors
(`RedactSensitiveData`, `MapContextToEcs`, `PsrLogMessageProcessor`). Emits single-line
ECS JSON for the log shipper. Local/staging stay on the text `stack` channel.

## EcsFormatter

`app/Logging/EcsFormatter.php` — Monolog formatter emitting ECS-shaped single-line JSON
(`@timestamp`, `log.level`, `message`, `ecs.version`, mapped fields at top level, other
context under `labels`). Used by the `json` channel.

## RedactSensitiveData

`app/Logging/Processors/RedactSensitiveData.php` — Monolog processor; replaces values of
sensitively-named keys with `[REDACTED]` (recursive, case-insensitive) across context/extra.

## MapContextToEcs

`app/Logging/Processors/MapContextToEcs.php` — Monolog processor; maps flat context keys
to ECS field names (`request_id` → `http.request.id`, `user_id` → `user.id`) and drops the
source keys. Extend the `MAP` constant as new flat keys appear (e.g. when auth sets `user_id`).

## AssignRequestId

`app/Http/Middleware/AssignRequestId.php` — first middleware in the `api` group. Resolves a
request id (validated incoming `X-Request-Id` UUID, else a generated v4), binds it to the
container (`AssignRequestId::CONTAINER_KEY` = `current_request_id`) and `Log::shareContext`,
and echoes `AssignRequestId::HEADER` (`X-Request-Id`) on the response.

## current_request_id

Container binding holding the current request's id (set by `AssignRequestId`). Listed in
`config('octane.flush')` so it is forgotten between Octane requests. Read it via
`app('current_request_id')` for queue/job propagation once jobs land.

## LogEvent

`app/Logging/LogEvent.php` — single entry point for domain events (`LogEvent::emit(action,
category, outcome, context, level)`), building the ECS `event.*` envelope. Never use raw
`Log::info` for domain events. Slices add specific named methods (e.g. `itemClarified()`)
that delegate here.

## composer quality

`composer.json` script — local mirror of the CI gates: `pint --test` → `phpstan analyse`
(level 6) → `artisan test` (Pest), fail-fast. Run before pushing.

## CI gates (.github/workflows/ci.yml)

Backend job: Pint (format) → Larastan level 6 → Pest (`--parallel`, SQLite `:memory:`).
Frontend job: `npm ci` → lint → build. All must pass before merge.
