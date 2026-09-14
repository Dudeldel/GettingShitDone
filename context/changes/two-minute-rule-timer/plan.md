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
- [x] `php artisan test` green
- [x] `./vendor/bin/phpstan analyse --memory-limit=512M` 0 errors
- [x] `./vendor/bin/pint --test` clean

**Manual**
- [x] A completed item reads back from `GET /api/items?bucket=next_actions` with `completedAt`.

### Phase 2: The frontend — the question and the countdown

**Changes required**
- `frontend/src/api.ts` — `TWO_MINUTE_SECONDS`, `TwoMinuteOutcome`, the widened
  `ClarifyAnswers` union, `completedAt` on `Item`.
- `frontend/src/items/ClarifyDialog.tsx` — the `< 2 min?` step, the countdown step, the loop.
- `frontend/src/items/InboxList.tsx` — render a done marker for a completed item.

**Automated verification**
- [x] `npm run test` green (timer tests on fake timers)
- [x] `npm run build` (includes `tsc -b`) clean
- [x] `npm run lint` clean

**Manual**
- [x] Drive the timer in a real browser: countdown ticks, "Done" files the item, marker shows.

### Phase 3: Close out

**Automated verification**
- [x] Full gates green both halves
- [x] Deliberate-breakage pass on the new tests (`lessons.md` rule)

## Progress

### Phase 1: The backend — the branch, the state, the record
#### Automated
- [x] 1.1 Domain: const, enum, outcome, decision, exception factories — 027e882
- [x] 1.2 Edge + persistence: payload, request rules, migration, model, DTO, repository — 027e882
- [x] 1.3 Record: LogEvent + service wiring — 027e882
- [x] 1.4 Tests: unit branch matrix + feature clarify cases — 027e882
#### Manual
- [x] 1.5 Read a completed item back through the API — completedAt set on the completed item, still null on one filed the other way

### Phase 2: The frontend — the question and the countdown
#### Automated
- [x] 2.1 api.ts types — 350981a
- [x] 2.2 ClarifyDialog: question + countdown + loop — 350981a
- [x] 2.3 InboxList done marker — 350981a
- [x] 2.4 Tests on fake timers — 350981a
#### Manual
- [x] 2.5 Drive the timer in a real browser — clock ticked 1:57 → reset on "I need more time" → Done → "✓ Done" in Next Actions, absent on the item filed without a timer

### Phase 3: Close out
#### Automated
- [x] 3.1 Full gates + deliberate breakage — 0d6f624

## Epilogue

**Deliberate breakage: 24 mutations, 24 killed** (2 only after a fix).

Backend 12/12. Two survived the first pass and were real:

- Deleting the `missingTwoMinuteAnswer()` guard, and deleting the
  `missingTwoMinuteOutcome()` guard, both left the suite green. Every gap in the tree
  raises the same exception type, so with one guard gone the *delegable* guard further down
  fired instead — same class, test still passes, question no longer asked. The feature tests
  could not catch it either: the FormRequest still returns 422, so the domain hole is
  invisible from the edge, which is exactly the independence the domain guard exists for.
  Fixed by pinning both refusals to their message (0d6f624), after which both mutations die.

Frontend 12/12 first pass, including: the clock set to 60 seconds, a countdown that never
ticks, one that runs past zero, "more time" that does not restart the clock, a loop that is
never counted, a deferral that reports as "no timer ran", a Back button that keeps a stale
deferral, and a done marker rendered unconditionally.

**Also fixed in passing:** backing out of the quick-route's delegation note landed the user
on "can someone else do it?" — a tree question they never entered — and answering it filed
the item somewhere they never chose. Shipped in S-02; found while extending `previousStep`.
