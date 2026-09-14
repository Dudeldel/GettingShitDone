# Visual design pass (S-10) — Plan Brief

> Full plan: `context/changes/visual-design-pass/plan.md`
> Research: `context/changes/visual-design-pass/research.md`

## What & Why

The GSD SPA has never been designed — it is the Vite starter's stylesheet plus 49 inline style
objects accumulated across five feature slices. Every input and button in the product renders
at 13.33px Arial, the `h1` overlaps itself at the default desktop width, and five of the
sixteen design tokens are defined and referenced nowhere. This slice adopts Tailwind v4 and
uses it to give the app a real visual hierarchy, one deliberate accent, a visible focus
treatment, and none of the twelve measured defects.

## Starting Point

Three routes (`/login`, `/`, `/bucket/:bucket`), seven styled components, one 109-line
stylesheet that `git log` shows is two commits old — the scaffold plus one edit. Zero
`className` anywhere. The 91-test frontend suite pins structure and behaviour thoroughly and
**cannot observe appearance at all**: no CSS loads in the test environment and no visibility
assertion exists.

## Desired End State

A page ground that cards sit on, three genuinely distinct text levels, one accent marking the
primary action and the open bucket, a focus ring in that accent on every control, and controls
that share the app's typeface. No heading overlaps itself at any width. An unknown URL shows a
page instead of nothing. Every screen carries the same header.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| Styling approach | Tailwind v4 alone, no component kit | Every kit measured (Base UI, Radix, HeroUI, Ark) `aria-hidden`s the background behind a modal and breaks the same existing test; a kit also subtracts focus guarantees S-02 and S-05 reviews added deliberately | Research |
| Dark mode | Out — light only | Half-built today; finishing it doubles the design surface for a single-user app | Plan (pre-research) |
| Visual-regression pin | Not in this slice | Test-plan Phase 4 stays `not started`; verification is a per-phase browser walk | Plan (pre-research) |
| Visual register | Calm and restrained, one accent used sparingly | Fastest route to something that reads as intentional without a designer; suits a focus tool | Plan |
| Type scale | 16px base | Tailwind's scale is calibrated to it, and the main screen is lists | Plan |
| Surfaces | Cards with subtle elevation | Chosen against the recommendation; the noise risk on list rows is an explicit manual check | Plan |
| Focus | Custom `focus-visible` ring in the accent | Two review fixes depend on focus being *visible*, and nothing in CI would catch its loss | Plan |
| `ClarifyDialog` | Drop the false `aria-modal`, stay inline as an elevated panel | The contract lies today; a hand-rolled focus trap is the wrong thing to write under a deadline | Plan |
| New surfaces | 404 route, session-rehydration state, shared header | All three render nothing or are missing today | Plan |
| Pre-work | Assert the four unpinned aria attributes first | `aria-invalid` becomes load-bearing for the error styling, so losing it becomes a silent *visual* regression too | Plan |

## Scope

**In scope:** Tailwind v4 install · token layer and the twelve measured defects · all three
routes restyled · `path="*"` page · session-rehydration state · shared header with Log out ·
`aria-modal` removal · four aria assertions · orphaned scaffold assets deleted

**Out of scope:** any component kit · dark mode · visual-regression pin · `/bucket/inbox`
duplicate-Inbox behaviour · any feature or behaviour change · any JSX structural change

## Architecture / Approach

Tailwind is purely additive here — with zero `className` in `src/` today, inline styles become
classes at any pace and the JSX structure never has to move, which is what keeps the 91 tests
untouched. Tokens live in a v4 `@theme {}` block (CSS-first; no config file). Preflight fixes
the 13.33px controls for free and cannot clobber existing rules, because unlayered CSS beats
every layer.

Phasing is vertical, one route per phase — with the consequence stated: **Phase 1 carries the
foundation**, since eight of the twelve defects are global and the Inbox cannot be styled
before the token layer is right.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Foundation + Inbox route | Tailwind, tokens, global defect fixes, shell, capture form, item list, clarify wizard | Largest phase; a wrong token choice propagates to everything built after it |
| 2. Bucket screens + shared frame | Eight bucket views, nav, Trash destructive affordance, header on every screen | The frame must add neither an `<h1>` nor a link — 14 tests assert exact counts |
| 3. Login, missing surfaces, close-out | Login, `path="*"`, rehydration state, full sweep, dead assets removed | The 404 route sits outside auth and must not leak that an account exists |

**Prerequisites:** S-01, S-02, S-05 shipped (the screens to design must exist — they do).
**Estimated effort:** ~3 sessions, one per phase, each ending in a browser walk.

## Open Risks & Assumptions

- **Appearance has no automated gate at all.** The per-phase browser walk *is* the
  verification, not a supplement. A green suite is not evidence of a correct screen — measured,
  not assumed.
- Card shadows on list rows are the known risk of the chosen surface treatment, and the list is
  the main screen. Explicit manual check in Phase 1.
- Exact token values are proposed in the plan and **verified for contrast in the browser**
  during Phase 1 — `crimson` is the standing proof that an unverified colour ships.
- Three `getByText` sites match multiple sibling text nodes in one element (`Discarded {n}
  {word}.`, `✓ Done`, the `m:ss` clock). Wrapping any of them in a `<span>` breaks the test.
- Unrelated, found in passing: `frontend/Dockerfile` is on `node:20-alpine` while everything
  else requires Node 22. Not touched here.

## Success Criteria (Summary)

- Opening the app, the hierarchy is obvious without being told what to look at: titles,
  body and muted text are three visibly different things.
- No heading or clock overlaps itself at 1280px, 1024px or 400px, and every control uses the
  app's typeface rather than the browser's.
- Every reachable state in `research.md` §6 has been walked in a browser and looks deliberate —
  including the ones that render nothing today.
