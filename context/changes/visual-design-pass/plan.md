# Visual design pass (S-10) — Implementation Plan

## Overview

Adopt Tailwind v4 as the SPA's only styling layer and use it to make the app read as a
considered product: a real visual hierarchy, one accent used deliberately, a working focus
treatment, and the twelve measured defects gone. The hand-written components stay — no
component kit — so the JSX structure never moves and the 91 existing tests are untouched.

## Current State Analysis

Fully measured in `research.md`. The parts that shape this plan:

- **49 inline `style={{}}` objects, zero `className`** anywhere in `src/`. Tailwind is
  therefore purely additive, not a migration off a partial setup.
- **Every input and button renders at 13.33px Arial** because nothing sets `font: inherit`.
  This is the single largest visual defect and Preflight fixes it for free.
- **`:root { font: 18px/145% }`** makes the inherited line-height an absolute 26.1px, so the
  56px `h1` overlaps itself **at the default 1280px desktop width** (the Inbox flex header
  squeezes it from 429px to 343px, wrapping it to two lines), and the 36px two-minute clock
  sits in a 26px box.
- **`--muted` is byte-identical to `--text`** in both themes, so nothing is de-emphasised.
  Worse, `:root` sets `color: var(--text)`, meaning body copy is *already* the muted grey —
  the fix is re-basing the text colour, not lightening `--muted`.
- **Five dead tokens** (`--accent`, `--accent-bg`, `--accent-border`, `--shadow`,
  `--social-bg`) and **three dead starter rules** (`#social .button-icon`, `.counter`, and the
  whole `code` block plus `--code-bg`).
- **No focus styles at all** — the browser default ring is the app's only focus affordance,
  and two implementation-review fixes depend on it being visible.
- **The suite cannot observe appearance.** No visibility assertion exists anywhere, and no CSS
  loads in the test environment (`index.css` is imported only by `main.tsx`, which no test
  mounts). All 91 tests pass against a zero-height container or a modal nobody can click.
- `ClarifyDialog` declares `role="dialog" aria-modal="true"` while rendering as a plain inline
  `<section>` — no overlay, no backdrop, no focus trap.
- `AppRoutes.tsx` has **no `path="*"`**, so any unknown URL renders a blank white document;
  `AuthProvider` returns `null` during session rehydration, so a reload shows a blank screen.

## Desired End State

Opening the app shows an interface with an obvious hierarchy: a page ground that cards sit on,
a three-level text scale where muted text is actually muted, one accent that marks the primary
action and the open bucket, a visible focus ring in that accent, and controls that share the
app's typeface rather than the browser's. No heading overlaps itself at any width. An unknown
URL shows a page rather than nothing. Every screen carries the same header.

Verified by walking all reachable states from `research.md` §6 in a real browser at the end of
each phase, and by all six CI gates staying green throughout.

### Key Discoveries

- `@tailwindcss/vite@4.3.3` declares `peerDependencies: { vite: '^5.2.0 || ^6 || ^7 || ^8' }`
  — Vite 8 is explicitly supported (tailwindlabs/tailwindcss#19790).
- Tailwind's entry stylesheet is `@layer theme, base, components, utilities;` and unlayered
  CSS beats every layer, so **Preflight cannot clobber the existing `index.css` rules**
  regardless of source order.
- Tailwind classes are **inert under jsdom** — verified three ways (no layout in jsdom;
  Vitest's `css` option defaults off and empties CSS modules; `index.css` never enters the
  test module graph). **Never set `test.css: true`** — that reproduces
  tailwindlabs/tailwindcss#18952.
- `--muted` at `InboxList.tsx:25,41,58`, `ClarifyDialog.tsx:296`, `BucketPage.tsx:169`,
  `LoginPage.tsx:45` — six sites expecting de-emphasis that currently get none.
- `LoginPage.tsx:72` `color: 'crimson'` is the only hardcoded colour and the only one failing
  contrast (4.99:1 light, **3.58:1 dark**).
- The `InboxPage.tsx:66-70` header flex row has no `flexWrap` and no `gap` — the direct cause
  of the worst `h1` instance.

## What We're NOT Doing

- **No component kit.** Measured and rejected — see `research.md` §8 and `change.md`.
- **No dark mode.** Light only, permanently.
- **No visual-regression pin** (test-plan Phase 4 stays `not started`).
- **No `/bucket/inbox` change** — it still renders a second read-only Inbox. Out of scope by
  decision; it is a behaviour fix wearing a styling costume.
- **No new features, no behaviour changes** beyond the three surfaces named in scope and the
  `aria-modal` removal. No re-filing, no per-item delete, no metadata editing.
- **No JSX structural changes** — no new wrappers around matched text, no `<ul>`/`<li>`
  replacements, no moving Clarify into a menu. The 91 tests are the contract; see "Critical
  Implementation Details".
  - **Amended during Phase 2 — one heading-level exception.** This entry originally also
    forbade changed heading levels. Phase 2's shared frame forced the question: a bucket page's
    single `<h1>` must name the bucket (seven tests assert exactly one, by level, with no
    name), so the product name cannot be a heading anywhere. The Inbox's
    `<h1>Getting Shit Done</h1>` therefore became plain text in the frame and its
    `<h2>Inbox</h2>` was promoted to `<h1>`, making every screen's one `<h1>` name that screen.
    Nothing pinned the old content. Recorded here rather than left in a commit message, so a
    reader of the plan alone does not conclude it was prohibited.
- **No `tailwind.config.js`** — v4 is CSS-first.

## Implementation Approach

Vertical, one route per phase, because that is what was chosen — with the consequence stated
plainly: **Phase 1 must carry the foundation**, since eight of the twelve defects live in
`index.css` and the Inbox cannot be styled without the token layer being right. Phases 2 and 3
inherit that foundation and only touch their own screens.

Each phase ends with a browser walk of every state from `research.md` §6 that the phase
touched, reported back before the next phase starts. That walk is the only thing standing
between this slice and a silent visual regression.

## Critical Implementation Details

**The test suite is the structural contract, and it cannot tell you when you break it
visually.** Three specific traps, all measured:

- `getByText` matches only an element's **direct** text children. `BucketPage.tsx:169-171`
  renders `Discarded {n} {word}.` as three sibling text nodes in one `<p>`; wrapping the
  number in a `<span>` or `<strong>` leaves the paragraph's direct text as `"Discarded ."` and
  `findByText(/discarded 4 items/i)` matches nothing. The same applies to `'✓ Done'`
  (`InboxList.tsx:41-43`) and the `m:ss` clock (`ClarifyDialog.tsx:293-295`, matched by an
  anchored `/^\d:\d{2}$/`).
- `InboxPage.test.tsx:228` calls `within(screen.getByRole('listitem'))` — **singular**. Adding
  any other `<li>` to the Inbox screen, including a nav rendered as `<ul><li>`, breaks it.
- `BucketPage.test.tsx:84` asserts exactly **8 links** on a bucket page. A logo link or a
  skip-link in the shared header takes it to 9.

**Dark-mode removal is two lines that must move together.** Deleting the `@media` block while
leaving `color-scheme: light dark` is *worse than today*: the page stays white while native
controls render dark — and since every control in this app is entirely UA-styled, the whole
interactive surface would go dark-on-white.

**Preflight removes list bullets and resets heading sizes**, which is wanted here, and it does
**not** touch `outline` — so focus rings survive by default. The forward risk is writing
`focus:outline-none` without a paired `focus-visible:ring-*`; no gate in this repo would catch
that, because the two focus tests assert `document.activeElement`, not visibility.

---

## Phase 1: Foundation and the Inbox route

### Overview

Install Tailwind, establish the token layer and the global fixes, then style everything on
`/` — the shell, the capture form, the item list, and the clarify wizard. Opens with the
aria assertions, before any style moves.

### Changes Required

#### 1. Pin the aria attributes that are about to become load-bearing

**File**: `frontend/src/items/CaptureForm.test.tsx`, `frontend/src/items/ClarifyDialog.test.tsx`

**Intent**: `aria-invalid` will drive the error styling of both text inputs, so its
disappearance stops being only an accessibility regression and becomes a silent visual one.
Four attributes introduced as review fixes currently have no test at all.

**Contract**: assert `aria-invalid` flips to `true` when each input's error is showing and is
absent/false otherwise, and that `aria-describedby` names the live region ids the component
renders (`capture-error capture-status`, `clarify-error`). Existing tests already reach both
error states; extend them rather than adding new cases.

#### 2. Install Tailwind v4

**File**: `frontend/package.json`, `frontend/vite.config.ts`

**Intent**: add Tailwind as the styling layer via the Vite plugin, not PostCSS — there is no
`postcss.config.js` today and adding one would be a second CSS pipeline for nothing.

**Contract**: `npm i tailwindcss@4 @tailwindcss/vite@4`; add `tailwindcss()` to the existing
`plugins` array. Do **not** add a `test.css` key.

#### 3. The token layer, the global fixes, and the dark-mode removal

**File**: `frontend/src/index.css`

**Intent**: replace the scaffold's token set with one that expresses the chosen register —
calm neutrals, one accent, three real text levels — fix the inherited-line-height defect at
its root, and take light-only.

**Contract**: `@import "tailwindcss";` must be the **first rule in the file** (CSS requires
`@import` before all other rules; the file currently opens with `:root {`). Tokens move into an
`@theme {}` block, which v4 emits as real CSS variables. The dark `@media` block is deleted and
`color-scheme` becomes `light` in the same edit. `font: 18px/145%` becomes a 16px base with a
**unitless** line-height so descendants compute their own. The three dead starter rules and
their tokens go.

```css
/* The @custom-variant keeps any stray `dark:` utility permanently inert, since no
   `.dark` class is ever applied — cheap insurance against one being copied in later. */
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
```

Token roles to establish (exact values are set here and **verified for contrast in the browser
during this phase's manual check**, because `crimson` proves an unverified colour ships):

| Role | Purpose | Bar |
|---|---|---|
| `ground` | page background the cards sit on | must read as distinct from `surface` |
| `surface` | card / panel background | — |
| `border` | dividers and card edges | perceptibly stronger than today's 1.27:1 |
| `text-strong` | titles, headings | ≥ 7:1 on `surface` |
| `text` | body copy | ≥ 4.5:1 on `surface` |
| `text-muted` | timestamps, empty states, status | ≥ 4.5:1, and **visibly lighter than `text`** |
| `accent` | primary action, open bucket, focus ring | ≥ 4.5:1 as text on `surface` |
| `error` / `success` | keep the current values — both verified at 6.54:1 | — |

#### 4. The Inbox shell and the shared page frame

**File**: `frontend/src/items/InboxPage.tsx`

**Intent**: convert the page shell to classes, fix the header row that causes the `h1`
overlap, and establish the frame that Phases 2 and 3 will reuse.

**Contract**: the header row gains wrapping and a gap so the `h1` is never squeezed below its
natural width. The shell keeps its single `<h1>` — **a shared frame introduced in Phase 2 must
not add another**, because seven tests assert exactly one `<h1>` per page. `<main>` stays.

#### 5. Capture form, item list

**File**: `frontend/src/items/CaptureForm.tsx`, `frontend/src/items/InboxList.tsx`

**Intent**: style the capture affordance as the screen's primary action, and give list rows
the card treatment with the item title, note, delegation note, done marker and timestamp in
three distinct text levels.

**Contract**: **no new elements wrapping matched text** — the title, `✓ Done`, `Waiting on:`
and the timestamp keep their current text-node shape. `<ul>`/`<li>` stay. The `<time
dateTime>` stays. The always-mounted live regions keep rendering **nothing** when empty
(`{error ?? ''}` produces zero child nodes, and three tests assert `toBeEmptyDOMElement()`) —
reserve their space with a min-height class instead, which also fixes the one-line layout jolt
every message currently causes.

#### 6. The clarify wizard

**File**: `frontend/src/items/ClarifyDialog.tsx`

**Intent**: present the wizard as a distinct elevated panel, and stop the accessibility
contract lying — it claims `aria-modal="true"` while rendering inline with no overlay, no
backdrop and no focus trap.

**Contract**: remove the `aria-modal` attribute; keep `role="dialog"`, `aria-labelledby`, the
focusable `tabIndex={-1}` heading and the Escape handler. The heading stays an **`<h3>`** and
must still take focus on mount — one test asserts both. The seven `<fieldset>`/`<legend>`
pairs stay as fieldsets. The two-minute clock keeps its digits as a **single text node in one
element** with no sibling text. Every step button keeps `type="button"`; the delegation form
keeps its native submit.

### Success Criteria

#### Automated Verification

- Frontend tests pass: `cd frontend && npm run test` (91 + the new aria assertions)
- Build and types pass: `cd frontend && npm run build`
- Lint passes: `cd frontend && npm run lint`
- Backend gates unaffected: `php artisan test`, `./vendor/bin/pint --test`

#### Manual Verification

- Walk every Inbox state from `research.md` §6 in a browser: loading, empty, populated, load
  error with and without local items, capture idle / restored draft / submitting / validation
  error / server error / success, and all 15 wizard steps including the running and expired
  timer
- Confirm no heading or clock overlaps itself at 1280px, 1024px and 400px
- Confirm every input and button now uses the app typeface, not 13.33px Arial
- Confirm the focus ring is visible on every control, including the wizard heading, and does
  not appear on mouse click
- Confirm muted text is visibly lighter than body text
- **Confirm the card shadows on list rows do not read as noise** — this is the known risk of
  the chosen surface treatment, and the list is the main screen
- Verify each token's contrast against the bar in the table above

---

## Phase 2: Bucket screens and the shared frame

### Overview

Style the eight bucket views, the bucket navigation, and the destructive Trash affordance —
and give every screen the same header, which today exists only on the Inbox.

### Changes Required

#### 1. The shared header

**File**: `frontend/src/items/BucketPage.tsx`, `frontend/src/items/InboxPage.tsx` (+ a shared
component if the duplication warrants one)

**Intent**: bucket screens carry neither the app name nor "Log out" — the frame simply stops
existing once you leave the Inbox.

**Contract**: the frame must **not** introduce an `<h1>` of its own (seven tests assert
exactly one `<h1>`, and on a bucket page it must name the bucket) and must **not** add a link
(one test asserts exactly 8 links on a bucket page). The app name therefore goes in
non-heading, non-link markup.

#### 2. Bucket navigation

**File**: `frontend/src/items/BucketNav.tsx`

**Intent**: make the open bucket obvious through the accent rather than through weight alone.

**Contract**: `aria-current="page"` stays on the active link — seven tests assert it — and
every link keeps a real `href`. Do not render the nav as `<ul>/<li>`: it shares a screen with
`InboxPage.test.tsx:228`'s singular `getByRole('listitem')`.

#### 3. The Trash purge

**File**: `frontend/src/items/BucketPage.tsx`

**Intent**: make the irreversible action look irreversible while the safe choice stays the
one holding focus.

**Contract**: the destructive button stays **first in DOM order** with the safe button second
and focused — that split is deliberate (S-05 F8). Both alert regions keep `role="alert"` plus
their `aria-label`s ("Discard confirmation", "Trash purge error"), and the purge-error region
keeps rendering nothing when empty. `Discarded {n} {word}.` keeps its three sibling text nodes
in one element.

### Success Criteria

#### Automated Verification

- Frontend tests pass: `cd frontend && npm run test`
- Build and lint pass: `cd frontend && npm run build && npm run lint`

#### Manual Verification

- Walk all eight buckets: loading, empty, populated, load error
- Walk the Trash sequence: idle, confirming, purging, purge failed, purged
- Confirm the destructive button reads as dangerous and that focus still lands on "Keep them"
- Confirm Delegation shows "Waiting on:" and Next Actions shows "✓ Done"
- Confirm the header is identical on every screen and that "Log out" works from a bucket view

---

## Phase 3: Login, the missing surfaces, and close-out

### Overview

Style the login screen, add the two surfaces that render nothing today, and finish: gates, a
full state sweep, and removal of the orphaned scaffold assets.

### Changes Required

#### 1. Login

**File**: `frontend/src/auth/LoginPage.tsx`

**Intent**: style the first screen anyone sees, and remove the `crimson` that is both the
app's only hardcoded colour and its only contrast failure.

**Contract**: the error paragraph adopts the error token. The email and password fields rely
on **wrapping `<label>` with no `htmlFor`/`id`** — six assertions depend on that nesting, so
the label must keep wrapping its input. While here, give that error paragraph a `role` — it is
the one failure message in the app that is never announced.

#### 2. The unmatched-route page

**File**: `frontend/src/AppRoutes.tsx` (+ a new component)

**Intent**: any unknown URL currently renders a blank white document. A typo in the address
bar should not look like the application crashed.

**Contract**: a `path="*"` route rendering a page that names what happened and offers a way
back to the Inbox. It sits **outside** `ProtectedRoute`, so it must not leak that an account
exists — it says the page was not found, nothing about sessions.

#### 3. The session-rehydration state

**File**: `frontend/src/auth/AuthContext.tsx`

**Intent**: `AuthProvider` returns `null` while `me()` is in flight, so every reload with a
stored token shows a blank screen for the length of a round trip.

**Contract**: render a minimal loading surface instead of `null`. It must not flash
distractingly on a fast response, and it must not be mistaken for the logged-out state.

#### 4. Remove the orphaned scaffold

**File**: `frontend/src/assets/`, `frontend/public/icons.svg`

**Intent**: `hero.png`, `react.svg`, `vite.svg` and the social-icon sprite are referenced by
nothing — not by `src/`, not by `index.html`.

**Contract**: delete. Confirm with a grep across `src/`, `public/` and `index.html` first.

### Success Criteria

#### Automated Verification

- All six gates green: `cd frontend && npm run test && npm run build && npm run lint`, and
  `php artisan test`, `./vendor/bin/phpstan analyse --memory-limit=512M`,
  `./vendor/bin/pint --test`
- No orphaned asset references: grep returns nothing for the deleted files

#### Manual Verification

- Walk login: idle, session-expired notice, submitting, 401 error, other error
- Visit an unknown URL signed in and signed out; confirm both show the page and neither leaks
  account existence
- Hard-reload with a stored token and confirm the rehydration state appears and does not flash
- **Full sweep**: walk every state in `research.md` §6 across all three routes at 1280px and
  400px, confirming nothing regressed from Phases 1 and 2

---

## Testing Strategy

### Unit tests

The four aria attributes that become load-bearing for the error styling (Phase 1, change 1).
Nothing else is added: this slice changes no behaviour, and the existing 91 tests already pin
the structural contract that the restyle must not move.

### What is deliberately not tested

Appearance. It cannot be — measured in `research.md`: no CSS loads in the test environment and
no visibility assertion exists. **The per-phase browser walk is the verification**, not a
supplement to one. This is the accepted cost of declining the visual pin, recorded so nobody
later mistakes a green suite for a correct screen.

### Manual testing steps

Per phase, above. The state inventory in `research.md` §6 is the checklist — it exists so a
state is discovered on paper rather than in production.

## Performance Considerations

Tailwind ships only the utilities actually used; the current bundle is 249 kB / 79 kB gzipped,
and the CSS delta should be small. Worth a glance at the build output in Phase 3, not a budget.

## References

- Research: `context/changes/visual-design-pass/research.md`
- Decisions and their revision: `context/changes/visual-design-pass/change.md`
- Risk #5 and rollout Phase 4: `context/foundation/test-plan.md:74,89`
- The accessibility fixes this must preserve:
  `context/archive/2026-09-14-eight-bucket-views/reviews/impl-review.md` (F8, F9),
  `context/archive/2026-09-14-guided-clarify-routing/reviews/impl-review.md` (F9),
  `context/archive/2026-09-14-testing-capture-durability/reviews/impl-review-phase-3.md:157` (F6a)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.
> Do not rename step titles.

### Phase 1: Foundation and the Inbox route

#### Automated

- [x] 1.1 Frontend tests pass, including the new aria assertions — fbe09ae
- [x] 1.2 Build and types pass — fbe09ae
- [x] 1.3 Lint passes — fbe09ae
- [x] 1.4 Backend gates unaffected — fbe09ae

#### Manual

- [x] 1.5 Walk every Inbox state from research §6 in a browser — fbe09ae
- [x] 1.6 No heading or clock overlaps at 1280px, 1024px, 400px — fbe09ae
- [x] 1.7 Every input and button uses the app typeface — fbe09ae
- [x] 1.8 Focus ring visible on every control including the wizard heading, absent on mouse click — fbe09ae
- [x] 1.9 Muted text visibly lighter than body text — fbe09ae
- [x] 1.10 Card shadows on list rows do not read as noise — fbe09ae
- [x] 1.11 Every token verified against its contrast bar — fbe09ae

### Phase 2: Bucket screens and the shared frame

#### Automated

- [x] 2.1 Frontend tests pass — e55388f
- [x] 2.2 Build and lint pass — e55388f

#### Manual

- [x] 2.3 Walk all eight buckets: loading, empty, populated, load error — e55388f
- [x] 2.4 Walk the Trash sequence: idle, confirming, purging, failed, purged — e55388f
- [x] 2.5 Destructive button reads as dangerous; focus still lands on "Keep them" — e55388f
- [x] 2.6 Delegation shows "Waiting on:", Next Actions shows "✓ Done" — e55388f
- [x] 2.7 Header identical on every screen; Log out works from a bucket view — e55388f

### Phase 3: Login, the missing surfaces, and close-out

#### Automated

- [x] 3.1 All six gates green — 56da13c
- [x] 3.2 No orphaned asset references remain — 56da13c

#### Manual

- [x] 3.3 Walk login: idle, session-expired, submitting, 401, other error — 56da13c
- [x] 3.4 Unknown URL signed in and signed out; neither leaks account existence — 56da13c
- [x] 3.5 Hard-reload with a stored token shows the rehydration state without flashing — 56da13c
- [x] 3.6 Full sweep of every state across all three routes at 1280px and 400px — 56da13c
