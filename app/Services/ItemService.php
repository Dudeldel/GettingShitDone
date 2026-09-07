<?php

namespace App\Services;

use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Logging\LogEvent;
use Illuminate\Support\Collection;

/**
 * Orchestrates GTD item capture and listing. No HTTP framework imports — the controller
 * maps HTTP to these calls and back.
 */
class ItemService
{
    public function __construct(private readonly ItemRepositoryInterface $items) {}

    /**
     * Capture a free-text idea (FR-001).
     *
     * The destination is applied here, not carried on the payload: a captured item always
     * lands in the Inbox, and that must not be client input.
     */
    public function capture(CaptureItemPayload $payload): ItemDto
    {
        $item = $this->items->create($payload, GtdBucket::Inbox);

        LogEvent::itemCaptured($item->id, $item->bucket);

        return $item;
    }

    /**
     * @return Collection<int, ItemDto>
     */
    public function listByBucket(GtdBucket $bucket): Collection
    {
        return $this->items->listByBucket($bucket);
    }
}
