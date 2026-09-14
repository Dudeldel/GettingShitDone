<?php

namespace App\Dto\Payload;

use App\Domain\Item\GtdBucket;

/**
 * Command: move an already-clarified item to a different destination (FR-010).
 *
 * Unlike ClarifyItemPayload this carries a bucket outright, and that is the difference
 * between the two operations rather than an inconsistency: clarify DERIVES a destination from
 * the user's answers and must never accept one, while re-filing IS the user naming a
 * destination. The rule that the Inbox is not among them lives on GtdBucket, so the edge and
 * the domain read it from the same place.
 */
class RefileItemPayload
{
    private function __construct(public readonly GtdBucket $destination) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(GtdBucket::from((string) $data['bucket']));
    }
}
