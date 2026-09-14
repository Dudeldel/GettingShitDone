<?php

namespace App\Dto\Payload;

/**
 * Command: replace an item's whole attribute set (FR-011 + FR-013).
 *
 * Carries the complete new state of the five attribute columns, never a partial patch — so a
 * null field is an instruction ("clear this"), not an absence. That is what lets one verb
 * both set and clear a due date without a second endpoint.
 *
 * What it deliberately CANNOT carry is a bucket, a completion, a title or a note. The generic
 * PATCH this codebase has twice refused was refused because it let a client choose where an
 * item lands; this payload has no field capable of expressing that, so the discipline holds by
 * construction rather than by a guard somebody has to remember.
 */
class ItemAttributesPayload
{
    /**
     * @param  list<string>|null  $tags
     */
    private function __construct(
        public readonly ?string $dueDate,
        public readonly ?array $tags,
        public readonly ?string $context,
        public readonly ?bool $important,
        public readonly ?bool $urgent,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? null;

        return new self(
            dueDate: isset($data['dueDate']) ? (string) $data['dueDate'] : null,
            // Re-packed here as well as in the normaliser: this is the boundary that promises
            // a list<string>, and fromArray is reachable from tests that never ran the
            // FormRequest.
            tags: is_array($tags)
                ? array_values(array_map(static fn (mixed $tag): string => (string) $tag, $tags))
                : null,
            context: isset($data['context']) ? (string) $data['context'] : null,
            // The `boolean` rule validates without casting, so "1" and 1 both arrive here as
            // themselves. Cast, but only past null — null is a value this field carries.
            important: isset($data['important']) ? (bool) $data['important'] : null,
            urgent: isset($data['urgent']) ? (bool) $data['urgent'] : null,
        );
    }
}
