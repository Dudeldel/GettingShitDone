<?php

namespace App\Services;

use App\Domain\Clarify\ClarifyDecision;
use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ClarifyItemPayload;
use App\Exceptions\InvalidClarificationException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemNotInInboxException;
use App\Exceptions\ItemPersistenceException;
use App\Logging\LogEvent;
use Illuminate\Support\Collection;

/**
 * Orchestrates GTD item capture and listing. No HTTP framework imports — the controller
 * maps HTTP to these calls and back.
 */
class ItemService
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly ClarifyDecision $decision,
    ) {}

    /**
     * Capture a free-text idea (FR-001).
     *
     * The destination is applied here, not carried on the payload: a captured item always
     * lands in the Inbox, and that must not be client input.
     */
    public function capture(CaptureItemPayload $payload): ItemDto
    {
        try {
            $item = $this->items->create($payload, GtdBucket::Inbox);
        } catch (ItemPersistenceException $e) {
            // The guardrail outcome worth alerting on. Only the SQLSTATE travels — the
            // captured text must never reach a log line.
            LogEvent::itemCaptureFailed(GtdBucket::Inbox, $e->sqlState());

            throw $e;
        }

        LogEvent::itemCaptured($item->id, $item->bucket);

        return $item;
    }

    /**
     * Clarify an Inbox item (FR-002, FR-003, FR-008).
     *
     * The destination is derived here from the user's ANSWERS, never taken from the request
     * — the same discipline as capture, where the bucket is applied by the service rather
     * than accepted from the client.
     *
     * @throws InvalidClarificationException the answers do not describe a complete path
     * @throws ItemNotFoundException no item with this id
     * @throws ItemNotInInboxException the item has already been clarified
     * @throws ItemPersistenceException the write failed
     */
    public function clarify(int $itemId, ClarifyItemPayload $payload): ItemDto
    {
        $outcome = $this->decision->decide($payload);

        try {
            $item = $this->items->clarify($itemId, $outcome);
        } catch (ItemPersistenceException $e) {
            LogEvent::itemClarifyFailed($itemId, $outcome->bucket, $e->sqlState());

            throw $e;
        }

        LogEvent::itemClarified($item->id, $item->bucket);

        return $item;
    }

    /**
     * Permanently discard everything in the Trash.
     *
     * @return int how many items went; 0 is a success
     *
     * @throws ItemPersistenceException the delete failed
     */
    public function emptyTrash(): int
    {
        try {
            $count = $this->items->emptyTrash();
        } catch (ItemPersistenceException $e) {
            LogEvent::trashEmptyFailed($e->sqlState());

            throw $e;
        }

        LogEvent::trashEmptied($count);

        return $count;
    }

    /**
     * @return Collection<int, ItemDto>
     */
    public function listByBucket(GtdBucket $bucket): Collection
    {
        return $this->items->listByBucket($bucket);
    }
}
