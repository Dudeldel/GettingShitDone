# Contract Parity — Implementation Plan

## Overview

Close the outstanding half of rollout Phase 2 (`context/foundation/test-plan.md` §3): make it
impossible for the published HTTP contract and the rules the application actually enforces to
drift apart without a test going red. That is Risk #4's exact failure mode, and it is not
hypothetical — the document currently offers a destination the endpoint refuses.

## Current State Analysis

Scramble generates `api.json` from the FormRequests, so the two are *supposed* to agree. Verified
by exporting the document and reading every key of every request schema:

| Where | Rule enforced | Published schema | Verdict |
| --- | --- | --- | --- |
| `RefileItemRequest` `bucket` | `Rule::enum(GtdBucket)` **and** `Rule::in(<7 destinations>)` — Inbox excluded | `$ref` → `GtdBucket`, which lists **all 8** | **Real schema drift.** The document tells a consumer `inbox` is a legal destination; the endpoint answers 422 |
| `ClarifyItemRequest` (whole body) | `required_if_accepted:` / `required_if_declined:` chains | `required` absent entirely | **Unrepresentable.** OpenAPI's `required` array cannot express a conditional requirement, so the document implies an empty body is acceptable |
| `ClarifyItemRequest` `quickRouteBucket` | edge accepts all 8; `ClarifyDecision::quickRoute()` refuses Inbox | `$ref` → `GtdBucket`, all 8 | **Not schema drift.** The request genuinely *is* schema-valid; it fails a business rule, which OpenAPI does not model. A description gap, not a schema one |

The `bucket` case has one cause worth naming: `Rule::enum(GtdBucket::class)` is **redundant** next to a
seven-value `Rule::in` — the `in` already rejects everything outside its list — but it is what makes
Scramble emit a `$ref` to the full eight-value enum instead of the narrowed set.

Nothing would catch any of this: there is no parity test, `.github/workflows/ci.yml` has no Scramble
step at all, and `api.json` is git-ignored — so the document is never generated outside a
developer's own machine.

**Out of scope because already covered** (per the §6.6 audit, re-confirmed): boundary limits are
derived from their owning constants rather than hard-coded, in
`tests/Feature/Item/{CaptureItemTest,ClarifyItemTest,UpdateItemAttributesTest}.php` — which is the
lived finding §2 warns about. Risk #7 (captured text reaching logs) has
`tests/Unit/Logging/RedactSensitiveDataTest.php` plus failure-path log assertions across the
Feature suite.

## Desired End State

A test suite that fails when the published contract and the enforced rules disagree, in either
direction, and a published document that currently tells the truth.

Verify by: re-adding the redundant `Rule::enum` to `RefileItemRequest`, and watching the parity
test go red on the widened published set rather than the drift shipping silently.

### Key Discoveries

- **A first pass at this analysis was wrong, and the way it was wrong is instructive.** An
  inspection script filtered schema keys to a hand-written list (`type`, `maxLength`, `enum`, …).
  `$ref` and `maxItems` were not on it, so three fields that publish a perfectly good `$ref` to an
  enum schema rendered as empty `{}`, and `tags`' `maxItems: 20` vanished. Three of four "drifts"
  did not exist. Any tooling this change writes must read the schema **whole**, never through a
  key allowlist — that is precisely the failure mode being automated away.
- The document is generated *from* the rules, so a naive "compare the doc to the rules" looks
  circular. It is not, because the link under test is **Scramble's translation** — and the one real
  failure lives exactly there: a redundant rule changes what gets published without changing what
  gets enforced.
- **A `$ref` is where over-promising hides.** A narrowed `Rule::in` and a full-enum `$ref` both look
  like "this field has an enum"; only resolving the reference and comparing the value *sets* shows
  the document offering a value the endpoint refuses.
- `required_if_accepted` / `required_if_declined` are used rather than plain `required_if` because
  JSON booleans need the boolean-aware variants. OpenAPI cannot express a conditional requirement
  at all — so this is a description to write, not a schema bug to fix.
- **`tests/Feature/Item/ClarifyItemTest.php:218` carries a second purpose in its comment**: it is
  the only test proving the `InvalidClarificationException` renderer is reachable over HTTP.
  Narrowing `quickRouteBucket` at the edge would make it pass at the edge instead — still green,
  for a different reason. That is why the `quickRouteBucket` gap is closed with prose rather than
  by tightening the rule.
- The suite already asserts validation failures by **field key**
  (`assertJsonValidationErrors([...])`), never by message text. The parity test inherits that.

## What We're NOT Doing

- **No re-testing of boundary limits or log redaction** — covered, and §6.6 says so.
- **No committed `api.json` snapshot.** It stays git-ignored; the test generates what it needs.
- **No new CI step.** The parity test runs inside `php artisan test` like everything else.
- **No weakening of a rule to make the document agree.** When the two disagree, the document is
  what gets corrected — the rule is the enforced behaviour and is not up for negotiation here.
- **No behavioural checks on clarify / refile / complete.** Their multi-step state setup is the
  bulk of the work for the least marginal signal; the structural layer covers them.
- **No general rule-to-OpenAPI translator.** The parity check understands an explicit, small set
  of rule tokens and *fails loudly* on any token outside it (see below) rather than guessing.
- **No E2E or browser-level work** — that is rollout Phase 4, deliberately deferred.

## Implementation Approach

Two layers that catch different halves of the risk, plus the document fixes they force.

**Structural** — for every documented request schema, compare each rule token in the FormRequest's
`rules()` against its counterpart in the published schema. Catches a rule that did not survive
translation — which is the shape of the one real drift here.

**Behavioural** — read the published constraints and fire real requests at them: a value at the
documented limit must NOT produce a validation error for that field, and a value one step past it
must. Catches the opposite shape — a constraint the document advertises that nothing actually
enforces. Limited to endpoints whose valid state is cheap to build.

Neither layer alone is sufficient. The structural one cannot prove a documented limit is real. The
behavioural one cannot see a constraint the document never mentions — and, on the one real drift
here, would need to guess that `inbox` is worth trying at all. The structural layer finds it by
comparing sets; the behavioural layer is what proves the narrowed set is genuinely enforced.

## Critical Implementation Details

**The unrepresentable-rule allowlist is the load-bearing part.** A parity check that silently
ignores rule tokens it does not understand degrades into a green test that checks almost nothing —
and it degrades *quietly*, as new rules are added. The check must therefore fail on an unknown
token and require it to be listed explicitly, with a reason, in an allowlist of rules OpenAPI
cannot express (`required_if_accepted`, `prohibited_if`, `present`, `confirmed`, …). Adding an
exotic rule then forces a one-line decision instead of a silent coverage hole.

## Phase 1: Contract parity, and the document fixes it forces

### Overview

Both parity layers plus the edits needed to make the published document true. The structural layer
lands first: it is what identifies the drift, and the behavioural layer reads the corrected document
to build its cases.

### Changes Required:

#### 1. Structural parity check

**File**: `tests/Feature/Api/ContractParityTest.php`

**Intent**: Prove every constraint the FormRequests enforce survives into the published schema, so
a rule Scramble cannot translate fails a test instead of shipping as a silent under-promise.

**Contract**: A Feature test (it boots the app to generate the document). It enumerates the
FormRequest classes under `app/Http/Requests/`, reads `rules()` from each, and for every field
compares the rule tokens against the matching `components.schemas.<RequestName>` entry. The
mapping the check understands, and which must hold in both directions:

| Rule token | Published counterpart |
| --- | --- |
| `required` | field appears in the schema's `required` array |
| `max:N` on a string | `maxLength: N` |
| `max:N` on an array | `maxItems: N` |
| `max:N` on `field.*` | `items.maxLength: N` |
| `min:N` on a string | `minLength: N` |
| `Rule::in([...])` / `Rule::enum(...)` | `enum` listing **exactly** those values — `$ref` resolved first, sets compared both ways |
| `date_format:<fmt>` | `format: date` |
| `email` | `format: email` |
| `boolean` / `array` / `string` / `integer` | corresponding `type` (nullable widening allowed) |

Any token not in this table and not in the explicit unrepresentable allowlist fails the test by
name. The limits are read from `rules()` at runtime, never restated in the test — restating them
is the lived anti-pattern §2 names.

#### 2. Stop publishing a destination the endpoint refuses

**File**: `app/Http/Requests/RefileItemRequest.php`

**Intent**: The published schema offers all eight buckets while the rule allows seven; a consumer
reading the document would send `inbox` and get a 422. Make the published set the enforced set.

**Contract**: `bucket` publishes an enum of exactly the seven destinations, still **derived** from
`GtdBucket::isDestination()` — a hand-listed enum would be a second source of truth and is what the
parity check exists to forbid. Verified during planning: dropping the redundant
`Rule::enum(GtdBucket::class)` (a seven-value `Rule::in` already rejects everything outside its list)
makes Scramble emit the narrowed inline enum, and all ten `RefileItemTest` cases still pass.

#### 3. Say that the Inbox is refused as a quick-route

**File**: `app/Http/Requests/ClarifyItemRequest.php`

**Intent**: Unlike `bucket` this is not schema drift — the edge genuinely accepts all eight and
`ClarifyDecision::quickRoute()` refuses the Inbox as a business rule, which OpenAPI does not model.
The document still leaves a consumer to discover it by 422.

**Contract**: `quickRouteBucket`'s description states that the Inbox is refused, and why. The rule is
deliberately **not** narrowed: `tests/Feature/Item/ClarifyItemTest.php:218` is the only test proving
the `InvalidClarificationException` renderer is reachable over HTTP, and tightening the rule would
make it pass at the edge instead — green for a different reason, with that coverage silently gone.

#### 4. Describe the conditional requirements

**File**: `app/Http/Requests/ClarifyItemRequest.php`

**Intent**: OpenAPI's `required` array cannot express "required only when `actionable` is true", so
the document omits `required` entirely and implies an empty body is acceptable when it is not.

**Contract**: Each conditionally-required field's description names its condition. The
`required_if_accepted` / `required_if_declined` / `prohibited_if_declined` / `prohibits` / `present`
tokens go into the unrepresentable allowlist with this entry as their reason.

#### 5. Behavioural parity check

**File**: `tests/Feature/Api/ContractEnforcementTest.php`

**Intent**: Prove the documented boundary is the enforced one at runtime, on both sides, so a
constraint the document advertises cannot be one nothing actually applies.

**Contract**: For each endpoint in a declared set — capture, register, login, and item attributes —
a minimal valid base payload is declared once. For every documented `maxLength` / `minLength` /
`enum` constraint on that endpoint, the test derives two payloads from the **published** value: one
at the limit and one a step beyond. It asserts `assertJsonMissingValidationErrors([field])` for the
at-limit case and `assertJsonValidationErrors([field])` for the over-limit case. Status codes and
message text are never asserted — the field key is the contract. The item-attributes base payload
uses the existing `seedItem()` and `itemAttributes()` helpers from `tests/Pest.php`.

### Success Criteria:

#### Automated Verification:

- Pest suite passes: `php composer.phar test`
- Larastan clean at level 6: `./vendor/bin/phpstan analyse --memory-limit=512M`
- Pint clean: `./vendor/bin/pint --test`
- The structural check covers every FormRequest under `app/Http/Requests/` — a new FormRequest with
  no schema entry fails the test rather than being skipped
- The structural check fails by name on a rule token that is neither mapped nor allowlisted
- The published enum for `RefileItemRequest.bucket` is exactly the seven destinations, still derived
  from `GtdBucket::isDestination()`
- The quick-route Inbox refusal and the clarify conditional requirements are described in the
  document; their rule tokens are allowlisted with reasons
- The behavioural check exercises both sides of every documented length and enum constraint on the
  four in-scope endpoints
- **Deliberate breakage pass** (per `context/foundation/lessons.md`): raising
  re-adding the redundant `Rule::enum` to `RefileItemRequest` must redden the structural check on the
  widened set; raising `ItemConst::TAGS_MAX_COUNT` without regenerating must redden it on `maxItems`;
  and loosening a `max:` rule while the document keeps the old value must redden the behavioural
  check. Confirm each goes red, then revert.

  > Note added during implementation: two of those three were not executable as written, because
  > the document is generated **in process** from the rules — so a `max:` and its published
  > counterpart always move together and cannot be desynchronised by editing either. The pass run
  > instead used four breakages that map to failure modes that can really occur: restoring the
  > redundant `Rule::enum` (enum parity reddens), removing a token from the allowlist (the
  > unknown-token check reddens), adding a FormRequest with no published schema (the coverage
  > check reddens), and making `prepareForValidation` silently truncate `context` instead of
  > letting it be rejected (the behavioural check reddens — and this is the one the structural
  > layer structurally cannot see, since rule and document still agree).

#### Manual Verification:

- `php artisan scramble:export` output is readable by a consumer: `bucket` no longer offers the
  Inbox, and the clarify body no longer implies an empty payload is valid
- The parity tests add no noticeable time to `php artisan test`

**Implementation Note**: After completing this phase and all automated verification passes, pause
for manual confirmation before proceeding.

---

## Phase 2: Close the rollout phase

### Overview

Reconcile the rollout tracker with what shipped, so the next `/10x-test-plan` invocation resumes at
the right place and a future reader knows what this phase did and did not buy.

### Changes Required:

#### 1. Rollout status

**File**: `context/foundation/test-plan.md`

**Intent**: Mark Phase 2 complete and record what landed, including the parts of Risk #4 that
remain structurally unprovable.

**Contract**: §3's Phase 2 row goes to `complete` with its change folder. §6.6's "Phase 2" note is
rewritten from an audit-of-what-remains into a record-of-what-shipped: the two parity layers, the
drift closed and the two description gaps, the unrepresentable-rule allowlist and why it exists,
the correction to the audit's own reading of the document, and the honest limit —
the behavioural layer does not cover clarify / refile / complete, so for those endpoints the
document is proven *complete* but not proven *enforced*.

#### 2. Cookbook entry

**File**: `context/foundation/test-plan.md`

**Intent**: §6 is the cookbook `/10x-tdd` reads when someone adds a test; a new endpoint should
inherit the parity obligation automatically rather than by memory.

**Contract**: A §6 entry naming the pattern: a new FormRequest is covered by the structural check
with no action, but any rule token outside the mapping table must be added to the allowlist with a
reason, and any new endpoint with cheap state should join the behavioural set.

### Success Criteria:

#### Automated Verification:

- Pest suite passes: `php composer.phar test`
- `context/foundation/test-plan.md` §3 shows Phase 2 as `complete` with the change folder
- The §6.6 note describes what shipped, and names clarify / refile / complete as structurally-only
  covered

#### Manual Verification:

- Reading §6.6 alone is enough to know what the next person must do when adding an endpoint

---

## Testing Strategy

### Unit Tests

None. Both layers need the booted application — one to generate the document, one to issue
requests — so both are Feature tests per `tests/CLAUDE.md`.

### Integration Tests

- Structural: rules-to-schema parity across every FormRequest, with an explicit failure for
  unmapped rule tokens.
- Behavioural: at-limit and over-limit requests for every documented length and enum constraint on
  the four in-scope endpoints, asserted by field key.

### Manual Testing Steps

1. Run `php artisan scramble:export` and read the three previously-empty fields
2. Re-add the redundant `Rule::enum` to `RefileItemRequest`, re-run the suite, confirm red, revert
3. Confirm the suite's wall-clock time is materially unchanged

## Performance Considerations

The structural layer generates the OpenAPI document once. If generation turns out to cost more than
a fraction of a second, generate it a single time for the whole test file rather than per case —
measure before optimising.

## Migration Notes

None. No schema change, no data change. The only production edits are annotations that change what
the generated document says, never what the application enforces.

## References

- Rollout phase: `context/foundation/test-plan.md` §3 Phase 2, §2 Risk #4, §6.6
- Grounding: the §6.6 audit of 2026-09-14 stands in for `research.md` for this change
- Field-key assertion convention: `tests/Feature/Item/UpdateItemAttributesTest.php`
- Shared test helpers: `tests/Pest.php` (`seedItem`, `itemAttributes`)
- Test discipline: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Contract parity, and the document fixes it forces

#### Automated

- [x] 1.1 Pest suite passes — 0c294ac
- [x] 1.2 Larastan clean at level 6 — 0c294ac
- [x] 1.3 Pint clean — 0c294ac
- [x] 1.4 Structural check covers every FormRequest; a new one cannot be silently skipped — 0c294ac
- [x] 1.5 Structural check fails by name on an unmapped, unallowlisted rule token — 0c294ac
- [x] 1.6 Published enum for refile's bucket is the seven destinations; the two description gaps closed — 0c294ac
- [x] 1.7 Behavioural check exercises both sides of every documented length and enum constraint in scope — 0c294ac
- [x] 1.8 Deliberate breakage pass: constant change, enum removal and rule loosening each redden — 0c294ac

#### Manual

- [x] 1.9 Exported document is readable and states the previously-empty fields — 0c294ac
- [x] 1.10 Parity tests add no noticeable time to the suite — 0c294ac

### Phase 2: Close the rollout phase

#### Automated

- [x] 2.1 Pest suite passes — 880c8b1
- [x] 2.2 test-plan.md §3 shows Phase 2 complete with its change folder — 880c8b1
- [x] 2.3 §6.6 records what shipped and names the structurally-only covered endpoints — 880c8b1

#### Manual

- [x] 2.4 §6.6 alone is enough to know what to do when adding an endpoint — 880c8b1
