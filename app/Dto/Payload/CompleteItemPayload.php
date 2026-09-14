<?php

namespace App\Dto\Payload;

/**
 * Command: mark an item done, or un-mark it.
 *
 * A single boolean rather than two endpoints, because completion is a toggle — the product
 * has exactly one irreversible operation and it is guarded by a confirmation.
 */
class CompleteItemPayload
{
    private function __construct(public readonly bool $completed) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((bool) $data['completed']);
    }
}
