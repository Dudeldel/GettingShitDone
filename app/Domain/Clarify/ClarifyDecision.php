<?php

namespace App\Domain\Clarify;

use App\Domain\Item\GtdBucket;
use App\Dto\Payload\ClarifyItemPayload;
use App\Exceptions\InvalidClarificationException;

/**
 * The GTD clarify decision tree (FR-003) and its routing rule (FR-004, FR-005, FR-007).
 *
 * Pure PHP: no Eloquent, no framework, no HTTP. This is the only place the tree is encoded,
 * and it is the whole correctness surface of this slice.
 *
 * The canonical GTD order is `actionable? → single step? → < 2 min? → delegable?`. The
 * `< 2 min?` question is absent here on purpose: it belongs to S-03 together with the timer
 * and the decision about where a *completed* item goes — there is no Done bucket among the
 * eight. S-03 inserts that question between single-step and delegable; every path below
 * terminates without it, which is what makes FR-008 provable today.
 *
 * Terminating paths:
 *
 *   actionable = false                          → Trash | Someday/Maybe | Reference  (FR-004)
 *   actionable, not single-step                 → Projects                           (FR-005)
 *   actionable, single-step, delegable          → Delegation + who/what              (FR-007)
 *   actionable, single-step, not delegable      → Next Actions                       (FR-008)
 *
 * No path returns Inbox, and no path returns null: an item that has been clarified has
 * moved, by construction.
 */
class ClarifyDecision
{
    /**
     * The three destinations FR-004 offers for a non-actionable item. Not every bucket is a
     * legal answer here — Next Actions or Delegation would make "not actionable" meaningless.
     *
     * @var list<GtdBucket>
     */
    private const NON_ACTIONABLE_DESTINATIONS = [
        GtdBucket::Trash,
        GtdBucket::SomedayMaybe,
        GtdBucket::Reference,
    ];

    /**
     * @throws InvalidClarificationException when the answers do not describe a complete path
     */
    public function decide(ClarifyItemPayload $payload): ClarifyOutcome
    {
        if ($payload->isQuickRoute()) {
            return $this->quickRoute($payload);
        }

        if ($payload->actionable === null) {
            throw new InvalidClarificationException('Clarify needs an answer to "is it actionable?".');
        }

        return $payload->actionable
            ? $this->actionable($payload)
            : $this->nonActionable($payload);
    }

    /** FR-002: the user named the destination outright instead of walking the tree. */
    private function quickRoute(ClarifyItemPayload $payload): ClarifyOutcome
    {
        $bucket = $payload->quickRouteBucket;

        if ($bucket === null || $bucket === GtdBucket::Inbox) {
            // Routing to the Inbox is not a clarification; it is the absence of one.
            throw new InvalidClarificationException('A quick-route needs a destination other than the Inbox.');
        }

        if ($bucket === GtdBucket::Delegation) {
            return $this->delegation($payload);
        }

        return ClarifyOutcome::to($bucket);
    }

    /** FR-004: one of three canonical destinations, chosen by the user. */
    private function nonActionable(ClarifyItemPayload $payload): ClarifyOutcome
    {
        $destination = $payload->nonActionableDestination;

        if ($destination === null || ! in_array($destination, self::NON_ACTIONABLE_DESTINATIONS, true)) {
            throw new InvalidClarificationException(
                'A non-actionable item must go to Trash, Someday/Maybe or Reference.'
            );
        }

        return ClarifyOutcome::to($destination);
    }

    private function actionable(ClarifyItemPayload $payload): ClarifyOutcome
    {
        if ($payload->singleStep === null) {
            throw new InvalidClarificationException('Clarify needs an answer to "is it a single step?".');
        }

        // FR-005: a multi-step outcome is a Project. In the MVP a Project is just a
        // destination bucket — no hierarchy, no linked next actions (FR-012 is parked).
        if (! $payload->singleStep) {
            return ClarifyOutcome::to(GtdBucket::Projects);
        }

        if ($payload->delegable === null) {
            throw new InvalidClarificationException('Clarify needs an answer to "can it be delegated?".');
        }

        return $payload->delegable
            ? $this->delegation($payload)
            : ClarifyOutcome::to(GtdBucket::NextActions);
    }

    /** FR-007: free-text who/what, never a contact or user account. */
    private function delegation(ClarifyItemPayload $payload): ClarifyOutcome
    {
        $who = $payload->delegatedTo;

        if ($who === null || trim($who) === '') {
            throw new InvalidClarificationException('Delegating an item needs a note saying who you are waiting on.');
        }

        return ClarifyOutcome::delegatedTo(trim($who));
    }
}
