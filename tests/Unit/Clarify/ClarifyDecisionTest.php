<?php

use App\Domain\Clarify\ClarifyDecision;
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
            delegable: true,
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses a whitespace-only delegation note', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
            delegable: true,
            delegatedTo: '   ',
        )))->toThrow(InvalidClarificationException::class);
    });

    it('refuses an unanswered single-step question', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(actionable: true)))
            ->toThrow(InvalidClarificationException::class);
    });

    it('refuses an unanswered delegable question', function () {
        expect(fn () => ($this->decide)(ClarifyItemPayload::treePath(
            actionable: true,
            singleStep: true,
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
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, delegable: true, delegatedTo: 'Bob'),
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, delegable: false),
    ];

    foreach ($paths as $path) {
        expect($decision->decide($path)->bucket)->not->toBe(GtdBucket::Inbox);
    }

    // Six paths is the complete set while the "< 2 min?" question belongs to S-03. If that
    // count ever drops, a branch was removed; if it rises without this list growing, a
    // branch is untested.
    expect($paths)->toHaveCount(6);
});
