<?php

namespace App\Dto\Payload;

use App\Domain\Clarify\TwoMinuteOutcome;
use App\Domain\Item\GtdBucket;

/**
 * Command: clarify one Inbox item. Built by the controller from
 * ClarifyItemRequest::validated() and passed into ItemService.
 *
 * Two mutually exclusive modes, kept distinct on purpose:
 *
 *  - the guided tree (FR-003), where the user supplies ANSWERS and the domain derives the
 *    destination;
 *  - the quick-route (FR-002), where the user names the destination outright.
 *
 * The `< 2 min?` answer (FR-006) belongs to the tree mode only: the quick-route skips the
 * questions by definition, so a timer there would be a question asked inside the path whose
 * whole point is not asking any.
 *
 * A single optional "bucket" field usable by either mode would recreate, on this endpoint,
 * exactly the hole that CaptureItemPayload is shaped to avoid: a client choosing where an
 * item lands. The tree mode therefore has no bucket field at all, except the non-actionable
 * destination, which FR-004 makes a genuine user choice among three.
 */
class ClarifyItemPayload
{
    private function __construct(
        public readonly ?GtdBucket $quickRouteBucket,
        public readonly ?bool $actionable,
        public readonly ?GtdBucket $nonActionableDestination,
        public readonly ?bool $singleStep,
        public readonly ?bool $twoMinutes,
        public readonly ?TwoMinuteOutcome $twoMinuteOutcome,
        public readonly int $twoMinuteLoops,
        public readonly ?bool $delegable,
        public readonly ?string $delegatedTo,
    ) {}

    /**
     * FR-002: the user skipped the tree and picked a destination directly.
     *
     * Takes the delegation note too, because Delegation is a legal quick-route target and
     * FR-007 makes the note part of what Delegation IS. Without it this branch could only
     * ever throw — the destination would be reachable in name and impossible in practice.
     */
    public static function quickRoute(GtdBucket $bucket, ?string $delegatedTo = null): self
    {
        return new self($bucket, null, null, null, null, null, 0, null, $delegatedTo);
    }

    /** FR-003: the user walked the tree; the domain derives the destination from these answers. */
    public static function treePath(
        bool $actionable,
        ?GtdBucket $nonActionableDestination = null,
        ?bool $singleStep = null,
        ?bool $twoMinutes = null,
        ?TwoMinuteOutcome $twoMinuteOutcome = null,
        int $twoMinuteLoops = 0,
        ?bool $delegable = null,
        ?string $delegatedTo = null,
    ): self {
        return new self(
            null,
            $actionable,
            $nonActionableDestination,
            $singleStep,
            $twoMinutes,
            $twoMinuteOutcome,
            $twoMinuteLoops,
            $delegable,
            $delegatedTo,
        );
    }

    /** FR-006: the user answered "< 2 min?" with yes, so a timer ran. */
    public function tookTheTwoMinuteBranch(): bool
    {
        return $this->twoMinutes === true;
    }

    public function isQuickRoute(): bool
    {
        return $this->quickRouteBucket !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['quickRouteBucket'])) {
            return self::quickRoute(
                GtdBucket::from((string) $data['quickRouteBucket']),
                isset($data['delegatedTo']) ? (string) $data['delegatedTo'] : null,
            );
        }

        return self::treePath(
            actionable: (bool) $data['actionable'],
            nonActionableDestination: isset($data['nonActionableDestination'])
                ? GtdBucket::from((string) $data['nonActionableDestination'])
                : null,
            singleStep: isset($data['singleStep']) ? (bool) $data['singleStep'] : null,
            // isset(), not array_key_exists(): a JSON `false` is set, a missing key is not,
            // and the domain distinguishes "answered no" from "never asked".
            twoMinutes: isset($data['twoMinutes']) ? (bool) $data['twoMinutes'] : null,
            twoMinuteOutcome: isset($data['twoMinuteOutcome'])
                ? TwoMinuteOutcome::from((string) $data['twoMinuteOutcome'])
                : null,
            twoMinuteLoops: isset($data['twoMinuteLoops']) ? (int) $data['twoMinuteLoops'] : 0,
            delegable: isset($data['delegable']) ? (bool) $data['delegable'] : null,
            delegatedTo: isset($data['delegatedTo']) ? (string) $data['delegatedTo'] : null,
        );
    }
}
