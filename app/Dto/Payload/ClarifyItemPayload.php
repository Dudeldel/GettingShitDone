<?php

namespace App\Dto\Payload;

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
        public readonly ?bool $delegable,
        public readonly ?string $delegatedTo,
    ) {}

    /** FR-002: the user skipped the tree and picked a destination directly. */
    public static function quickRoute(GtdBucket $bucket): self
    {
        return new self($bucket, null, null, null, null, null);
    }

    /** FR-003: the user walked the tree; the domain derives the destination from these answers. */
    public static function treePath(
        bool $actionable,
        ?GtdBucket $nonActionableDestination = null,
        ?bool $singleStep = null,
        ?bool $delegable = null,
        ?string $delegatedTo = null,
    ): self {
        return new self(null, $actionable, $nonActionableDestination, $singleStep, $delegable, $delegatedTo);
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
            return self::quickRoute(GtdBucket::from((string) $data['quickRouteBucket']));
        }

        return self::treePath(
            actionable: (bool) $data['actionable'],
            nonActionableDestination: isset($data['nonActionableDestination'])
                ? GtdBucket::from((string) $data['nonActionableDestination'])
                : null,
            singleStep: isset($data['singleStep']) ? (bool) $data['singleStep'] : null,
            delegable: isset($data['delegable']) ? (bool) $data['delegable'] : null,
            delegatedTo: isset($data['delegatedTo']) ? (string) $data['delegatedTo'] : null,
        );
    }
}
