# Plan: 2-minute rule timer in clarify (S-03)

**Change:** `two-minute-rule-timer` · **PRD:** FR-006, FR-003, FR-008, US-01 · **Depends on:** S-02

## What we are building

The `< 2 min?` question — the one branch S-02 deliberately left out of the tree — plus the
timer it triggers, the two outcomes it can end in, and the item state that records them.

The canonical order becomes complete: `actionable? → single step? → < 2 min? → delegable?`

```
actionable, single-step, NOT < 2 min                    → delegable?  (S-02's path, unchanged)
actionable, single-step, < 2 min, timer → "done"        → Next Actions, completed_at set
actionable, single-step, < 2 min, timer → "more time"   → the timer loops
actionable, single-step, < 2 min, deferred              → delegable?  (rejoins S-02's path)
```

## Where a completed item goes

See `change.md`. Done is a **state** (`completed_at`), not a ninth bucket. The item lands in
Next Actions — what it already was — and stays visible there, marked done.

## Decisions worth stating

1. **The loop count is logged, not stored.** FR-006 says "records the outcome (done / loop)".
   `completed_at` records *done* durably. Nothing in the MVP reads a loop count back — no
   view, no FR — so a column for it would be state we must keep correct forever with no
   reader. `LogEvent::twoMinuteRuleApplied` carries outcome + loops, which is where an
   observability fact belongs. S-09 (weekly review) can promote it to a column if it ever
   needs to ask "how often do I underestimate two minutes?".

2. **The timer is client-side.** It is UI pacing, not a domain fact. A server-held timer
   would need a session, a heartbeat and a recovery story for a closed tab — all to enforce
   a discipline the user can walk away from anyway. The server sees only the outcome.

3. **`delegable` is prohibited when the outcome is "done".** You cannot delegate a thing you
   have already finished; accepting the field would make two contradictory answers valid in
   one payload.

4. **120 seconds is `ClarifyConst::TWO_MINUTE_SECONDS`** (the class `app/CLAUDE.md` names),
   mirrored once into the SPA — not a literal at either end.

## What we are NOT doing

- No Done bucket, no ninth `GtdBucket` case.
- No server-side timer, no session state, no heartbeat.
- No "un-complete" / re-open verb — that is re-filing, FR-010, v2.
- No completed-item filter or archive view on Next Actions.
- No timer on the quick-route: it skips the questions by definition.

## Phases

### Phase 1: The backend — the branch, the state, the record

**Changes required**
- `app/Const/ClarifyConst.php` (new) — `TWO_MINUTE_SECONDS = 120`.
- `app/Domain/Clarify/TwoMinuteOutcome.php` (new) — backed enum `done` | `deferred`.
- `app/Domain/Clarify/ClarifyOutcome.php` — carry `completed`; add `completedInTwoMinutes()`.
- `app/Domain/Clarify/ClarifyDecision.php` — insert the branch between single-step and delegable.
- `app/Exceptions/InvalidClarificationException.php` — two named factories.
- `app/Dto/Payload/ClarifyItemPayload.php` — `twoMinutes`, `twoMinuteOutcome`, `twoMinuteLoops`.
- `app/Http/Requests/ClarifyItemRequest.php` — the cross-field rules for the new answers.
- `database/migrations/*_add_completed_at_to_items_table.php` (new).
- `app/Models/Item.php`, `app/Dto/ItemDto.php` — `completed_at` / `completedAt`.
- `app/Infrastructure/Item/ItemRepository.php` — write `completed_at` in the guarded update.
- `app/Logging/LogEvent.php` — `twoMinuteRuleApplied`.
- `app/Services/ItemService.php` — emit it when the payload took the timer branch.

**Automated verification**
- [ ] `php artisan test` green
- [ ] `./vendor/bin/phpstan analyse --memory-limit=512M` 0 errors
- [ ] `./vendor/bin/pint --test` clean

**Manual**
- [ ] A completed item reads back from `GET /api/items?bucket=next_actions` with `completedAt`.

### Phase 2: The frontend — the question and the countdown

**Changes required**
- `frontend/src/api.ts` — `TWO_MINUTE_SECONDS`, `TwoMinuteOutcome`, the widened
  `ClarifyAnswers` union, `completedAt` on `Item`.
- `frontend/src/items/ClarifyDialog.tsx` — the `< 2 min?` step, the countdown step, the loop.
- `frontend/src/items/InboxList.tsx` — render a done marker for a completed item.

**Automated verification**
- [ ] `npm run test` green (timer tests on fake timers)
- [ ] `npm run build` (includes `tsc -b`) clean
- [ ] `npm run lint` clean

**Manual**
- [ ] Drive the timer in a real browser: countdown ticks, "Done" files the item, Escape backs out.

### Phase 3: Close out

**Automated verification**
- [ ] Full gates green both halves
- [ ] Deliberate-breakage pass on the new tests (`lessons.md` rule)

## Progress

### Phase 1: The backend — the branch, the state, the record
#### Automated
- [ ] 1.1 Domain: const, enum, outcome, decision, exception factories
- [ ] 1.2 Edge + persistence: payload, request rules, migration, model, DTO, repository
- [ ] 1.3 Record: LogEvent + service wiring
- [ ] 1.4 Tests: unit branch matrix + feature clarify cases
#### Manual
- [ ] 1.5 Read a completed item back through the API

### Phase 2: The frontend — the question and the countdown
#### Automated
- [ ] 2.1 api.ts types
- [ ] 2.2 ClarifyDialog: question + countdown + loop
- [ ] 2.3 InboxList done marker
- [ ] 2.4 Tests on fake timers
#### Manual
- [ ] 2.5 Drive the timer in a real browser

### Phase 3: Close out
#### Automated
- [ ] 3.1 Full gates + deliberate breakage
