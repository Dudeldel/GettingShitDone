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
 * The canonical GTD order is complete here: `actionable? → single step? → < 2 min? →
 * delegable?` (FR-003).
 *
 * Terminating paths:
 *
 *   actionable = false                          → Trash | Someday/Maybe | Reference  (FR-004)
 *   actionable, not single-step                 → Projects                           (FR-005)
 *   actionable, single-step, < 2 min, done      → Next Actions, completed            (FR-006)
 *   actionable, single-step, delegable          → Delegation + who/what              (FR-007)
 *   actionable, single-step, not delegable      → Next Actions                       (FR-008)
 *
 * The `< 2 min?` branch is the only one that does not always terminate: a DEFERRED timer
 * falls through to "can it be delegated?" and rejoins the paths above. That is what the
 * PRD means by holding the user "until it is done or explicitly deferred" — deferring
 * returns them to the tree, it does not file the item.
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
            throw InvalidClarificationException::missingActionableAnswer();
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
            throw InvalidClarificationException::quickRouteToInbox();
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
            throw InvalidClarificationException::missingNonActionableDestination();
        }

        return ClarifyOutcome::to($destination);
    }

    private function actionable(ClarifyItemPayload $payload): ClarifyOutcome
    {
        if ($payload->singleStep === null) {
            throw InvalidClarificationException::missingSingleStepAnswer();
        }

        // FR-005: a multi-step outcome is a Project. In the MVP a Project is just a
        // destination bucket — no hierarchy, no linked next actions (FR-012 is parked).
        if (! $payload->singleStep) {
            return ClarifyOutcome::to(GtdBucket::Projects);
        }

        // FR-006: the two-minute rule sits between "single step?" and "can it be delegated?".
        // Asking it later would let the user delegate something they were about to do in a
        // minute; asking it earlier would apply it to multi-step projects.
        if ($payload->twoMinutes === null) {
            throw InvalidClarificationException::missingTwoMinuteAnswer();
        }

        if ($payload->twoMinutes) {
            if ($payload->twoMinuteOutcome === null) {
                // A timer that was started must have ended somehow. Defaulting either way
                // would either invent work the user never did or discard work they did.
                throw InvalidClarificationException::missingTwoMinuteOutcome();
            }

            if ($payload->twoMinuteOutcome === TwoMinuteOutcome::Done) {
                return ClarifyOutcome::completedInTwoMinutes();
            }
            // Deferred: the item is still unfiled, so it carries on to the last question.
        }

        if ($payload->delegable === null) {
            throw InvalidClarificationException::missingDelegableAnswer();
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
            throw InvalidClarificationException::missingDelegationNote();
        }

        return ClarifyOutcome::delegatedTo(trim($who));
    }
}
