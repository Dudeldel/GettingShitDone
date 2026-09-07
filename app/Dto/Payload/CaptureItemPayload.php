<?php

namespace App\Dto\Payload;

/**
 * Command: capture a free-text idea. Built by the controller from
 * CaptureItemRequest::validated() and passed into ItemService.
 *
 * The destination bucket is deliberately not a payload field — capture always targets
 * the Inbox, and that default belongs to the service, not to user input.
 */
class CaptureItemPayload
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $note,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
