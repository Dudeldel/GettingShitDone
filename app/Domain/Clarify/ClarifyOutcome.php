<?php

namespace App\Domain\Clarify;

use App\Domain\Item\GtdBucket;

/**
 * Where a clarified item lands, plus the fields its branch implies.
 *
 * Immutable and bucket-bearing by construction: there is no way to build an outcome without
 * a destination, which is half of FR-008 ("nothing falls through") enforced by the type
 * rather than by a check someone can forget.
 */
final readonly class ClarifyOutcome
{
    private function __construct(
        public GtdBucket $bucket,
        public ?string $delegatedTo,
    ) {}

    public static function to(GtdBucket $bucket): self
    {
        return new self($bucket, null);
    }

    /** FR-007: Delegation always carries the free-text who/what note it was created with. */
    public static function delegatedTo(string $who): self
    {
        return new self(GtdBucket::Delegation, $who);
    }
}
