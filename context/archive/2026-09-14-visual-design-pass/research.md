---
date: 2026-09-14T15:42:48+02:00
researcher: Jakub Dudek
git_commit: ea44267b91fc46a417282ef4222154eb225d61f6
branch: main
repository: GettingShitDone
topic: "Adopting Tailwind + a component kit for the GSD SPA, and what it can break"
tags: [research, codebase, frontend, styling, tailwind, accessibility, test-coverage]
status: complete
last_updated: 2026-09-14
last_updated_by: Jakub Dudek
---

# Research: Adopting Tailwind + a component kit for the GSD SPA

**Date**: 2026-09-14T15:42:48+02:00
**Researcher**: Jakub Dudek
**Git Commit**: `ea44267b91fc46a417282ef4222154eb225d61f6`
**Branch**: main
**Repository**: GettingShitDone

## Research Question

Roadmap S-10 (`visual-design-pass`): make the UI read as a considered product rather than the
unstyled scaffold the feature slices have been building on. Three decisions were taken before
research because the PRD commits nothing about visual design (see `change.md`):

1. **Tailwind v4 + a ready-made component kit** — not a token repair, not hand-written CSS.
2. **Dark mode out of scope** — light only; the half-built dark palette comes out.
3. **No visual-regression pin** — test-plan Phase 4 (Risk #5) does not ride along.

Given 1 + 3, the research question is really: *what exactly is at risk, and what is the
safety net actually made of?*

## Summary

**The single most important finding — the existing test suite cannot observe appearance at
all.** All 91 tests pass while a screen is visually broken. Three independent reasons, each
sufficient on its own:

- There is **no visibility assertion anywhere** in `frontend/src` — zero occurrences of
  `toBeVisible`, `getComputedStyle`, or `offsetParent`.
- **No CSS is loaded in the test environment.** `index.css` is imported only by
  `src/main.tsx:4`, and no test mounts `main.tsx`. After a Tailwind port every visual
  property lives in a `class` attribute jsdom never resolves.
- The only visibility filter in the stack (`getByRole` → `isInaccessible`) reads *computed*
  `display`/`visibility`, which without a stylesheet can only see inline styles.
  `getByText` — which drives 33 of the 91 tests — applies no visibility filter at all.

A green 91/91 therefore survives: zero-height containers, `opacity: 0`, white-on-white, an
element under a backdrop, a Tailwind `hidden` class, a collapsed grid — and **a modal no
human can click**, because jsdom's `user.click()` dispatches straight at the element with no
hit-testing. All 27 `ClarifyDialog` tests would pass against an unusable dialog.

**The second most important finding is a strategic split.** The two halves of this slice
carry wildly different risk:

| Work | Test risk | Evidence |
|---|---|---|
| **Tailwind port + dark-mode removal** (classes replace inline styles; no markup change) | **0%** | Zero assertions on `className`, `toHaveStyle`, `getComputedStyle`, `--tokens`, `matchMedia` or `color-scheme` anywhere in the suite |
| **Component-kit swap** (dialog, form controls, nav) | **41%** | 37 of 91 tests carry at least one structural or behavioural coupling a kit is likely to disturb |

All of this slice's risk lives in the second row. That is the central fact for planning.

**Third**: the visual starting point is worse than the ticket implied, and in a way that
makes the fix cheaper. Every input and button in the product renders at **13.33px Arial**
because nothing sets `font: inherit` — Tailwind's Preflight fixes that for free. And the
`h1` overlap is not an edge case: it reproduces at the default 1280px desktop width.

## Detailed Findings

### 1. What the test suite is actually made of

91 tests across 9 files (there are no `src/auth/*.test.tsx`; auth is covered indirectly by
`AppRoutes.test.tsx` and `CaptureForm.401.test.tsx`). Classified by each test's **most
fragile** coupling:

| Class | Meaning | Count | Share |
|---|---|---|---|
| A | accessible-name only (`getByRole` + name, `getByLabelText`) | 9 | 10% |
| B | text content (`getByText`, `toHaveTextContent`) | 33 | 36% |
| C | **structural** (element counts, `within`, `toBeEmptyDOMElement`, index access) | 11 | 12% |
| D | **behavioural / a11y state** (`toHaveFocus`, `aria-current`, role lookups, Escape) | 18 | 20% |
| E | network / payload / non-DOM | 20 | 22% |

Counting tests that contain *at least one* assertion of a class (they overlap): **26 contain
a C assertion, 30 contain a D assertion, 37 contain C or D**. Only the 20 pure-E tests are
genuinely restyle-proof.

Two RTL internals were verified in `node_modules` rather than assumed:

- `getNodeText` (`@testing-library/dom/dist/get-node-text.js:8`) joins **only direct
  `TEXT_NODE` children**. Wrapping a whole string in one new `<span>` *re-targets* the query
  rather than breaking it; it breaks when the string is **split across siblings**, when a
  string currently **concatenated from several text nodes in one element** gets separated, or
  when the wrap creates a **second match** (`getByText` then throws "found multiple").
- `queryAllByText` (`queries/text.js:14`) applies **no visibility filter** — only
  `ignore: 'script, style'`.

### 2. The danger list — what a kit swap breaks, by mechanism

Ordered by probability.

**a. `toBeEmptyDOMElement()` on always-mounted live regions — near-certain break, 11 tests.**
`BucketPage.test.tsx:263`, `ClarifyDialog.test.tsx:505`, and `CaptureForm.test.tsx:29` — the
last being a shared helper inherited by 8 tests (`:39, :55, :63, :78, :93, :125, :135, :149`).
Today `<p role="alert">{error ?? ''}</p>` renders **zero child nodes** when empty
(`CaptureForm.tsx:110-112`, `ClarifyDialog.tsx:383-385`, `BucketPage.tsx:165-167`). Nearly
every kit `<Alert>` renders an icon or a wrapper div and is therefore never empty.
**Extra hazard**: `CaptureForm.test.tsx:29` is a *synchronisation gate* — if the region is
never empty, its `waitFor` resolves immediately and 8 tests start reading the message before
it arrives, producing confusing flakes rather than a clean failure.

**b. A global `<Toaster>` makes every singular alert lookup ambiguous — ~12 tests.**
Adding one is shadcn's standard first move. Affected: `CaptureForm.test.tsx:28` (8 tests via
the helper) and `:267`; `ClarifyDialog.test.tsx:175, 191, 203, 499`; and
`ClarifyDialog.test.tsx:358` for `getByRole('status')`.

**c. `getByRole('listitem')` singular — `InboxPage.test.tsx:228`.** Requires `<ul>/<li>` in
`InboxList.tsx:31-33` to survive **and** nothing else on the screen to introduce an `<li>`.
Radix NavigationMenu and most kit sidebars render nav as `<ul><li>` — that alone adds 8 and
breaks the test.

**d. `readClock()` anchored regex — 4 tests.** `ClarifyDialog.test.tsx:304` matches
`/^\d:\d{2}$/` against `getNodeText` of `<p>{formatClock(s)}</p>`
(`ClarifyDialog.tsx:293-295`). Splitting minutes and seconds into spans, or adding any label
inside the same element, makes **no element match** and the query throws. Used by `:327`,
`:336`, `:349`, `:390`.

**e. `ClarifyDialog.test.tsx:253` breaks on both halves.** It asserts
`getByRole('heading', {level: 3})` **and** `toHaveFocus()`. shadcn/Radix `DialogTitle`
renders an `<h2>` and is not focusable.

**f. Focus assertions — 3 tests.** `BucketPage.test.tsx:241` is the one that matters: focus
must land on the **safe** button ("Keep them"). Radix `AlertDialog` focuses
`AlertDialogCancel` by default — which happens to be correct — but shadcn's generated
`AlertDialogFooter` places `AlertDialogAction` **before** `AlertDialogCancel` in some
layouts, and any kit falling back to "first focusable" lands on **"Yes, discard them"**. That
is a reflexive-Enter data-loss bug on an operation with no undo. **Verify empirically per
kit; do not assume.**

**g. `aria-current="page"` — 7 tests** (`BucketPage.test.tsx:55` via `it.each`). Radix
NavigationMenu signals the active item with `data-active`/`data-state`, not `aria-current`.

**h. `getByRole('heading', {level: 1})` with no name — 7 tests** (`BucketPage.test.tsx:53`).
Requires exactly **one** `<h1>` per bucket page; an app-shell header with a site-title `<h1>`
breaks all seven at once.

**i. `getAllByRole('link')).toHaveLength(8)` — `BucketPage.test.tsx:84`.** A logo link, a
skip-link or a footer link takes it to 9.

**j. Kit `<Button>` defaulting to `type="button"`.** Roughly 40 test actions are a
`user.click()` on a `type="submit"` button relying on native form submit
(`CaptureForm.tsx:87,107`, `ClarifyDialog.tsx:360,375`, `LoginPage.tsx:49,73`). A kit button
that defaults to `type="button"` silently disables capture, delegate and sign-in. shadcn's
Button does not set a default type (so it inherits `submit`) — verify whichever kit is chosen.

**k. Portal + `aria-hidden` on background content.** `screen.*` queries survive a portal
(they query `document.body`), but Radix `Dialog` applies `aria-hidden="true"` to portal
siblings, and `*ByRole` excludes inaccessible elements. `InboxPage.test.tsx:228` runs
`within(getByRole('listitem'))` **while the dialog is open** — once the dialog is truly
modal, that throws.

**l. Escape side effects are lost.** Both Escape handlers are React `onKeyDown` on a plain
container (`ClarifyDialog.tsx:176`, `BucketPage.tsx:116`) and do more than close:
`BucketPage.tsx:118-119` also clears `purgeError`, and `ClarifyDialog.tsx:177` gates on
`!submitting`. A kit's built-in `onOpenChange(false)` runs neither — so Escape would cancel
mid-submit (untested) and leave a stale error (caught only by `BucketPage.test.tsx:263`).

**m. Refs that silently no-op.** Both focus moves go through refs typed to concrete DOM
elements (`BucketPage.tsx:25,147,61`; `ClarifyDialog.tsx:68,183,81`) and call `.focus()`
behind an optional chain. A kit `<Button>` that does not forward its ref leaves
`current === null` and `?.focus()` becomes a **silent no-op** — no error, no type failure,
nothing in the console.

**n. Auto-generated ids overwrite the aria wiring — invisible today.** Radix and most form
kits generate ids via `useId()` and auto-wire `aria-describedby`. Dropping in a kit `<Input>`
overwrites `aria-describedby="capture-error capture-status"` (`CaptureForm.tsx:101`) and
`aria-describedby="clarify-error"` (`ClarifyDialog.tsx:371`). **No test asserts any of these
attributes**, so this regression would be completely silent.

**o. Controlled inputs and the draft guarantee.** `CaptureForm.tsx:41-45` writes to
`sessionStorage` on every `onChange` and `:68-73` clears conditionally against the submitted
value. A kit `<Input>` that is uncontrolled, debounces, or normalises the event breaks the
PRD's "capture never loses an entry" guardrail. Pinned by `CaptureForm.test.tsx:224-251`
and `:149-166`.

**p. `disabled` vs `aria-disabled`.** There is **not one `toBeDisabled()` in the suite**. A
kit using `aria-disabled` + `pointer-events:none` instead of the attribute lets jsdom clicks
through (no CSS is applied, so user-event's pointer-events check always sees `auto`) —
double-submits become possible and nothing says a word.

**q. Animated mount/unmount.** Kits with exit transitions keep nodes mounted, breaking every
`not.toBeInTheDocument()` — e.g. `BucketPage.test.tsx:261`. Loud, therefore fine. The
dangerous variant: if the wizard's steps became a kit `Tabs`/`Stepper` that keeps panels
mounted and merely `hidden`, `queryByText` **still finds hidden text**, so
`ClarifyDialog.test.tsx:54-66, 77-90` go red — and "fixing" them by switching to
`getByRole(..., {hidden: false})` would quietly retire the FR-003 enforced-order guarantee.

**r. `<fieldset>`/`<legend>` is untested as semantics.** Seven sites in `ClarifyDialog.tsx`
(`:188…:345`). The tests only match the legend's **text**, so replacing
`<fieldset><legend>` with `<div><h4>` leaves **all 27 ClarifyDialog tests green** while the
question/answer grouping disappears from the accessibility tree.

**Two negative assertions that are already vacuum-prone** (nothing positive backs them):
`AppRoutes.test.tsx:36` (nav found by `aria-label="Buckets"` — rename it and the test passes
even if the nav *is* rendered to a stranger) and `BucketPage.test.tsx:102` (if Clarify became
a `DropdownMenuItem`, role `menuitem`, the FR-010 "no re-filing from a destination" guard goes
vacuously green).

### 3. The accessibility contract — 37 items, 16 unpinned

A full inventory with provenance is in the "Architecture Insights" section below. The
headline: of 37 behaviours a kit swap must preserve, **16 have no test at all**. The largest
single blind spot is `aria-invalid` + `aria-describedby` on both text inputs
(`CaptureForm.tsx:100-101`, `ClarifyDialog.tsx:370-371`) — four attributes, all introduced as
implementation-review fixes, **none asserted anywhere**.

The highest-leverage pre-work available: assert those four attributes *before* the swap. It
costs a few lines.

### 4. The visual starting point

`git log -- frontend/src/index.css` shows exactly two commits: the scaffold and one edit
(`c401365`) that added `--muted/--error/--success` and stripped the `#root` rules.
**Everything else is untouched Vite starter CSS**, which explains most of what follows.

**Every interactive control renders at 13.33px Arial.** Nothing sets `font: inherit`
(grep = 0 in `src/` and `index.html`), so while body text is 18px system-ui, both login
fields and their submit, the capture field and button, ~20 wizard buttons, the delegation
input, Log out and all three Trash controls compute to **13.3333px Arial** — the browser
default, at 74% of body size. This affects every screen and state. **Tailwind's Preflight
fixes it for free**; worth stating as a deliberate win rather than meeting it as a diff
surprise.

**The `h1` overlap reproduces at the default 1280px desktop width.** `:root`'s
`font: 18px/145%` computes the inherited line-height to an absolute **26.1px**, which every
element without its own `line-height` inherits regardless of its font size. Measured in
Chromium against the real markup:

| Element | font-size | line-height | Verdict |
|---|---|---|---|
| `:root` / `body` / `<p>` | 18px | 26.1px | — |
| **`h1`** | **56px** | **26.1px** | **broken** |
| **Clarify clock** (`ClarifyDialog.tsx:293`) | **36px** | **26.1px** | **broken** |
| `h2` | 24px | 28.32px | escapes — own `line-height:118%` (`index.css:85`) |
| `code` | 15px | 20.25px | escapes — own `line-height:135%` (`index.css:106`) |
| `<input>`, `<button>` | 13.33px Arial | — | escapes by never inheriting at all |

The Inbox `<h1>` sits in a flex row with no `flexWrap` and no `gap`
(`InboxPage.tsx:66-70`); "Signed in as…" squeezes it from a natural 429px to 343px, so it
**wraps to two lines that overlap by roughly 30px**. At 400px it is three lines. `BucketPage`
usually survives on one line because it is full-width and `margin: 32px 0` absorbs the
overflow — which is why the bug has looked intermittent. The
`@media (max-width:1024px){font-size:16px}` does not help: `16 × 1.45 = 23.2px`, still
absolute, still wrong.

**`--muted` is byte-identical to `--text`** in both blocks (light `#6b6375`, dark `#9ca3af`),
verified at the computed level. The `c401365` diff shows it was *born* equal. Six sites
expect de-emphasis and get none: `InboxList.tsx:25, 41, 58`, `ClarifyDialog.tsx:296`,
`BucketPage.tsx:169`, `LoginPage.tsx:45`. The deeper problem: `:root` sets
`color: var(--text)`, so **all body copy is already the muted grey** and only `--text-h` is
strong. The fix is not "make `--muted` lighter" — it is re-basing the default text colour so
there is somewhere below it to go.

**Five dead tokens**, defined twice and referenced nowhere: `--accent`, `--accent-bg`,
`--accent-border`, `--shadow`, `--social-bg`. The app has no accent colour and no elevation.

**Three dead starter rules**, not two: `#social .button-icon` (`:54-56`), `.counter`
(`:96-102`), and **the entire `code` rule plus `--code-bg`** (`:96-108`) — the app renders no
`<code>` element. Orphaned starter assets too: `src/assets/{hero.png,react.svg,vite.svg}` and
`public/icons.svg` (the social sprite `#social .button-icon` was written for).

**Other defects found:**

- `LoginPage.tsx:72` `color: 'crimson'` — the **only** hardcoded colour in the app, the only
  error message not using `--error`, and the only colour that fails contrast: 4.99:1 on light
  (scrapes AA) and **3.58:1 on dark (fails AA)**.
- The 1024px breakpoint corresponds to no layout change. The layout is a fixed `maxWidth: 640`
  (or 360) container, which only starts constraining at ~704px. The breakpoint was orphaned
  when `c401365` deleted the starter's `#root` rules.
- Always-mounted live regions are 0px tall when empty and 26.1px when filled, so every error
  and every "Saved to your Inbox." **jolts the page down one line**. The a11y reasoning for
  mounting them early is sound; the space simply is not reserved.
- Three page shells re-declare `fontFamily: 'system-ui, sans-serif'` — a *different* stack
  from `--sans`, and the inline one wins everywhere, so `--sans` is effectively unused.
- `--border: #e5e4e7` is 1.27:1 on white; the `InboxList` row dividers are near-invisible, so
  the list has no perceptible structure.

**Focus visibility**: there are **no custom focus styles and nothing suppresses the default**
— zero matches for `outline`, `:focus`, `focus-visible`, `:hover`, `:active` across
`frontend/src` and `index.html`. So today every control shows the browser's default
`:focus-visible` ring, and that is the app's only focus affordance. The risk is entirely
forward-looking: **Preflight does not remove focus rings, but `focus:outline-none` without a
paired `focus-visible:ring-*` does** — and no gate in this repo would catch it, because the
two focus tests assert `document.activeElement`, not that the focus is *visible*.

### 5. Styling surface to be ported

49 inline style objects (count verified exactly), **zero `className` attributes anywhere** —
this is a greenfield Tailwind install, not a migration off a partial setup. 22 objects carry
a colour; 21 use a token and exactly one is hardcoded.

Duplication that collapses into utilities: the page shell ×3 (`LoginPage.tsx:34`,
`InboxPage.tsx:58`, `BucketPage.tsx:93` — byte-identical apart from `maxWidth` 360 vs 640),
the fieldset reset ×7 (all in `ClarifyDialog`), the block-input rule ×2, `marginTop:0.5rem`
×2, `color:var(--error)` ×5, `color:var(--muted)` ×4.

Two things that are **not** mechanical swaps: the ad-hoc spacing scale
(`0.25 · 0.5 · 0.6 · 0.75 · 1 · 1.5 · 2` rem) contains `0.6rem` (`InboxList.tsx:33`) which has
**no Tailwind equivalent** and must be normalised; and font sizes are `em`-relative
(`0.8em`, `0.85em`, `0.9em`) while Tailwind's `text-*` scale is rem-based.

### 6. Screens and states to design

Route tree (`AppRoutes.tsx:13-26`): `/login`, `/` (protected), `/bucket/:bucket` (protected).
**There is no `path="*"`**, so any unmatched URL renders a blank white document — not a 404
page. `AuthProvider` also returns `null` during session rehydration
(`auth/AuthContext.tsx:59-61`), so a reload with a stored token shows a blank screen.

The full state inventory is 3 screens × ~40 reachable states — login (7), Inbox shell (7),
capture form (7), clarify wizard (15), bucket page (13). Notable ones the design must handle
rather than discover:

- Load error **with** locally captured items vs **without** (`InboxPage.tsx:114`) — these
  deliberately render differently, and `BucketPage.tsx:103-107` deliberately differs again.
- Purge **failed** leaves the confirmation open (`BucketPage.tsx:73-81`).
- `delegatedTo` reached via the tree vs via the quick-route (`ClarifyDialog.tsx:359-379` vs
  `:398-401`) — visually identical, behaviourally different.
- `/bucket/inbox` is a valid URL rendering a **second, read-only Inbox** with no capture form
  (`buckets.ts:27-29`); the nav points Inbox at `/`, so it is only reachable by typing.
- **No "Log out" and no "Signed in as" on any bucket screen** — they exist only in
  `InboxPage`'s header. Bucket screens also drop the app name entirely.
- **`ClarifyDialog` claims `role="dialog" aria-modal="true"` but renders as a plain inline
  `<section>`** — no overlay, no backdrop, no positioning, no focus trap. Everything behind it
  stays on screen and in the tab order. The accessibility contract and the visual presentation
  disagree *today*; the redesign has to pick one.

### 7. Dark-mode removal

Three edits: delete `index.css:36-57` (the whole `@media` block), change
`index.css:23` `color-scheme: light dark` → `light`, and fold `LoginPage.tsx:72`'s `crimson`
into the error token. **No component reads `matchMedia`, `prefers-color-scheme` or
`color-scheme`** (grep = 0), and **no test would break** (verified across all 9 test files for
`toHaveStyle`, `getComputedStyle`, `className`, `matchMedia`, `color-scheme`, `--muted`,
`--error`, `crimson` — zero hits).

**The trap**: deleting the `@media` block while leaving `color-scheme: light dark` produces a
state *worse than today*. On a dark-OS machine the page stays white (`--bg` is now only
defined light) while native form controls still render dark — and since every input and
button in this app is entirely UA-styled (§4), the whole interactive surface becomes
dark-on-white. **The two lines must change together.**

## Code References

- `frontend/src/index.css:22` — `font: 18px/145%`, the inherited-26.1px line-height defect
- `frontend/src/index.css:23` — `color-scheme: light dark`, half of the dark-mode trap
- `frontend/src/index.css:36-57` — the dark block to delete
- `frontend/src/index.css:54-56, 96-108` — three dead starter rules
- `frontend/src/items/InboxPage.tsx:66-70` — the flex header with no wrap/gap that causes the worst `h1` instance
- `frontend/src/items/ClarifyDialog.tsx:172-183` — `role="dialog"` on a plain inline `<section>`; the focusable `<h3>`
- `frontend/src/items/ClarifyDialog.tsx:293-295` — the clock, broken by the same line-height defect and pinned by an anchored regex
- `frontend/src/items/BucketPage.tsx:59-63, 146-147` — focus to the safe button, the highest-stakes contract item
- `frontend/src/items/BucketPage.tsx:116-121` — Escape that also clears `purgeError`
- `frontend/src/items/CaptureForm.tsx:110-115` — the always-mounted live regions that must render nothing when empty
- `frontend/src/auth/LoginPage.tsx:50-71` — the only screen relying on **wrapping** `<label>` with no `htmlFor`
- `frontend/src/auth/LoginPage.tsx:72` — `crimson`, the one hardcoded colour, fails AA on dark
- `frontend/src/items/InboxList.tsx:31-33` — the `<ul>/<li>` that `InboxPage.test.tsx:228` depends on
- `frontend/src/test/server.ts` — MSW handlers; `frontend/src/main.tsx:4` — the only `index.css` import

## Architecture Insights

**The contract a component-kit swap must preserve** — the top items, with what pins them.
`UNPINNED` means nothing catches a regression.

1. Revealing the discard confirmation moves focus to the **safe** button — `BucketPage.tsx:57-63,146-147` · `BucketPage.test.tsx:231-243`
2. The destructive button is visually distinct and never receives focus despite coming **first** in DOM order — `BucketPage.tsx:138-143` · **UNPINNED** (only the focus half)
3. Escape closes the confirmation **and** clears a stale purge error, inert while purging — `BucketPage.tsx:116-121` · `BucketPage.test.tsx:245-264`
4. Opening the wizard moves focus into it, onto the level-3 heading — `ClarifyDialog.tsx:80-82,183` · `ClarifyDialog.test.tsx:253`
5. Escape closes the wizard, inert while submitting — `ClarifyDialog.tsx:176-180` · `ClarifyDialog.test.tsx:256-261`
6. Every live region is mounted **before** it has text — 4 sites · partially pinned via `toBeEmptyDOMElement()`
7. The purge error region is a `role="alert"` named "Trash purge error" — `BucketPage.tsx:165` · `BucketPage.test.tsx:209,257,263`
8. Exactly one question is in the DOM at a time — absent, not hidden — `ClarifyDialog.tsx:187-357` · `ClarifyDialog.test.tsx:54-66,77-90`
9. `aria-current="page"` on the open bucket — `BucketNav.tsx:16` · `BucketPage.test.tsx:55` (×7)
10. A completed item is marked in **text** (`✓ Done`), never styling alone — `InboxList.tsx:41-43` · `InboxList.test.tsx:57`, `BucketPage.test.tsx:302`

**UNPINNED and therefore silently losable**: `aria-invalid` + `aria-describedby` on both
inputs; the dialog's accessible name via `aria-labelledby`; focus return to the capture input
after every submit; the `role="status"` on capture success and on the session-expired notice;
the countdown's deliberate *silence*; `<fieldset>/<legend>` as semantics; `<time dateTime>`;
`autoFocus` ×2; `aria-busy`; the three `<main>` landmarks; `maxLength`.

**Provenance matters here.** Most of these were not designed up front — they were
implementation-review fixes: focus-to-safe-button was `eight-bucket-views` F8, focus-into-
wizard was `guided-clarify-routing` F9, always-mounted live regions were
`testing-capture-durability` ph3 F6a, the error-cleared-on-step-change was
`two-minute-rule-timer` F10b. Losing them to a kit default would re-open findings the project
already paid to close.

### 8. Stack feasibility — measured, not inferred

**Tailwind v4 on Vite 8 is officially supported.** `@tailwindcss/vite@4.3.3` declares
`peerDependencies: { vite: '^5.2.0 || ^6 || ^7 || ^8' }`; the range was widened deliberately
by tailwindlabs/tailwindcss#19790, shipped in the v4.2.2 release notes ("Support Vite 8").
The project is on `vite@8.0.16`. `@tailwindcss/oxide` ships a `linux-x64-musl` prebuild, so
the Alpine Dockerfile is fine.

Install is two lines of config — the Vite plugin (not PostCSS; there is no
`postcss.config.js` today and adding one would be a second CSS pipeline for nothing) plus
`@import "tailwindcss";` as the **first rule** of `index.css`, which currently opens with
`:root {`. No `tailwind.config.js` — v4 is CSS-first and theme tokens live in `@theme {}`.

**Cascade order is safe**: Tailwind's entry stylesheet is `@layer theme, base, components,
utilities;` with Preflight in `layer(base)`, and unlayered CSS beats every layer — so
Preflight cannot clobber the existing `index.css` rules regardless of source order.

**Three premises this research started with were stale.** All npm-verified:

- `@base-ui-components/react` is **deprecated** ("Package was renamed to @base-ui/react").
  The live package is `@base-ui/react@1.8.0`, **stable GA**.
- **shadcn/ui's default primitive base has been Base UI, not Radix, since July 2026.**
  Picking shadcn *is* picking Base UI. Radix is opt-in via `shadcn init -b radix`.
- shadcn no longer installs `clsx` + `tailwind-merge`; it installs the `cn` package.

**The decisive measurement — every kit breaks `InboxPage.test.tsx:228`, identically.** Run on
this exact stack with a modal open and content outside it:

| | Base UI 1.8.0 | Radix 1.6.7 | HeroUI 3.2.5 | Ark UI |
|---|---|---|---|---|
| `queryByRole('listitem')` behind the dialog | **NOT FOUND** | **NOT FOUND** | **NOT FOUND** | **NOT FOUND** |
| `queryByText('row behind')` | found | found | found | found |
| attribute applied outside | `aria-hidden` + `data-base-ui-inert` | `aria-hidden` + `data-aria-hidden` | `aria-hidden` | `aria-hidden` |

Mechanism confirmed in shipped source: Base UI vendors a modified `aria-hidden` package;
`@radix-ui/react-dialog` depends on `aria-hidden` outright. And confirmed against this repo's
own `@testing-library/dom@10.4.2` that an ancestor `aria-hidden="true"` makes
`getByRole('listitem')` unfindable (ByRole's `hidden` defaults to `false`), while the native
`inert` attribute does **not** — jsdom 30 has no `inert` at all. The only escape is
`modal={false}`, which discards the very accessibility behaviour a kit would be adopted for.

**shadcn's CLI hard-fails on this repo.** Run against a byte-copy of the real config,
`shadcn init` wrote nothing: *"No Tailwind CSS configuration found"* and *"Could not find
valid path aliases"*. Adding `baseUrl`/`paths` to `tsconfig.app.json` alone makes validation
pass and then silently writes every file into a **literal directory named `@`** at the repo
root — because the CLI validates by walking the project but resolves write paths off the
**root** `tsconfig.json`, which in this solution-style setup has no `compilerOptions` at all.
Both files need the aliases.

**jsdom 30.0.1 capability probe** (run against the installed copy): `matchMedia`,
`ResizeObserver`, `IntersectionObserver`, `scrollIntoView`, `hasPointerCapture`,
`setPointerCapture`, `animate`, `showModal` and `inert` are all **missing**;
`PointerEvent`, `MutationObserver`, `requestAnimationFrame`, `getComputedStyle` are present.
`grep -r matchMedia node_modules/jsdom/` returns zero hits.

Polyfills required per option, measured with `setupFiles: []`:

| Option | jsdom polyfills needed |
|---|---|
| **Tailwind only** | **none** |
| Base UI 1.8.0 | **none** — Dialog, Select, Popover, DropdownMenu, Tooltip all pass |
| HeroUI 3.2.5 | none for modal/select/popover flows |
| Radix 1.6.7 | two: `Element.prototype.hasPointerCapture` **and** `.scrollIntoView` |
| Ark UI / Park UI | `ResizeObserver` — Select throws an **unhandled rejection**, which CI fails on |

Park UI is out on its own terms: grepping the `@park-ui/cli@1.0.1` tarball finds **zero**
occurrences of "tailwind" (only "panda"), and its Tailwind plugin is frozen at 2024.
HeroUI drags in `@adobe/react-spectrum` transitively — 62 packages, 151 MB installed,
tree-shaken from the bundle but paid on every CI cold install.

**Tailwind classes are inert under jsdom**, traced three ways: jsdom does no layout at all;
Vitest's `css` option defaults to off and replaces CSS modules with empty strings
(`vitest:css-disable` at `enforce: "pre"`, `vitest:css-empty-post` at `enforce: "post"`);
and `index.css` is imported only by `main.tsx`, which no test imports. `@tailwindcss/vite`'s
plugins are all `enforce: "pre"`, so the `post` plugin empties the module either way.
**The one way to break this is setting `test.css: true`** — that reproduces
tailwindlabs/tailwindcss#18952, "Could not parse CSS stylesheet" on jsdom 27+. Do not set it.

## Historical Context (from prior changes)

- `context/foundation/test-plan.md:74` — Risk #5 already names this slice's unknowns:
  *"Which screens and states are worth pinning; whether dark mode is a supported state or
  half-built; what the dead scaffold CSS currently affects."* Response guidance: a
  **deterministic visual snapshot on 2–3 states, not a vision model**; anti-pattern: pinning
  whole pages so every copy change goes red.
- `context/foundation/test-plan.md:89` — rollout **Phase 4 "Browser layer and visual
  regression"** covers Risk #5 and is `not started`. This slice proceeds without it by
  decision.
- `context/archive/2026-09-14-eight-bucket-views/reviews/impl-review.md` — F8 (focus to the
  safe button), F9 (clear `purgeError` on cancel), F4 (render the delegation note).
- `context/archive/2026-09-14-guided-clarify-routing/reviews/impl-review.md` — F9 (focus into
  the wizard, Escape to cancel, `role="dialog"`).
- `context/archive/2026-09-14-testing-capture-durability/reviews/impl-review-phase-3.md:157`
  — F6a, the rule that live regions must be mounted before they have text.
- `context/foundation/lessons.md` — *"A test is not coverage until deliberate breakage has
  reddened it."* Directly applicable: 16 contract items are decoration until something can
  redden for them.

## Research conclusion — the recommendation the evidence supports

**Tailwind v4 alone. Keep the hand-written components. Do not adopt a component kit.**

This contradicts the direction chosen before research began, so it is stated with the three
measurements behind it rather than as a preference:

1. **Every kit breaks the same real test, the same way** (§8). Base UI, Radix, HeroUI and Ark
   all `aria-hidden` the background behind a modal, which makes
   `InboxPage.test.tsx:228`'s `within(getByRole('listitem'))` unfindable while the clarify
   dialog is open. The only escape is `modal={false}`, i.e. giving up the behaviour the kit
   was for.
2. **The kit would remove guarantees this project already paid to get.** `ClarifyDialog` is a
   deliberately inline `<section role="dialog">` whose test asserts a level-3 heading takes
   focus; a kit dialog focuses its own wrapper, and Base UI does not set `aria-modal` at all
   (measured `null`). `BucketPage`'s focus-to-the-safe-button is product judgement no kit
   provides — and under jsdom HeroUI's focus restore does not even fire. These are
   implementation-review fixes from S-02 and S-05 being traded for defaults.
3. **Tailwind alone costs nothing in the suite.** There is not one `className` in `src/`
   today, so Tailwind is purely additive: inline styles become classes at any pace, and the
   JSX structure — roles, labels, `aria-*` — never changes. All 113 `getByRole` and 20
   `getByLabelText` queries are untouched. Preflight resets margins, heading weights and list
   bullets; it changes no roles and no accessible names, and it **fixes the 13.33px controls
   for free**.

What Tailwind alone still delivers against S-10's outcome: the 13.33px Arial controls, the
overlapping `h1` and clock, the five dead tokens, the three dead starter rules, the
`--muted == --text` collapse, the ad-hoc spacing scale, the missing accent and elevation, the
near-invisible row dividers, the unreserved live-region space, and the layout-shift jolt. That
is the whole defect list from §4 — none of it needs a kit.

**If primitives are wanted later**, the ranking from this evidence is: **Base UI directly**
(`npm i @base-ui/react` — no CLI, no `components.json`, no tsconfig surgery, zero jsdom
polyfills, opt-in portals) for one or two components; **shadcn** only with the four manual
config edits done first; **not Radix** (two `Element.prototype` shims); **not Park UI** (no
Tailwind path, ~10 months without a release).

## Open Questions

1. **Direction, now that the evidence is in.** The pre-research decision was "Tailwind + a
   ready-made kit". The measurements say the kit is where 100% of the risk lives and that it
   subtracts guarantees rather than adding them. This needs an explicit confirm-or-change
   before planning.
2. **Does the `ClarifyDialog` become a real modal?** It claims `aria-modal="true"` today and
   is not one — no overlay, no backdrop, no focus trap. Either make it one (and accept the
   `InboxPage.test.tsx:228` break), or drop the false claim. Doing neither leaves the
   accessibility contract lying about the UI.
3. **Do the four unpinned aria attributes get assertions before any markup moves?**
   Recommended regardless of direction — `aria-invalid` and `aria-describedby` on both inputs
   are the largest single blind spot, all four came from review fixes, and none is asserted.
4. **The three screens with no design at all**: the missing `path="*"` route (any unknown URL
   is a blank white page), the blank screen during session rehydration, and bucket screens
   having no "Log out" and no app name. These are design work this slice will surface whether
   or not it plans for them.
5. **Unrelated, found in passing**: `frontend/Dockerfile` builds on `node:20-alpine` while
   `package.json` declares `engines.node >= 22.12`, CI uses Node 22, and `vitest@5` requires
   `^22.12 || ^24 || >=26`. Nothing is broken today — Node 20 squeaks past Vite 8's range —
   but the image is a version behind everything else.
