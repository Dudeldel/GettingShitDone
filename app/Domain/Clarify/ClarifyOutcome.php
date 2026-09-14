<?php

namespace App\Domain\Clarify;

use App\Domain\Item\GtdBucket;

/**
 * Where a clarified item lands, plus the fields its branch implies.
 *
 * Immutable and bucket-bearing by construction: there is no way to build an outcome without
 * a destination, which is half of FR-008 ("nothing falls through") enforced by the type
 * rather than by a check someone can forget.
 *
 * It also carries what the two-minute rule DID, and that placement is load-bearing. This is
 * the only object that knows the timer branch was actually walked — the request payload does
 * not, because a client can attach timer answers to a path that never asks the question, and
 * the decision tree simply ignores them there. Anything reading the timer facts off the
 * payload instead of off this object can record a completed timer for an item filed in
 * Projects. FR-006 asks for the outcome to be recorded; this is what makes the record true.
 */
final readonly class ClarifyOutcome
{
    private function __construct(
        public GtdBucket $bucket,
        public ?string $delegatedTo,
        public bool $completed,
        public ?TwoMinuteOutcome $twoMinuteOutcome,
        public int $twoMinuteLoops,
    ) {}

    public static function to(GtdBucket $bucket): self
    {
        return new self($bucket, null, false, null, 0);
    }

    /** FR-007: Delegation always carries the free-text who/what note it was created with. */
    public static function delegatedTo(string $who): self
    {
        return new self(GtdBucket::Delegation, $who, false, null, 0);
    }

    /**
     * FR-006: the user did it inside the two minutes.
     *
     * Next Actions, because that is what the item already WAS — actionable, single-step,
     * not delegated. Doing it immediately changes its state, not its classification, and
     * there is no Done bucket among the eight (FR-009) for it to change class into. The
     * completion is carried as state so FR-008's exactly-one-bucket invariant still holds.
     *
     * @param  int  $loops  how many times the user asked for more time before finishing
     */
    public static function completedInTwoMinutes(int $loops): self
    {
        return new self(GtdBucket::NextActions, null, true, TwoMinuteOutcome::Done, $loops);
    }

    /**
     * FR-006: a timer ran and the user deferred, so the item carried on down the tree and
     * was filed by a later branch. The destination is that branch's; the timer facts are
     * stamped on afterwards, because deferring decides nothing about where the item goes.
     *
     * @param  int  $loops  how many times the user asked for more time before giving up
     */
    public function deferredAfterTwoMinutes(int $loops): self
    {
        return new self(
            $this->bucket,
            $this->delegatedTo,
            $this->completed,
            TwoMinuteOutcome::Deferred,
            $loops,
        );
    }
}
