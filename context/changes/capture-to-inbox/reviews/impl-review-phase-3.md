<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Capture an Idea into the Inbox (S-01) — Phase 3

- **Plan**: `context/changes/capture-to-inbox/plan.md`
- **Scope**: Phase 3 of 4 — "Capture UI and Inbox list"
- **Commit**: `c524a9d`
- **Date**: 2026-09-07
- **Verdict**: REJECTED
- **Findings**: 2 critical, 7 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

Both automated criteria re-run and pass (`npm run build` = tsc -b + vite, `npm run lint`).
Every planned change is present and matches intent; the `Item` interface mirrors `ItemDto`
field-for-field across all eleven fields; no scope creep. **All of the findings below are
invisible to the current toolchain — the SPA has no test runner at all.**

Note on Success Criteria: the human confirmed 3.5 ("API failure shows an error and
preserves the typed text") by stopping the API — the transport-failure path, which does
work. F2 below is a *sibling* failure path that was not exercised and behaves the opposite
way. The criterion passed honestly; its coverage was narrower than its wording.

## Findings

### F1 — A capture made during the initial list load is erased from the UI

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/App.tsx:15-16`, `:48`
- **Detail**: The mount effect does `listItems().then(setItems)` — a whole-array *replace* —
  while `onCaptured` does a functional prepend. The GET cannot contain an item created after
  it was issued, so whichever settles last wins. Sequence: app mounts and the GET starts;
  `autoFocus` has already placed the cursor in the field, so the user types and submits; the
  POST returns and the item is prepended; then the GET resolves with its pre-capture snapshot
  and overwrites it. The final screen shows "Saved to your Inbox." directly above "Your Inbox
  is empty." The row exists in the database, but the UI asserts it does not — so the rational
  user retypes and creates a duplicate. `<StrictMode>` double-invokes the effect in dev,
  widening the window. This is the "capture never loses an entry" guardrail failing in the
  most misleading way available.
- **Fix A ⭐ Recommended**: Guard the effect with an `ignore` flag and merge by id instead of
  replacing.
  - Strength: The canonical React fix; also closes F-adjacent lifecycle issues (a load
    resolving after logout). Merging keeps a completed capture visible whatever the ordering.
  - Tradeoff: A few more lines, and a merge helper to keep honest.
  - Confidence: HIGH — standard pattern, no product decision needed.
  - Blind spot: None significant.
- **Fix B**: Do not render `CaptureForm` until the initial load settles.
  - Strength: Removes the interleaving entirely; smallest reasoning surface.
  - Tradeoff: Delays the one interaction the product exists for behind a network round trip
    — directly at odds with the ~2s capture NFR and the "idea strikes, dump it in seconds"
    premise.
  - Confidence: HIGH that it works; LOW that it is the right product call.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — the load effect now carries an `ignore` cleanup and merges by id (`mergeById`) instead of replacing; local entries the server has not seen lead the list. The list also stays visible while a load is pending once a capture has landed.

### F2 — A 401 unmounts the form and destroys the typed text, while the comment promises the opposite

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.tsx:22-32`, `frontend/src/api.ts:47-49`, `frontend/src/auth/AuthContext.tsx:12`, `frontend/src/auth/ProtectedRoute.tsx:7`
- **Detail**: Verified chain. On a 401, `request()` calls `clearToken()` (so retry is
  impossible), then `onUnauthorized?.()` → `setUser(null)`, then throws. The catch sets the
  error, but React batches both updates: `isAuthenticated` goes false, `ProtectedRoute`
  renders `<Navigate to="/login" replace />`, and `CaptureForm` unmounts before the error is
  painted. `title` lived only in `useState`, so the captured idea is gone with no message.
  React 18+ removed the unmounted-setState warning, so there is not even a console signal.
  The comment at `CaptureForm.tsx:23-24` — "A failed capture must never cost the user the
  text they typed" — holds only for same-mount failures, and is false for the one failure
  mode that unmounts. The same in-memory-only draft is also lost on an explicit logout mid
  typing, a tab crash, a refresh, or a mobile tab discard.
- **Fix ⭐**: Mirror the field to `sessionStorage` on change, clear it only after a confirmed
  201, rehydrate on mount; and special-case `ApiError` 401 with a session-expired message.
  - Strength: One mechanism covers the 401 unmount and every other way the tab can go away,
    which is what a guardrail this central deserves.
  - Tradeoff: Introduces client-side persistence the plan explicitly excluded ("no offline
    capture, draft queue, or client-side retry") — this is a scope decision, not just a fix.
  - Confidence: HIGH on the diagnosis; MEDIUM on whether the scope expansion belongs in S-01
    rather than a follow-up change.
  - Blind spot: Not verified against Safari private mode, where storage writes can throw.
- **Decision**: FIXED via Fix A — the field is mirrored to sessionStorage on every change and cleared only after a confirmed 201, so the draft survives the 401 unmount, an explicit logout, a refresh and a tab crash. 401 now renders a session-expired message. Storage access is wrapped in try/catch for private mode. This deliberately widens the plan's 'no draft queue' exclusion; recorded here as the scope decision it is.

### F3 — `catch {}` discards the error and every message the backend wrote for the user

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/items/CaptureForm.tsx:28-29`
- **Detail**: The catch does not bind the error at all. `api.ts:52-62` goes to the trouble of
  parsing the backend's `message` field into `ApiError`, and every one of those messages is
  thrown away. A 422 for an over-long title, a 500 from `ItemPersistenceException` (whose
  message was written deliberately for the user in `bootstrap/app.php`), a 429 from the new
  `throttle:api` limiter, and a dropped connection all render the identical string "Could not
  save that … try again" — advice that is actively wrong for the 422 (retrying the same
  over-long title never succeeds) and for the 429 (the user should wait). The plan's contract
  said "render the error message". `LoginPage.tsx:21-26` already does this correctly with
  `catch (err)` + `err instanceof ApiError && err.status === 401`; CaptureForm does not even
  import `ApiError`.
- **Fix**: `catch (err)` and branch on `err instanceof ApiError` by status — 422 shows
  `err.message` and keeps focus for correction, 429 says wait, 5xx and transport failures keep
  the current retry wording.
- **Decision**: FIXED — `catch (err)` with an `ApiError` switch: 422 surfaces the backend's own message, 429 says to wait, 401 explains the expired session, everything else keeps retry wording. Matches the LoginPage pattern.

### F4 — Disabling the input blurs it, eats keystrokes, and loses focus after every capture

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.tsx:46`, `:25`
- **Detail**: Browsers blur a focused element the moment it becomes `disabled`. Anything typed
  during the round trip is dropped, and when `submitting` flips back focus does not return —
  `autoFocus` only fires on mount. Capturing five ideas in a row therefore costs five manual
  re-focus actions, which is the opposite of the frictionless capture the NFR exists for.
  `LoginPage.tsx:45-63` disables only the submit button, not the fields — the better pattern
  and already in the repo. Coupled hazard: `setTitle('')` at `:25` is unconditional and is
  safe *only because* the input is disabled. Fix the focus problem naively and that line
  silently becomes a text-eating bug for anything typed during the await.
- **Fix**: Disable only the button, restore focus via a ref after the await, and clear
  conditionally against the submitted value rather than unconditionally.
- **Decision**: FIXED — only the button is disabled now; focus is restored through a ref in `finally`; the clear is conditional on the submitted value so text typed during the round trip is never eaten.

### F5 — No request timeout: "Saving…" can hang forever

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/api.ts:44`
- **Detail**: `fetch` is called with no `AbortSignal`. Against a black-holing connection
  (captive portal, dropped VPN) the promise never settles: `submitting` stays true, the button
  reads "Saving…" indefinitely, the input stays disabled so the text cannot even be selected
  and copied, and neither branch ever runs. Only a reload recovers — which destroys the draft
  (F2). The single committed NFR is a visible confirmation within ~2 seconds; today there is
  no upper bound on feedback at all.
- **Fix**: An `AbortSignal.timeout(...)` default inside `request()`, surfaced as a distinct
  "no response" state.
- **Decision**: FIXED — `AbortSignal.timeout(10_000)` default inside `request()`, with the TimeoutError mapped to a 408 ApiError carrying a distinct message.

### F6 — Accessibility: nothing is announced, and the field is not described

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.tsx:13-15`, `:39-50`, `:55-56`, `frontend/src/App.tsx:54`
- **Detail**: Three related gaps. (a) The error and the "Saved to your Inbox." confirmation
  are plain conditionally-mounted `<p>` elements outside any live region, so a screen-reader
  user presses Enter and hears nothing — the NFR's "visible confirmation" is met for sighted
  users only. Adding `aria-live` to an element mounted at the same time as its text is
  unreliable; the region has to be present already. (b) Submitting empty or whitespace is a
  silent no-op with no message, and the early return also skips `setSaved(false)`, so a stale
  "Saved" can sit on screen and read as a second successful save. (c) The input has no `id`,
  so no `aria-describedby` links the error to it, no `aria-invalid`, and no `maxLength`
  mirroring `ItemConst::TITLE_MAX_LENGTH` — the only way to learn the cap is a 422 the UI
  then discards (F3).
- **Fix**: One always-present `role="status"` node and one `role="alert"` node whose text is
  swapped; `required` plus a real validation message; `id`/`htmlFor`, `aria-invalid`,
  `aria-describedby`, and a `maxLength` bound to a shared constant.
- **Decision**: FIXED (all three) — always-mounted role=alert and role=status nodes whose text is swapped; `required` plus an explicit whitespace-only message that also refocuses the field; `id`/`htmlFor`, `aria-invalid`, `aria-describedby` and `maxLength` bound to the shared TITLE_MAX_LENGTH constant.

### F7 — Hardcoded colours ignore the existing dark-mode tokens, and the screen renders inside dead scaffold CSS

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `frontend/src/items/InboxList.tsx:32`, `frontend/src/items/CaptureForm.tsx:55-56`, `frontend/src/App.tsx:2`, `frontend/src/index.css:53-59`
- **Detail**: `index.css` already ships a `prefers-color-scheme: dark` theme with `--bg`,
  `--text`, `--border` tokens and declares `color-scheme: light dark`, but every colour in the
  new components is a literal. On a dark-mode device the background goes near-black while
  `#555`/`#666`/`#888` text stays dark grey, and `crimson` — the error message, the one thing
  that must be readable — drops to about 3.6:1. Even in light mode `#888` timestamps (≈3.5:1)
  and `seagreen` (≈4.2:1) fail WCAG AA. Separately, `index.css:53-59` still carries the Vite
  scaffold's `#root { width: 1126px; text-align: center; border-inline: … }` — verified
  present — so the capture form and every Inbox row render centre-aligned inside a bordered
  1126px column, fighting the `maxWidth: 640` set inline; and `App.tsx:2` still imports 184
  lines of `App.css` whose selectors (`.counter`, `.hero`, `#docs`) match nothing.
- **Fix**: Use the existing custom properties plus new `--error`/`--success` tokens; drop the
  scaffold `#root` rules and the dead `App.css` import.
- **Decision**: FIXED — all literals replaced with CSS custom properties, new --muted/--error/--success tokens defined for both colour schemes; the Vite scaffold's #root rules (1126px, centred, bordered) reduced to a min-height, and the dead App.css deleted. Bundle CSS fell from 4.10 kB to 1.73 kB.

### F8 — TypeScript `strict` is off, so every nullable type in this change is decorative

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/tsconfig.app.json`
- **Detail**: Verified: no `strict` in any of the three tsconfigs, so `strictNullChecks` and
  `noImplicitAny` are off. Every `| null` union added here — `Item | null`, `useState<string |
  null>`, the `item.note !== null` narrowing, the `user !== null` guard — is unenforced. This
  matters more than usual because the project's own rationale
  (`context/foundation/tech-stack.md`) is compensating for dynamic typing with strict static
  analysis: the backend runs Larastan level 6 while the frontend runs with the net off.
  Verified the fix is free — adding `"strict": true` and running `tsc -p tsconfig.app.json
  --noEmit` exits 0 with no errors today.
- **Fix**: Add `"strict": true` to `tsconfig.app.json` now, while it costs nothing.
- **Decision**: FIXED — "strict": true added to tsconfig.app.json; build and lint pass unchanged, confirming the zero-cost measurement.

### F9 — Unhandled promise rejection on logout

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/App.tsx:40`, `frontend/src/auth/AuthContext.tsx:34-41`
- **Detail**: `onClick={() => void logout()}` — `void` discards the value, not the rejection.
  `AuthContext`'s `logout` wraps `api.logout()` in `try/finally` with no `catch`, so a network
  error or a 500 from `/api/logout` escapes as an uncaught `ApiError`. The user is logged out
  either way (the `finally` clears local state), but it is a real unhandled rejection that any
  error reporter will flag. Pre-existing in `AuthContext`; this change is the first to keep
  the call site.
- **Fix**: Catch inside `AuthContext.logout` — the `finally` already guarantees local cleanup,
  so swallowing there is defensible.
- **Decision**: FIXED — catch added inside AuthContext.logout, where the existing finally already guarantees local cleanup.

### F10 — Type, naming and dead-code cleanup

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `frontend/src/api.ts:117-126`, `:134`, `:153`, `frontend/src/App.tsx:8`, `:60`
- **Detail**: Three small items. (a) `bucket` is typed as a bare `string` on `Item` and on
  `listItems`, while the backend has an eight-value enum validated by `Rule::enum` —
  `listItems('next-actions')` compiles and 422s at runtime, and the clarify slice will lean on
  this type hard. (b) `fetchHealth` and `HealthStatus` now have no consumer anywhere in
  `frontend/src`; ESLint does not flag unused exports, so the gate stays green. (c) `App.tsx`
  is the only component in the repo using a default export and a scaffold name — it is now one
  screen among future siblings, mounted at `/` and rendering `<h2>Inbox</h2>`, while
  `LoginPage`, `ProtectedRoute`, `CaptureForm` and `InboxList` are all named exports.
- **Fix**: Add a `GtdBucket` union type and use it in both places; delete or knowingly keep the
  health helpers; rename `App` to `InboxPage`, move it beside its children, export it named.
- **Decision**: FIXED (all three) — GtdBucket union type mirroring the backend enum, applied to Item.bucket and listItems(); the dead fetchHealth/HealthStatus helpers deleted; App renamed to InboxPage, moved to src/items/ beside its children and exported named, with main.tsx updated.


## Verification note

F1 and F2 are verified by code reading plus the type, lint and build gates only. The SPA
has no test runner, so neither the load/capture interleaving nor the 401 unmount has an
automated guard, and the browser path was not driven because it requires entering a
password. **These two guardrail defects were introduced, shipped and manually signed off
without anything catching them — that is the standing gap this phase exposed.** The
highest-value first tests, if a runner is added, are exactly: resolve the POST before the
GET and assert the item survives; simulate a 401 and assert the draft is recoverable;
assert 422 / 429 / network produce different messages.
