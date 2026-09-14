<?php

use App\Domain\Clarify\ClarifyDecision;
use App\Domain\Clarify\TwoMinuteOutcome;
use App\Domain\Item\GtdBucket;
use App\Dto\Payload\ClarifyItemPayload;
use App\Exceptions\InvalidClarificationException;

/**
 * The full branch matrix of the GTD decision tree.
 *
 * Every expected bucket below is derived from the PRD requirement cited in its test name —
 * NOT by reading ClarifyDecision. An expectation lifted from the implementation under test
 * can only confirm current behaviour, including current bugs, and can never fail for the
 * right reason. The PRD is the oracle here; the code is the thing on trial.
 */
beforeEach(function () {
    $this->decide = fn (ClarifyItemPayload $payload): GtdBucket => (new ClarifyDecision)
        ->decide($payload)
        ->bucket;
});

describe('a non-actionable item (FR-004)', function () {
    it('goes to Trash when the user says so', function () {
        $outcome = ($this->decide)(ClarifyItemPayload::treePath(
            actionable: false,
            nonActionableDestination: GtdBucket::Trash,
        ));

        expect($outcome)->toBe(GtdBucket::Trash);
    });

    it('goes to Someday/Maybe when the user says so', function () {
        $outcome = ($this->decide)(ClarifyItemPayload::treePath(
            actionable: false,
            nonActionableDestination: GtdBucket::SomedayMaybe,
        ));

        expect($outcome)->toBe(GtdBucket::SomedayMaybe);
    });

    it('goes to Reference when the user says so', function () {
        $outcome = ($this->decide)(ClarifyItemPayload::treePath(
            actionable: false,
            nonActionableDestination: GtdBucket::Reference,
        ));

        expect($outcome)->toBe(GtdBucket::Reference);
    });

    it('refuses a destination outside the three FR-004 offers', function () {
        // Next Actions for a non-actionable item would make the answer meaningless.
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: false,
            nonActionableDestination: GtdBucket::NextActions,
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses to pick a destination on the user behalf', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(actionable: false)))
            ->toThrow(InvalidClarificationException::class);
    });
});

describe('an actionable item (FR-005, FR-007, FR-008)', function () {
    it('becomes a Project when it takes more than one step', function () {
        $outcome = ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: false,
        ));

        expect($outcome)->toBe(GtdBucket::Projects);
    });

    it('goes to Delegation when it can be handed off', function () {
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
            delegable: true,
            delegatedTo: 'Ania — sent the contract on Tuesday',
        ));

        expect($outcome->bucket)->toBe(GtdBucket::Delegation)
            ->and($outcome->delegatedTo)->toBe('Ania — sent the contract on Tuesday');
    });

    it('goes to Next Actions when it is a single step nobody else can take', function () {
        $outcome = ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
            delegable: false,
        ));

        expect($outcome)->toBe(GtdBucket::NextActions);
    });

    it('refuses to delegate without recording who is being waited on', function () {
        // FR-007: Delegation IS the who/what note. Without it the bucket is useless —
        // you would know something is delegated but not whom to chase.
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
            delegable: true,
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses a whitespace-only delegation note', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
            delegable: true,
            delegatedTo: '   ',
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses an unanswered single-step question', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(actionable: true)))
            ->toThrow(InvalidClarificationException::class);
    });

    it('refuses an unanswered delegable question', function () {
        // twoMinutes answered, so the gap under test really is the delegable one — without
        // it this would throw on the two-minute question instead and still pass, testing
        // nothing about delegation.
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
        )))->toThrow(InvalidClarificationException::class);
    });
});

describe('the quick-route (FR-002)', function () {
    it('sends the item straight to the bucket the user named', function () {
        expect(($this->decide)(ClarifyItemPayload::quickRoute(GtdBucket::Reference)))
            ->toBe(GtdBucket::Reference);
    });

    it('still demands a who/what note when the destination is Delegation', function () {
        // Skipping the tree must not skip the field that makes Delegation meaningful.
        expect(fn () => ($this->decide)(ClarifyItemPayload::quickRoute(GtdBucket::Delegation)))
            ->toThrow(InvalidClarificationException::class);
    });

    it('refuses the Inbox as a destination', function () {
        // Routing to the Inbox is not a clarification; it is the absence of one.
        expect(fn () => ($this->decide)(ClarifyItemPayload::quickRoute(GtdBucket::Inbox)))
            ->toThrow(InvalidClarificationException::class);
    });
});

/**
 * The guardrail itself (FR-008), asserted over the whole answer space rather than one path
 * at a time: whatever the user answers, a clarified item has left the Inbox.
 */
it('never leaves a clarified item in the Inbox, on any path through the tree', function () {
    $decision = new ClarifyDecision;

    $paths = [
        ClarifyItemPayload::treePath(actionable: false, nonActionableDestination: GtdBucket::Trash),
        ClarifyItemPayload::treePath(actionable: false, nonActionableDestination: GtdBucket::SomedayMaybe),
        ClarifyItemPayload::treePath(actionable: false, nonActionableDestination: GtdBucket::Reference),
        ClarifyItemPayload::treePath(actionable: true, singleStep: false),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: false, delegable: true, delegatedTo: 'Bob'),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: false, delegable: false),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: true, twoMinuteOutcome: TwoMinuteOutcome::Done),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: true, twoMinuteOutcome: TwoMinuteOutcome::Deferred, delegable: false),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: true, twoMinuteOutcome: TwoMinuteOutcome::Deferred, delegable: true, delegatedTo: 'Bob'),
    ];

    foreach ($paths as $path) {
        expect($decision->decide($path)->bucket)->not->toBe(GtdBucket::Inbox);
    }

    // Nine paths is the complete set now that the "< 2 min?" question is in the tree: the
    // six from S-02, plus done, plus deferred rejoining the tree on BOTH of its sides. If
    // that count ever drops, a branch was removed; if it rises without this list growing,
    // a branch is untested.
    expect($paths)->toHaveCount(9);
});

/**
 * FR-006. The expectations below come from the PRD's acceptance criterion — "a '< 2 min'
 * item triggers a 2-minute timer; completion marks it done, 'need more time' loops the
 * timer" — and from the resolution recorded in this change's change.md for the question the
 * PRD leaves open: there is no Done bucket among the eight, so done is state, not a
 * destination.
 */
describe('the two-minute rule (FR-006)', function () {
    it('marks an item completed when the user finishes it inside the timer', function () {
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: true,
            twoMinuteOutcome: TwoMinuteOutcome::Done,
        ));

        expect($outcome->completed)->toBeTrue()
            // Next Actions, because that is what the item already was. A done item still
            // needs exactly one bucket (FR-008) and there is no ninth one to invent.
            ->and($outcome->bucket)->toBe(GtdBucket::NextActions);
    });

    it('never asks about delegation once the item is already done', function () {
        // Ordering, expressed as behaviour: the done branch terminates BEFORE "can it be
        // delegated?" is consulted. If the questions were reordered, or the done branch fell
        // through, these delegation answers would win and the item would land in Delegation —
        // filed as waiting on someone for work that is already finished.
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: true,
            twoMinuteOutcome: TwoMinuteOutcome::Done,
            delegable: true,
            delegatedTo: 'Ania',
        ));

        expect($outcome->bucket)->toBe(GtdBucket::NextActions)
            ->and($outcome->delegatedTo)->toBeNull()
            ->and($outcome->completed)->toBeTrue();
    });

    it('returns a deferred item to the tree rather than filing it', function () {
        // "Explicitly deferred" in the PRD's Business Logic does NOT mean "done with it" —
        // the user still has to answer the last question. Treating deferral as a terminal
        // outcome would file an unclarified item.
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: true,
            twoMinuteOutcome: TwoMinuteOutcome::Deferred,
            delegable: false,
        ));

        expect($outcome->bucket)->toBe(GtdBucket::NextActions)
            // The item was NOT finished. Marking a deferred item done would tell the user
            // they did work they explicitly said they had not done.
            ->and($outcome->completed)->toBeFalse();
    });

    it('lets a deferred item still be delegated', function () {
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: true,
            twoMinuteOutcome: TwoMinuteOutcome::Deferred,
            delegable: true,
            delegatedTo: 'Piotr — quoted on Monday',
        ));

        expect($outcome->bucket)->toBe(GtdBucket::Delegation)
            ->and($outcome->delegatedTo)->toBe('Piotr — quoted on Monday')
            ->and($outcome->completed)->toBeFalse();
    });

    it('refuses an unanswered two-minute question', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses a timer that started and never ended', function () {
        // Defaulting either way is the failure: "done" invents work the user never did,
        // "deferred" discards work they did.
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: true,
        )))->toThrow(InvalidClarificationException::class);
    });

    it('does not complete an item that never went near the timer', function () {
        $outcome = (new ClarifyDecision)->decide(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            twoMinutes: false,
            delegable: false,
        ));

        expect($outcome->completed)->toBeFalse();
    });

    it('does not complete an item filed by the quick-route', function () {
        // The quick-route skips the questions, so no timer ever ran. An item marked done
        // here would be a completion nobody performed.
        expect((new ClarifyDecision)->decide(ClarifyItemPayload::quickRoute(GtdBucket::NextActions))->completed)
            ->toBeFalse();
    });
});
