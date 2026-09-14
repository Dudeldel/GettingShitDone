# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## A test is not coverage until deliberate breakage has reddened it

**Context:** `context/changes/testing-capture-durability/reviews/impl-review.md` F1, F4, F5,
F6 — the test-rollout change that introduced the frontend suite.

**Problem:** Three tests shipped that could not fail, in a change whose stated purpose was to
stop shipping tests that cannot fail, and whose plan quoted the earlier `ph2 F2` finding as
the anti-pattern to avoid. Each passed for a different wrong reason: `vi.spyOn` silently
no-ops on `Storage` under jsdom (the Proxy refuses the override without throwing), so the
private-window test ran the happy path; an RTL query resolved to an earlier never-unmounted
component rather than the remount under test; and an assertion ran after a navigation had
unmounted the element it was checking for, so it held whatever the flag's value. Separately,
removing the `Authorization` header from the API client entirely left all 20 tests green.
A fourth instance was a plan claiming "the existing tests already pin this" to justify adding
none — the assertions matched on fragments and structurally could not see the changed text.

**Rule:** Before accepting any test as coverage, break the specific behaviour it claims to
protect and watch it go red. Not the module — the behaviour. If it stays green, the test is
decoration, whatever its name says. This applies equally to tests you inherit and to the
claim "the existing tests cover it", which is only true if those assertions can actually
observe the thing.

**Applies to:** every new or modified test, in any layer. Especially where a mocking helper
is involved (verify it took effect; jsdom `Storage` cannot be spied — replace the global),
where multiple components can match one query, and where an assertion follows a navigation
or unmount.
