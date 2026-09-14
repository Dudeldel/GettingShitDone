<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Capture durability and error surfacing (test rollout Phase 1)

- **Plan**: `context/changes/testing-capture-durability/plan.md`
- **Scope**: Full plan — Phases 1–5 of 5
- **Commits**: `5d6341f`, `42383cb`, `4e3bbcb`, `cc8f5e5`, `60c76c3`, `a5bcd8a`
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Findings**: 3 critical, 6 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | WARNING |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

All automated criteria re-run independently and pass: backend 66/229, Larastan 0 errors,
Pint clean, frontend 24/24, build and lint green. Architecture is clean — zero files under
`app/` changed, the layering rules hold, and the `AppRoutes` / `apiMessage` extractions are
well-judged.

**The headline finding is that this change shipped three tests that cannot fail — in a
change whose stated purpose was to stop exactly that.** The archived `ph2 F2` finding ("a
test that cannot fail") is quoted in this plan's own Critical Implementation Details as the
anti-pattern to avoid, and §6.3 as written now mandates deliberate-breakage verification.
That discipline was applied to five of the eight new behaviours and skipped on three, and
all three of the skipped ones are hollow.

## Findings

### F1 — The private-mode test asserts nothing; `vi.spyOn` on storage is a silent no-op

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.test.tsx:141-163`
- **Detail**: `vi.spyOn(sessionStorage, 'getItem' | 'setItem')` does not take effect under
  jsdom 30 — jsdom wraps `Storage` in a Proxy whose `defineProperty` trap refuses the
  override without throwing. Probe output: own property after spyOn `false`, identity
  changed `false`, calling `getItem` throws `false`, spy call count `0`. The test therefore
  runs the plain happy path and never simulates failing storage. Verified independently two
  ways: the probe above, and by deleting **every** `try/catch` from `readDraft()` and
  `writeDraft()` in `CaptureForm.tsx` — all 10 tests in the file stayed green. The test's own
  comment, "prove the guard, not just its presence", is precisely what it fails to do.
  The production guards are correct; only the test is hollow.
- **Fix**: Replace the spies with `vi.stubGlobal('sessionStorage', throwingStub)` (verified to
  reach the component) and add `unstubGlobals: true` beside `restoreMocks` in
  `vite.config.ts`. Keep the deliberate `sessionStorage`-only scope — widening to
  `Storage.prototype` breaks `getToken()` and makes the test pass for a second wrong reason.
- **Decision**: FIXED — vi.spyOn replaced with vi.stubGlobal (full sessionStorage replacement); unstubGlobals: true added to vite.config.ts. Re-verified by deliberate breakage: stripping readDraft's try/catch now turns the test RED (it previously stayed green).

### F2 — Unguarded `localStorage` white-screens the app at boot

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/api.ts:9-19`, entered from `frontend/src/auth/AuthContext.tsx:9`
- **Detail**: `getToken()` / `setToken()` / `clearToken()` touch `localStorage` bare, while
  `CaptureForm` guards every `sessionStorage` call. `AuthContext.tsx:9` calls `api.getToken()`
  inside a `useState` initializer — during render, outside any `try`. Verified:
  `AuthProvider` under a throwing `localStorage` throws `SecurityError`. `main.tsx` has no
  error boundary, so with storage blocked (Safari "Block All Cookies", a partitioned embed,
  `dom.storage.enabled=false`, or a full quota) the user gets a blank page with no recovery
  path. Separately, `request()`'s `catch` wraps only `fetch`, so a throw from `getToken()` at
  `:43` escapes as a non-`ApiError` and `messageFor` mislabels it "Could not reach the
  server." This gap was *found* during implementation and recorded in a test comment
  (`CaptureForm.test.tsx:143-148`) and in test-plan §6.6 — but recording a user-facing break
  is not the same as guarding it, and the note understated it as "narrow".
- **Fix A ⭐ Recommended**: Wrap all three accessors in `try/catch` with an in-memory fallback,
  mirroring `readDraft`/`writeDraft`.
  - Strength: Same shape as the guard already accepted in `CaptureForm`; keeps a
    storage-denied browser usable for the session rather than blank.
  - Tradeoff: The session silently stops surviving a reload in that environment.
  - Confidence: HIGH — the pattern exists in this repo and the failure is reproduced.
  - Blind spot: Not verified against a real Safari "Block All Cookies" profile.
- **Fix B**: Add an error boundary in `main.tsx` only.
  - Strength: Catches this and every other render-time throw.
  - Tradeoff: Turns a blank page into an error page; the app still does not work.
  - Confidence: HIGH that it helps, LOW that it is sufficient alone.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — getToken/setToken/clearToken wrapped in try/catch with an in-memory fallback, mirroring readDraft/writeDraft. A new frontend/src/api.test.ts covers all three accessors under a throwing localStorage plus the normal round trip; proven able to fail (reverting the guard reddens 3 of 4).

### F3 — The new CI test gate runs on a Node version the toolchain declares unsupported

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `.github/workflows/ci.yml:49-51`
- **Detail**: CI pins `node-version: '20'`. Four newly-added packages declare Node 20 out of
  range: `vitest@5.0.0` (`^22.12.0 || ^24.0.0 || >=26.0.0`), `jsdom@30.0.1`
  (`^22.22.2 || ^24.15.0 || >=26.0.0`), `undici@8` via jsdom (`>=22.19.0`), and
  `@testing-library/jest-dom` (`>=22`). `engine-strict` is false, so `npm ci` only warns
  `EBADENGINE` and `npm test` then runs on an unsupported runtime; `undici@8`'s fetch path
  calls `Promise.withResolvers()` (Node 22+). **This was never verified** — every local run
  was Node 22.23.2, and the plan's own §D.4 said to treat the CI result as authoritative,
  which was not done. The gate this phase exists to install may not execute at all.
- **Fix**: Set `node-version: '22'` in `ci.yml`, add `"engines": { "node": ">=22.12" }` to
  `frontend/package.json` and a `.nvmrc`, so the drift fails at install rather than at runtime.
- **Decision**: FIXED — ci.yml bumped to Node 22, frontend/package.json gains engines.node >=22.12, and frontend/.nvmrc pins 22, so a future mismatch fails at npm ci rather than silently at runtime.

### F4 — The remount test reads a stale form; it cannot fail

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.test.tsx:126-139`
- **Detail**: `captureAndReadMessage()` renders form #1 and never unmounts it; the test then
  renders #2, unmounts it, and renders #3. `getAllByLabelText(...)[0]` resolves to form #1 —
  still mounted, still holding the text in `useState`, never re-reading the draft. Two
  mechanisms compound: RTL appends each `render` without cleanup, and all three inputs share
  `id="capture-title"`, so label association resolves through `document.getElementById` and
  returns only the first. Verified: with `readDraft()` hard-returning `''`, the file stayed
  10/10 green. Net coverage is not zero — `CaptureForm.401.test.tsx:61-86` does go red under
  the same mutation — but this test contributes nothing while reading as if it does.
- **Fix**: `cleanup()` before the remount, then assert with the singular `getByLabelText`.
- **Decision**: FIXED — cleanup() before the remount and a singular getByLabelText. Proven able to fail: hard-returning '' from readDraft now reddens it (it previously stayed green).

### F5 — The "notice is dropped after re-login" test cannot fail

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `frontend/src/items/CaptureForm.401.test.tsx:88-110`
- **Detail**: `setSessionExpired(false)` at `AuthContext.tsx:41` is entirely unprotected. Two
  mutations verified: replacing it with a no-op → 3/3 pass; latching it to `true` forever →
  3/3 pass. A successful login navigates to `/` and unmounts `LoginPage` wholesale, so
  `queryByText(/session expired/i)` is absent regardless of the flag. The real risk — a stale
  notice reappearing on the next logout→login cycle — is never exercised.
- **Fix**: After signing back in, log out and return to `/login`, then assert the notice is
  absent. That asserts the state rather than the unmount.
- **Decision**: FIXED — the test now logs out and returns to /login before asserting, so it checks the flag rather than LoginPage's unmount. Proven able to fail: latching setSessionExpired(true) now reddens it (both earlier mutations passed).

### F6 — Three production behaviours have no coverage, including the `Authorization` header

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: `frontend/src/api.ts:44-46`, `:68`; `frontend/src/items/CaptureForm.tsx:79`
- **Detail**: Verified by mutation, each left the suite fully green: removing the
  `Authorization: Bearer` header entirely → **20/20 pass**; removing `clearToken()` from the
  401 branch → 3/3 pass; removing the `Your text is still here.` reassurance added in Phase 3
  → 13/13 pass. The first is the notable one — no MSW handler inspects auth, so the suite
  cannot distinguish an authenticated request from an anonymous one, *including in
  `CaptureForm.401.test.tsx`, whose entire subject is the auth flow*.
- **Fix**: Have one handler reject unauthenticated requests
  (`if (!request.headers.get('authorization')) return 401`), and assert the token is gone from
  `localStorage` after the 401 bounce.
- **Decision**: FIXED (all three) — new api.test.ts cases assert the bearer header is attached with a token, omitted without one, and discarded on a 401; CaptureForm.test.tsx asserts the draft reassurance on both a 422 and a transport failure. Proven able to fail: removing the Authorization header now reddens the suite (it previously left 20/20 green).

### F7 — "Extraction is mechanical — no behaviour change" was false, and untestable by design

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/apiMessage.ts:17`, `frontend/src/items/CaptureForm.tsx:79`
- **Detail**: The plan (`plan.md:467-469`) justified adding no tests for the Phase 4
  extraction with "no behaviour change to the capture path, which Phase 3's tests already
  pin." Both halves were wrong. The network string was reworded
  ("…Your text is still here — try again." → "…Please try again."), and `CaptureForm.tsx:79`
  now appends `Your text is still here.` to **every** failure where previously only the
  transport arm carried it — so every 422, 429 and 500 message differs from what shipped in
  Phase 3. And Phase 3's tests could not have pinned it: `git show cc8f5e5 --stat` shows
  `CaptureForm.test.tsx` was not modified, because the assertions use `toContain`/`toMatch` on
  fragments and never touch the trailing clause. `grep -rn "still here" frontend/src
  --include=*.test.tsx` returns nothing. The loose-assertion policy is correct in itself; the
  error was claiming it provided a guarantee it structurally cannot.
- **Fix**: Add one assertion that a capture failure reassures about the draft, and correct the
  plan's Phase 4 entry to say what actually changed.
- **Decision**: FIXED — Phase 4 change #3 now carries a CORRECTION note stating what actually changed and why "the tests already pin it" was unsound (fragment assertions cannot see a trailing clause). The missing coverage itself was added under F6.

### F8 — The plan body was never amended; three deviations live only in commit messages

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Scope Discipline
- **Location**: `context/changes/testing-capture-durability/plan.md` (Progress section only)
- **Detail**: `git diff 5d6341f..a5bcd8a -- plan.md` touches nothing but `- [ ]` → `- [x]`
  checkboxes. Three deviations were decided in-session and recorded in commits, code comments
  and test-plan §6.6, but never in the plan: (a) `api.ts:61` was changed in Phase 2, which
  lists no production file in its "Changes Required" and for which the plan had *already*
  specified the alternative to use if the probe failed (a manufactured rejection); (b)
  `frontend/src/AppRoutes.tsx` is a new production module — `grep -n "AppRoutes" plan.md`
  returns zero hits; (c) Progress item 5.5 credits `60c76c3` with the §2 backport, but the
  entire backport shipped in `5d6341f` and `60c76c3` changed nothing in §2. Each individual
  decision was sound and approved; the cumulative effect is that a reader trusting the plan as
  the record of what was built would be wrong about all three.
- **Fix**: Add an addendum section to the plan recording the three deviations and correcting
  the 5.5 attribution.
- **Decision**: FIXED — an "Addendum — deviations from this plan" section records all three (api.ts in Phase 2, AppRoutes.tsx, and the 5.5 misattribution) with rationale. Recorded as an addendum rather than rewritten into the phase entries, so the approved plan and what actually happened stay separable.

### F9 — Five Phase 3 contract items were never implemented

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `frontend/src/items/CaptureForm.test.tsx`
- **Detail**: The Phase 3 contracts name these explicitly; none exist. (a) "Text typed *during*
  a successful round trip is not eaten" — the conditional clear at `CaptureForm.tsx:68-73` has
  zero coverage. (b) "focus returns to the field" on a whitespace-only submit — the alert and
  the no-request assertions are present, the focus one is not. (c) draft survival across an
  **explicit logout** — the only mention is a comment at `:132`; no test clicks "Log out".
  (d) the tab-close boundary note "in the test file" — it lives in `CaptureForm.tsx` and
  `contract-surfaces.md` instead. (e) a 408 assertion in the capture form — relocated to the
  probe file, which asserts the client mapping, not that the message reaches a user.
  Progress items 3.1–3.6 were all checked complete regardless.
- **Fix**: Add the four missing assertions; decide explicitly whether (e) stays relocated and
  record that in the plan.
- **Decision**: FIXED (a)-(d), (e) documented — new tests cover the conditional clear (proven able to fail: making the clear unconditional reddens it), focus restoration after a whitespace-only submit, and draft survival across an explicit logout; the tab-session boundary is now stated in CaptureForm.test.tsx. The 408 assertion stays in the probe file, recorded in the plan addendum.

### F10 — The timeout probe costs 91% of frontend suite wall time on every push

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `frontend/src/test/abort-signal.probe.test.ts:40-55`
- **Detail**: Measured at 10003ms of an 11.0s suite, against a hard-coded
  `REQUEST_TIMEOUT_MS = 10_000` and a 15s per-test budget — 50% headroom, fixed and
  unmonitorable, on a runner that executes files in parallel. If it ever blows, the failure
  reads as "the 408 mapping broke" rather than "CI was slow", which is the worst kind of
  flake. The test itself is real — neutering the `TimeoutError → 408` branch turns it red.
  `vi.useFakeTimers()` will not help; `AbortSignal.timeout` is native.
- **Fix**: Make the timeout injectable (module-level setter or `import.meta.env`) and run the
  probe at ~50ms; the assertion is unchanged.
- **Decision**: FIXED — DEFAULT_REQUEST_TIMEOUT_MS exported and the active timeout made settable via setRequestTimeoutForTests(); the probe runs at 50ms. Assertions unchanged. Probe file went from 10.0s to 0.83s and the whole frontend suite from 11.3s to 6.3s while growing from 24 to 34 tests.

## Additional observations (not counted as findings)

- `setup.ts:16-17` — `server.resetHandlers()` runs before `cleanup()`; a request fired during
  unmount hits a reset handler set and trips `onUnhandledRequest: 'error'`. Conventional order
  is `cleanup()` first.
- `CaptureForm.tsx:75` — `onCaptured(item)` sits inside the `try`, so a throwing callback would
  show a failure message after a successful save.
- `CaptureForm.test.tsx:19` — `respondToCaptureWith` holds one `Response` instance; a second
  POST in the same test would read a consumed body. Use a factory.
- `api.ts:32-35` — `setUnauthorizedHandler` is a write-only module global with no unregister
  and no reset in `setup.ts`.
- `ci.yml` — the frontend gates run sequentially in one job while root `CLAUDE.md` specifies
  parallel gates; no `cache: npm` on `setup-node`.
- `InboxPage.tsx:23` — `loadError` is never cleared and there is no retry affordance.
- `CaptureForm.tsx:32-33` — stray double blank line left by the extraction.
- Root `CLAUDE.md` still says "AWS Lightsail" while `tech-stack.md` and `ci.yml` say Railway.

## Verified valid (suspicions tested and disproved)

These were probed by deliberate breakage and are genuinely load-bearing:

- `InboxPage.test.tsx:56` and `:75` — both real. Reverting `mergeById` to `fromServer` reddens
  the first; removing id-filtering reddens the second.
- `CaptureForm.401.test.tsx` — all three tests genuinely require the full router shell.
  Forcing `ProtectedRoute` to always render `<Outlet />`: 3/3 red. Removing
  `onUnauthorized`: 3/3 red.
- Both new backend tests in `CaptureItemTest.php` — `create → make` reddens the durability
  test; swallowing `QueryException` reddens the failure test; rewording `bootstrap/app.php`
  trips the pinned `assertJsonPath`.
- 401 test independence, `restoreMocks` behaviour, cross-test `onUnauthorized` leakage, and
  double-submit guarding were each checked and found clean.
