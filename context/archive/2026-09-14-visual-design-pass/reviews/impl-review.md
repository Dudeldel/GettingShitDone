<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Visual design pass (S-10)

- **Plan**: context/changes/visual-design-pass/plan.md
- **Scope**: Phases 1–3 of 3 (full plan)
- **Date**: 2026-09-14
- **Verdict**: REJECTED → all 10 findings fixed; 10 mutations, 10 killed, plus the three visual fixes verified in a browser
- **Findings**: 2 critical, 5 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING → PASS after fixes |
| Scope Discipline | WARNING |
| Safety & Quality | FAIL → PASS after fixes |
| Architecture | WARNING → PASS after fixes |
| Pattern Consistency | PASS |
| Success Criteria | FAIL → PASS after fixes |

All six automated gates pass. Both FAILs are things no gate in this project can see — which is
the slice's own stated risk, now realised.

**The accessibility contract survived intact.** Verified two independent ways: a semantic-
attribute diff over `fbe09ae~1..HEAD` whose only non-re-added removal is `aria-modal`, and 30
sandbox mutations against the full suite. Focus-to-the-safe-button, focus-into-the-wizard,
both Escape handlers, all five live regions, `aria-current`, `<time dateTime>`, seven
fieldset/legend pairs, `<ul>/<li>`, `autoFocus`, `maxLength`, `aria-busy` — all present.

## Findings

### F1 — `--color-muted` fails WCAG AA everywhere it is used

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality / Success Criteria
- **Location**: frontend/src/index.css:24
- **Detail**: `#767682` computes to **4.48:1** on `--color-surface` and **4.15:1** on
  `--color-ground`, against the plan's own stated bar of ≥ 4.5:1. Computed independently from
  the hex values; every other token clears comfortably (ink 18.04, body 8.74, accent 7.24,
  danger/ok 6.54). None of the ~13 failing sites is large text — all are `text-sm` or
  `text-xs`: the signed-in email (`AppHeader.tsx:20`), **the "Log out" and "Cancel" button
  labels** via `.btn-ghost` (`index.css:168`), **every input placeholder** via
  `.field::placeholder` (`:198`), the session-expired explanation (`LoginPage.tsx:39`), every
  empty state and the `✓ Done` marker and all timestamps (`InboxList.tsx:25,45,56`), the
  two-minute status line (`ClarifyDialog.tsx:313`), `BucketPage.tsx:101,168`,
  `NotFound.tsx:18`, `AuthContext.tsx:66`.
  **Compounding this: Progress item 1.11 "Every token verified against its contrast bar" is
  ticked.** Colours were measured in the browser but no ratio was ever computed. The plan
  justified that bar with "because `crimson` proves an unverified colour ships" — and an
  unverified colour shipped.
- **Fix**: `#6b6b77` (5.26:1 / 4.87:1), still visibly lighter than `--color-body` #4a4a55, so
  the three-level hierarchy survives.
- **Decision**: FIXED — `--color-muted` → `#6b6b77` (5.26:1 surface / 4.87:1 ground, computed), still clearly lighter than `--color-body`. Every token re-checked by calculation this time, not by eye.

### F2 — The focus ring rewrites every control's corner radius

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: frontend/src/index.css:98
- **Detail**: `border-radius: 3px` inside the unlayered `:focus-visible` block does not shape
  the outline — it rewrites the **element's own** radius, and because the rule is unlayered it
  beats `.btn`'s 8px (`:129`), `.field`'s 8px (`:193`) and every `rounded-*` utility.
  Confirmed in a real browser: the Capture button measures `border-radius: 8px` at rest and
  **3px** the moment it takes keyboard focus. Every Tab step in the application visibly
  squares off the control being focused and rounds it again on blur. No assertion in the suite
  can observe it — this is precisely the defect class the slice shipped without a gate for.
- **Fix**: Delete the `border-radius` line. Browsers already follow the element's own radius
  when painting an outline, so it bought nothing.
- **Decision**: FIXED — the `border-radius` line deleted. Verified live: the Capture button now measures 8px at rest AND 8px under keyboard focus, with the accent ring intact.

### F3 — `--color-line` is the only boundary ~25 controls have, at 1.46:1

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: frontend/src/index.css:18, used by `.field` (:191) and `.btn-quiet` (:157)
- **Detail**: `#d5d5dc` is **1.46:1** on surface and 1.35:1 on the ground. WCAG SC 1.4.11
  requires 3:1 for the boundary of a user-interface component **when that boundary is what
  identifies it** — and it is: `.btn-quiet`'s white face is 1.08:1 against the page ground, so
  the border is the only thing distinguishing it from the page. That covers every wizard
  button, "Clarify", "Empty the Trash", "Keep them", and all four text inputs.
- **Fix**: Split the token — keep `--color-line` for decorative dividers and card edges, add a
  stronger `--color-control-line` (e.g. `#8a8a96`, 3.23:1) used by `.field` and `.btn-quiet`.
  - Strength: Keeps card edges quiet, which is what the calm register wants, while giving
    controls a real edge.
  - Tradeoff: Two border tokens to keep straight.
  - Confidence: HIGH — the ratios are computed, not estimated.
  - Blind spot: Whether the stronger edge reads as heavy next to the subtle card shadows;
    a browser check settles it.
- **Decision**: FIXED — new `--color-control-line: #8a8a96` (3.41:1 / 3.16:1) used by `.field` and `.btn-quiet`; `--color-line` stays decorative for dividers and card edges.

### F4 — The 404 page has no test, and its own stated risk is unprotected

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: frontend/src/NotFound.tsx
- **Detail**: Zero coverage. The review agent rewrote the page's copy to "No such page for this
  account." — a deliberate account-existence leak, the exact risk the file's own docblock names
  — and all 93 tests stayed green. Nothing catches the route being deleted, regressing to
  blank, or being moved **inside** `ProtectedRoute` (which would bounce a typo'd URL to login
  and reintroduce the leak). `AppRoutes.test.tsx` exists specifically to mount the real route
  tree and was not extended.
- **Fix**: Two tests in `AppRoutes.test.tsx` — an unknown path with no token asserts the 404
  heading is present **and** `/sign in/i` is absent; the same with a valid token asserts
  identical markup.
- **Decision**: FIXED — two tests in `AppRoutes.test.tsx` covering the 404 signed out and signed in. Mutation-verified: deleting the route, moving it inside the guard, and rewriting the copy to leak account existence each redden it.

### F5 — The shared frame is not a banner landmark, and is unpinned where it matters

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: frontend/src/AppHeader.tsx:17, rendered at InboxPage.tsx:57 and BucketPage.tsx:94
- **Detail**: `<AppHeader />` renders **inside** `<main>`. Per the HTML spec a `<header>`
  descending from `main` is not a `banner` landmark — it is generic. So the app now has no
  banner landmark at all, the product name is in neither the landmark list nor the heading
  outline, and site-level chrome sits inside the landmark reserved for page-unique content.
  Separately: removing `<AppHeader />` from `BucketPage` leaves all 93 tests green, while
  removing it from `InboxPage` fails 2 — so the frame is protected on the one screen that
  already had it and unprotected on the seven it was built for.
- **Fix**: Hoist it into `ProtectedRoute` as a sibling of `<main>` so `<header>` becomes a real
  banner, and add a "Log out is reachable from a bucket" assertion to the `it.each` loop.
  - Strength: Fixes the landmark and removes the duplication in one move; the h1 and link
    counts are untouched because the header adds neither.
  - Tradeoff: Touches the route tree, which `AppRoutes.test.tsx` mounts for real.
  - Confidence: HIGH — the spec rule is unambiguous and the test counts are known.
  - Blind spot: Whether any test asserts `<main>` is the outermost element of a screen.
- **Decision**: FIXED — `AppHeader` hoisted into `ProtectedRoute` as a sibling of `<main>`, so `<header>` is a real banner landmark. Two tests added: sign-out works from a bucket view, and `getByRole('banner')` resolves. Both mutation-verified.

### F6 — The login screen flags both fields invalid for failures that are not theirs

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: frontend/src/auth/LoginPage.tsx:54,66,73-77
- **Detail**: `aria-invalid={error !== null}` is on **both** inputs, and `error` is also set for
  the generic case (`:25`, "Login failed. Please try again." — a 500, a dropped connection). A
  network blip therefore announces both fields as "invalid entry" and paints both red when
  nothing is wrong with either. And unlike the other two inputs in the app, neither carries
  `aria-describedby` and the error `<p>` has no `id`, so a screen reader hears "invalid entry"
  with nothing linking it to the explanation. There is no `LoginPage.test.tsx` anywhere, so
  none of this is covered — on the one screen where `.field[aria-invalid='true']` now drives
  visible styling.
- **Fix**: Give the alert an `id`, point both inputs at it with `aria-describedby`, and scope
  `aria-invalid` to the credential (401) case only.
- **Decision**: FIXED — `id="login-error"` on the alert, `aria-describedby` on both inputs, and `aria-invalid` scoped to the 401 case via a separate `badCredentials` flag. A new `LoginPage.test.tsx` (the screen had none) covers all three; three mutations, three kills.

### F7 — Heading levels changed, which the plan forbids outright

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: frontend/src/AppHeader.tsx:19, frontend/src/items/InboxPage.tsx:80
- **Detail**: "What We're NOT Doing" says "no changed heading levels". The Inbox's
  `<h1>Getting Shit Done</h1>` became a `<span>` and its `<h2>Inbox</h2>` was promoted to
  `<h1>`. The change is reasoned and disclosed — in the `e55388f` commit message — but every
  edit to `plan.md` across the slice was a Progress checkbox flip or a SHA write-back. The
  contract text was never amended, so a reader of the plan alone still concludes this was
  prohibited.
- **Fix**: Amend the plan's "What We're NOT Doing" entry to record the exception and why
  (a bucket page's one h1 must name the bucket, so the product name cannot be a heading).
- **Decision**: FIXED — the plan's "What We're NOT Doing" entry now carries the heading-level exception and its reasoning, instead of leaving the resolution in a commit message.

### F8 — The rehydration message is announced to screen readers it is meant to be hidden from

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: frontend/src/auth/AuthContext.tsx:66, frontend/src/index.css:209-221
- **Detail**: `.settle` delays **visibility**, not **mounting**. `opacity: 0` does not remove an
  element from the accessibility tree, so on an 80ms rehydration — exactly the case the 300ms
  delay exists to suppress — a screen-reader user is told "Restoring your session…" while a
  sighted user sees nothing. A rehydration resolving near 350ms also flashes a half-opacity
  message for ~50ms, which is the flash the delay was meant to prevent, moved to a narrower
  window. The surface is also untested: regressing it to `return null` — the blank-screen bug
  this slice fixed — leaves 93 green.
- **Fix**: Make the delay real with a state timer (`setTimeout(() => setSlow(true), 300)`) and
  render the `<p>` only when slow; mounting and visibility then agree for every audience.
- **Decision**: FIXED — the message is now withheld by mounting it late (a 300ms state timer) rather than by fading it in, so screen readers and sighted users get the same answer. `.settle` deleted. A test pins that the surface is not null, that the message is absent at first and present later.

### F9 — Three small defects in the new token layer

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: frontend/src/index.css:27, :45, :225
- **Detail**: (a) `--color-accent-soft` has zero references — a slice whose Current State
  Analysis counted "five dead tokens" as a defect introduced a sixth. (b) `html { font-size:
  16px }` pins the root to an absolute pixel value, defeating the reader's browser font-size
  preference; every other size is `rem`, so the whole scale becomes unresponsive to it.
  (c) `.live-line { min-height: 1.25rem }` reserves 20px for a line that renders at 21px, so
  the jolt is reduced from a full line to 1px rather than removed, and the two-region wrappers
  can hold two lines.
- **Fix**: Delete the unused token; `font-size: 100%`; reserve `1.3125rem`.
- **Decision**: FIXED — `--color-accent-soft` deleted, `font-size: 100%` replaces the absolute 16px root, `.live-line` reserves 1.3125rem for its 21px line.

### F10 — Two test and bookkeeping nits

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria / Scope Discipline
- **Location**: frontend/src/items/CaptureForm.test.tsx:292, ClarifyDialog.test.tsx:570;
  context/foundation/roadmap.md:44,299
- **Detail**: Both new aria tests open with `await screen.findByRole('alert')` as their wait for
  the error — but that region is deliberately always mounted, so it resolves on the first tick
  and waits for nothing. They still fail correctly, but the line reads as a synchronisation
  point that is not one. Separately, the roadmap still shows S-10 as `in-progress` while
  `change.md` says `implemented`, and commit `fbe09ae` carried two entirely new roadmap slices
  (S-11, S-12) that the plan never described — disclosed in the commit body, but scope the plan
  did not authorise.
- **Fix**: Wait on the message text instead; `/10x-archive` will reconcile the roadmap status.
- **Decision**: FIXED — both no-op `findByRole('alert')` waits replaced with waits on the actual message text. The stale roadmap status is left for `/10x-archive`, which owns that flip.

## Also checked, clean

- **The accessibility contract**: every item verified present, most of them mutation-pinned.
- **Test vacuity**: no pre-existing test went vacuous from the re-nesting. `getByText` still
  matches the item title beside the `✓ Done` span (direct-text-node semantics),
  `toBeEmptyDOMElement()` still holds because the reserved height sits on a wrapper, and
  `InboxPage.test.tsx:228`'s singular `getByRole('listitem')` still resolves — `AppHeader`
  contributes no `<li>` and nothing carries `aria-hidden`.
- **All five new/changed assertions can fail**, mutation-verified one by one.
- **`:focus-visible` cannot be defeated** by any utility: it is emitted unlayered and every
  Tailwind utility lives in `@layer utilities`. Only a `!`-suffixed `outline-none!` or an
  inline style could win.
- **Tailwind v4 usage is correct**: `@theme` namespaces, `@layer components`, `@custom-variant`,
  no config file, no `dark:` utility anywhere, no `prefers-color-scheme` in the built CSS.
- **`NotFound` leaks nothing** as written, and `<Link className="btn">` is the right control —
  a real `<a>` keeps the native keyboard contract.
- **Bundle**: CSS 14.90 kB / 3.95 kB gzip, JS 251.57 kB / 79.70 kB gzip — the small delta the
  plan predicted.

## Triage outcome

All ten findings fixed. Suite grew 93 → **102** (a `LoginPage.test.tsx` that never existed,
plus 404, banner, sign-out-from-a-bucket and rehydration coverage).

Ten mutations over the fixes, **ten killed**: the 404 route deleted · the 404 moved inside the
guard · `NotFound` rewritten to leak account existence · the frame removed from the guard ·
`<header>` demoted so it is no longer a banner · rehydration back to `null` · the holding
message no longer withheld · login losing `aria-describedby` · login flagging both fields for
a server fault · `aria-invalid` never set on capture.

The three visual fixes are invisible to any test by construction and were verified in a real
browser instead: the Capture button holds 8px at rest **and** under keyboard focus, `--color-muted`
resolves to `#6b6b77`, the input border to `#8a8a96`, and `<header>` sits outside `<main>`.

**Process note, recorded because it is the second time.** The first mutation pass reverted
files with `git checkout --`, which restores from HEAD — and the F5 fix was uncommitted, so the
pass silently destroyed it and left the app in a half-state (the Inbox stopped rendering the
frame while the guard had not yet taken it over). Caught by noticing `git diff` reported no
changes to files that had just been edited. The second pass saves each file's contents in
memory and restores from there in a `finally`, touching git not at all.
