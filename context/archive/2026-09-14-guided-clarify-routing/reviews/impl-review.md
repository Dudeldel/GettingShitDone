<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Guided clarify routing (S-02)

- **Plan**: `context/changes/guided-clarify-routing/plan.md`
- **Scope**: Phases 1–3 of 3
- **Commits**: `92ae0b1`, `ba74ad3`, `1e7a196`, `c5fc3dd`
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Findings**: 4 critical, 5 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | FAIL |

All four gates pass independently: backend 94 tests, Pint clean, Larastan level 6 zero errors,
frontend 46 tests, `tsc -b` and eslint clean. Architecture is clean — 3-line controller,
HTTP-free service, DTO-returning repository, pure-PHP domain, `Response::HTTP_*` throughout,
zero inline FQNs. **Scope discipline is 8 for 8**: every exclusion in "What We're NOT Doing"
held, including the capture-path guard that clarify could have loosened for convenience.

**31 mutations were applied to the source; 12 survived fully green.** The decision tree, the
repository write, the controller status and the dialog's per-branch payloads are genuinely
covered. The holes are concentrated in the validation layer, the failure path, and one
frontend list operation — and one planned feature has no UI at all.

## Findings

### F1 — The by-id removal test cannot fail, and its comment claims it can

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/InboxPage.test.tsx:169-196`, guarding `InboxPage.tsx:91`
- **Detail**: Replacing `current.filter((item) => item.id !== clarified.id)` with
  `current.slice(1)` leaves 46/46 green. Two items are not enough: the test clarifies the
  **first** row, and its MSW handler returns a hardcoded `makeItem({ id: 1 })` regardless of
  the path parameter, so by-id and by-index remove the identical element. The comment at
  `:185` states the opposite — *"Two items, so removal by id rather than by index is what is
  actually under test."* A/B verified: echoing `params.id` in the handler and clicking index
  `[1]` makes the correct source pass 9/9 and the `slice(1)` mutant go red. The bug it would
  miss is user-visible — clarifying the second row makes the first one vanish.
- **Fix**: Echo the requested id in the handler (`({ params }) => makeItem({ id: Number(params.id) })`),
  click index `[1]`, and invert the assertions.
- **Decision**: FIXED — the MSW handler now echoes params.id and the test clarifies the SECOND row, so by-id and by-index no longer remove the same element. Proven able to fail: slice(1) in place of the filter now reddens it (it previously stayed green).

### F2 — Concurrent clarify corrupts the row and tells both callers they won

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Infrastructure/Item/ItemRepository.php:37-67`
- **Detail**: `find()` → compare `bucket` → `save()`, with no transaction, no `lockForUpdate()`,
  and no `WHERE bucket = 'inbox'` on the UPDATE. Reproduced by interleaving two workers so both
  SELECT before either UPDATE — which is what two PHP-FPM processes do:
  both guards pass, A is told `delegation/Ania` with 200, B is told `trash` with 200, and the
  row ends **corrupt**: `bucket=trash` carrying `delegated_to='Ania'`. Eloquent's `save()` only
  writes dirty attributes, so B's already-null `delegated_to` never reached its UPDATE. That
  contradicts the `ItemDto` contract at `app/Dto/ItemDto.php:13` and produces two
  `item.clarified` log lines, one of which is a lie. The guard's own comment claims it prevents
  this; it does so only serially. Same class as the archived `email-password-auth` F1, whose
  fix (`DB::transaction` + `lockForUpdate`) already lives in this repo at
  `app/Infrastructure/Auth/UserRepository.php:28-31`. The plan's justification — *"one UPDATE,
  so no transaction"* — mis-describes the operation, which is a read-check-write.
  Not catchable by this suite: SQLite in-memory serialises.
- **Fix A ⭐ Recommended**: Make guard and write one statement —
  `Item::query()->where('id', $id)->where('bucket', GtdBucket::Inbox)->update([...])`, treating
  0 affected rows as `ItemNotInInboxException`, and write every delegation column explicitly so
  a no-op assignment still participates.
  - Strength: Closes the race with zero transaction machinery and no new interface method; the
    database does the checking.
  - Tradeoff: Loses the loaded model, so the DTO needs a re-read (which is a durability
    improvement in its own right).
  - Confidence: HIGH — single-statement conditional update is the standard remedy.
  - Blind spot: MySQL isolation behaviour inferred, not measured.
- **Fix B**: `DB::transaction` + `lockForUpdate()` in the service, with a `transaction(callable)`
  helper on the repository interface.
  - Strength: Matches the auth precedent exactly and creates the seam the plan noted was absent.
  - Tradeoff: More machinery for one statement; `app/CLAUDE.md` puts the transaction in the
    Service, so the interface grows too.
  - Confidence: HIGH.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — guard and write are one conditional UPDATE with a `where bucket = inbox` predicate; 0 affected rows is disambiguated into 404 vs 409 by a single read on the failure path only, and the DTO comes from a re-read. No transaction seam needed.

### F3 — Eight of nine validation rules are unpinned; one surviving mutant turns 422 into 500

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `app/Http/Requests/ClarifyItemRequest.php:24-83`, `tests/Feature/Item/ClarifyItemTest.php`
- **Detail**: Every rule except `prohibits:` can be deleted with the suite at 94/94 — the
  `required_if_accepted`/`required_if_declined` rules, the three-value `Rule::in`, the
  `max:255`, and the `prepareForValidation` sanitizer. They survive because `ClarifyDecision`
  re-checks the same invariants and also maps to 422, and every test asserts only the status
  code, so it cannot see which layer answered. The load-bearing one is
  `Rule::enum(GtdBucket::class)` on `quickRouteBucket`: it is all that stands between an unknown
  string and `GtdBucket::from()` at `ClarifyItemPayload.php:61`. Removing it turns
  `{"quickRouteBucket":"nonsense"}` from a clean 422 into a **500 with an uncaught `ValueError`
  and a stack trace** — and 94/94 stays green. The response *shape* also changes without any
  test noticing: with the rule, `{message, errors:{delegatedTo:[…]}}`; without it,
  `{message}` and no `errors` map.
- **Fix**: Assert the payload, not just the status — `assertJsonValidationErrors(['delegatedTo'])`
  on the delegation test, plus a new `{"quickRouteBucket":"nonsense"}` → 422 test asserting
  `assertJsonValidationErrors(['quickRouteBucket'])`. Add the three boundary cases
  `CaptureItemTest` already has and `ClarifyItemTest` lacks: `max:255` rejection, control-character
  strip, whitespace trim.
- **Decision**: FIXED — six new feature tests assert assertJsonValidationErrors for delegatedTo, singleStep, nonActionableDestination and quickRouteBucket, plus the max-length and control-character boundaries. Proven able to fail: removing Rule::enum now reddens (previously a silent 500), as does removing required_if_accepted:delegable.

### F4 — Quick-route to Delegation is structurally impossible, and a unit test pins the dead end

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: `app/Dto/Payload/ClarifyItemPayload.php:34-37`, `app/Domain/Clarify/ClarifyDecision.php:73-75`, `tests/Unit/Clarify/ClarifyDecisionTest.php:134-138`
- **Detail**: `quickRoute()` passes `null` for `delegatedTo` unconditionally, and `fromArray()`
  discards any `delegatedTo` sent alongside `quickRouteBucket`. `ClarifyDecision` then routes a
  quick-route to Delegation through `delegation()`, which requires a non-empty note — so
  **every** quick-route to Delegation throws, including a well-formed one carrying the note.
  The HTTP edge does not catch it either: `delegatedTo`'s only gate is
  `required_if_accepted:delegable`, and a quick-route payload has no `delegable` field, so the
  rejection comes from the domain — the exact edge/domain disagreement
  `InvalidClarificationException.php:13-14` says should never happen. The unit test
  *"still demands a who/what note when the destination is Delegation"* passes, but not for the
  reason its name claims: the branch can never succeed, so the test locks the gap in as if it
  were the design. No feature test covers any quick-route to Delegation.
- **Fix A ⭐ Recommended**: Let `quickRoute()` carry an optional `delegatedTo` and have
  `fromArray()` pass it through, then add the `prohibited_unless` the plan specified so the edge
  rejects a Delegation quick-route without a note.
  - Strength: Makes the documented FR-002 behaviour actually reachable and removes the
    edge/domain disagreement.
  - Tradeoff: The payload's two modes overlap slightly on one field.
  - Confidence: HIGH — the domain branch already exists and is correct.
  - Blind spot: None significant.
- **Fix B**: Exclude Delegation from the quick-route and reject it at the edge.
  - Strength: Keeps the two modes perfectly disjoint; smaller change.
  - Tradeoff: Narrows FR-002 without the PRD saying so — Delegation becomes the one bucket the
    quick-route cannot reach, which needs recording as a product decision.
  - Confidence: HIGH that it works; MEDIUM that it is the right call.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — quickRoute() carries an optional delegatedTo, fromArray() passes it through, and ClarifyItemRequest gained required_if:quickRouteBucket,delegation so the edge rejects a note-less Delegation quick-route instead of the domain. Two feature tests cover the branch end to end.

### F5 — The clarify persistence-failure path has no test; a swallowed error returns 200

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Services/ItemService.php:68-74`, `app/Infrastructure/Item/ItemRepository.php:60-64`
- **Detail**: Two mutations, both green at 94/94: deleting `LogEvent::itemClarifyFailed(...)`
  from the service's catch, and making the repository's `catch (QueryException)` swallow instead
  of rethrow. The second is the dangerous one — the endpoint then returns **200 with a DTO
  showing the new bucket while the row never moved**, which is the FR-008 guardrail failing
  silently. `CaptureItemTest` has all three analogues for capture; the precedent was set in the
  previous slice and not followed here.
- **Fix**: Mirror `CaptureItemTest`'s failure test — force the write to fail, assert 500, assert
  `item.clarified.failure` carries only the SQLSTATE, and assert via a second `GET /api/items`
  that the item is still in the Inbox.
- **Decision**: FIXED — a feature test drops the items table mid-clarify and asserts 500, no captured text in the body, and the item.clarified.failure event. Proven able to fail: swallowing the QueryException now reddens it with 'log() should be called at least 1 times but called 0 times'.

### F6 — The FR-002 quick-route has no user-facing entry point

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/items/ClarifyDialog.tsx:21`, `frontend/src/api.ts:226`
- **Detail**: The Phase 3 Contract required *"the FR-002 quick-route as an explicit alternative
  entry"*; `plan-brief.md:41` lists it In scope; plan Key Discoveries calls it in scope and not
  FR-010. `Step` is typed as exactly five tree states with no quick-route, and
  `grep -rn "quickRoute" frontend/src/` returns two hits, both in the type declaration, zero in
  any component. The `{ quickRouteBucket }` union member is dead. The backend half shipped and is
  tested, so the feature is reachable by API only. Progress 3.5 *"Each branch routes to the right
  bucket, verified end to end"* is ticked and cannot have covered it.
- **Fix**: Add the quick-route entry to the wizard (a "skip the questions — file it directly"
  affordance listing the destinations), or move it to its own slice and correct the plan,
  plan-brief and Progress 3.5.
- **Decision**: FIXED — the wizard gained a 'Skip the questions' entry listing the seven destinations (Inbox excluded), with Delegation routed through the who/what note. Three component tests cover it, including that the Inbox is not offered.

### F7 — The 422 render echoes the exception message, breaking the fixed-message precedent

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `bootstrap/app.php:64-68`
- **Detail**: The sibling renders use hardcoded strings — the 404 one deliberately discards
  `'No item with id 7.'` — following `ItemPersistenceException`'s documented rule that the
  message is the contract the SPA renders and must not derive from internals. The
  `InvalidClarificationException` arm returns `$e->getMessage()` instead. No leak today: all five
  messages are hand-authored statics. But the containment is by convention, not construction —
  the exception is a bare `RuntimeException` with no constructor guard, so a future
  `throw new InvalidClarificationException("… {$payload->delegatedTo} …")` ships straight to the
  client, and `apiMessage.ts:24-26` renders a 422 body verbatim. In a slice whose whole privacy
  discipline is about keeping user text out of messages, this is the one hole.
- **Fix**: Return a fixed message, or give the exception a constructor that accepts only a
  safe-by-construction reason code.
- **Decision**: FIXED — InvalidClarificationException now has a private constructor and six named factories, each with a hardcoded message. Rendering getMessage() is safe by construction rather than by convention: the exception cannot be built with interpolated request data.

### F8 — Test gaps: the Delegation Inbox-absence half, and no test reaches the 422 renderer

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `tests/Feature/Item/ClarifyItemTest.php:51-67`
- **Detail**: The plan required, for at least the Delegation and Next Actions branches, both a
  destination GET **and** an Inbox-absence GET. Next Actions has both; Delegation — the one
  branch that writes extra columns — asserts only the destination. Separately, no feature test
  posts `quickRouteBucket: "delegation"` or `"inbox"`, so `bootstrap/app.php:64-68` is never
  exercised: the 422 test at `:122-133` is satisfied by the FormRequest instead.
- **Fix**: Add the Inbox-absence assertion to the Delegation test and one feature test that
  reaches `InvalidClarificationException` through HTTP.
- **Decision**: FIXED — the Delegation test gained the Inbox-absence half, and a quick-route-to-Inbox test reaches the InvalidClarificationException renderer over HTTP for the first time.

### F9 — The "dialog" is not a dialog: no role, no focus move, no Escape

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/ClarifyDialog.tsx:72`
- **Detail**: Probed live on `InboxPage`: `role=dialog count: 0`, `aria-modal` absent, focus
  after opening stays on the Clarify trigger, and Escape does not close it. A screen-reader or
  keyboard user gets no announcement that a wizard opened and must Tab forward blind to reach
  "Is it actionable?". Neither test file covers any of it. The previous slice's review made the
  same class of finding about live regions and it was fixed then.
- **Fix**: `role="dialog"` + `aria-modal="true"` + `aria-labelledby` on the existing `<h3>`; move
  focus on mount; bind Escape to `onCancel`; pin all three with tests.
- **Decision**: FIXED — role=dialog, aria-modal, aria-labelledby on the heading, focus moved on mount, and Escape bound to cancel. Two tests pin the focus move and the Escape close.

### F10 — Bookkeeping: S-04 not closed as absorbed, S-02 status stale, dead test scaffolding

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `context/foundation/roadmap.md:187`, `tests/Pest.php:63-65,92-93`
- **Detail**: The plan is emphatic that *"S-04 must be closed in the roadmap as absorbed, not left
  dangling"* — it is still `proposed`, while the multi-step→Projects branch it describes shipped
  here and is tested. S-02 itself reads `in-progress` in the roadmap while `change.md` says
  `implemented`. Separately, the fake repository gained `clarifiedItemId`/`clarifiedTo` recorders
  that nothing reads, and `ItemServiceTest` has no clarify test — so the service's orchestration
  is reached only through Feature tests.
- **Fix**: Close S-04 as absorbed and flip S-02 at archive time; either add the `ItemService::clarify`
  unit test the recorders were built for, or drop them.
- **Decision**: FIXED — S-04 marked 'absorbed by S-02' in the roadmap with the reasoning recorded; the fake repository's clarify recorders now have the two ItemService unit tests they were built for. S-02 flips to done at archive time.

## Verified sound (probed and confirmed)

- **Scope discipline 8/8** — every "What We're NOT Doing" exclusion held, verified by grep and
  diff, including that clarify did not loosen the capture bucket guard.
- **Auth and throttle** — the clarify route is inside `auth:sanctum` + `throttle:api`; the 401
  test is live (dropping the middleware reddens it), and the `Authorization` header is pinned by
  `frontend/src/api.test.ts:81` on the shared `request()` helper, closing the lessons.md
  regression.
- **Migration** — `down()` verified reversible on SQLite 3.45.1 through up → down → up with data.
- **Error mapping** — 404/409/422 are distinct and the 404-vs-409 split is pinned.
- **Pattern compliance** — zero substantive mismatches against `ItemController`,
  `CaptureItemRequest` and `CaptureItemTest`.
- **Genuinely covered by mutation**: the decision tree's four tree rows, the repository's bucket
  write, the controller's status code, and each of the dialog's per-branch payloads.
