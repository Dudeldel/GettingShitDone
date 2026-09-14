---
change_id: visual-design-pass
title: Make the UI read as a considered product, not a scaffold
status: implementing
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

make the UI look like a considered product instead of the unstyled scaffold it is today — real visual hierarchy, an accent that actually gets used, working light/dark, and the inherited layout defects fixed. Roadmap S-10. The acceptance bar is not in the PRD and has to be agreed.

## Decisions taken before research (2026-09-14)

The roadmap recorded one **blocking** unknown for S-10: what "looks designed" means, concretely
enough to accept or reject a result. The PRD commits nothing about visual design, so it was
settled by asking rather than derived. Answers:

1. **Direction: Tailwind v4 + a ready-made component kit.** Not a token repair, not hand-written
   CSS on the existing tokens — adopt a design system and restyle every screen against it.
2. **Dark mode: out of scope, light only.** The half-built `@media (prefers-color-scheme: dark)`
   palette and `color-scheme: light dark` come out rather than being finished. Recorded as a
   deliberate cut, not an oversight.
3. **No visual-regression pin.** Test-plan Phase 4 (Risk #5) does NOT ride along with this slice.

Consequence of 1 + 3, stated plainly so nobody rediscovers it later: this is the highest
blast-radius option combined with the smallest safety net. A component kit replaces form
controls and the dialog — exactly the elements whose focus management and aria attributes were
added as implementation-review fixes in S-02 and S-05 — and no deterministic visual diff will be
watching. **The 91 existing frontend tests are the entire safety net**, which is why the first
research question is how much of that suite actually survives a markup swap.

## Direction revised after research (2026-09-14)

Decision 1 above — "Tailwind v4 + a ready-made component kit" — was **superseded once the
measurements came in**. The revised direction is **Tailwind v4 alone; the hand-written
components stay.**

Three measured reasons, all in `research.md` §8:

1. **Every kit breaks the same existing test, identically.** Base UI, Radix, HeroUI and Ark
   all apply `aria-hidden` to the background behind a modal, which makes
   `InboxPage.test.tsx:228`'s `within(getByRole('listitem'))` unfindable while the clarify
   dialog is open. The only escape is `modal={false}` — giving up the behaviour the kit was
   adopted for.
2. **A kit subtracts guarantees this project already paid to get.** The focus-to-the-safe-
   button on the Trash confirmation (S-05 F8) and focus-into-the-wizard-heading (S-02 F9)
   are product judgements no kit provides; Base UI does not even set `aria-modal` (measured
   `null`), and HeroUI's focus restore does not fire under jsdom.
3. **Tailwind alone costs nothing in the suite** — there is not one `className` in `src/`
   today, so it is purely additive and the JSX structure never has to change. It also fixes
   the whole defect list on its own, including the 13.33px controls, which Preflight repairs
   for free.

Decisions 2 (light only) and 3 (no visual pin) stand unchanged.

**What this does NOT change:** the suite still cannot observe appearance — no visibility
assertion exists, and no CSS loads in the test environment. Verification of how the screens
actually look remains a human looking at a browser. That was true under either direction.
