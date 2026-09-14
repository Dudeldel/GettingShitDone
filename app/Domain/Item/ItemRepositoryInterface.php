<?php

namespace App\Domain\Item;

use App\Domain\Clarify\ClarifyOutcome;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemNotInInboxException;
use App\Exceptions\ItemPersistenceException;
use Illuminate\Support\Collection;

/**
 * Data-access contract for GTD items. Implementations confine Eloquent; no Model leaks
 * past this boundary.
 */
interface ItemRepositoryInterface
{
    public function create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto;

    /**
     * Items in one bucket, newest first (created_at desc, id desc as the tiebreaker so
     * ordering stays deterministic when two items share a timestamp).
     *
     * @return Collection<int, ItemDto>
     */
    public function listByBucket(GtdBucket $bucket): Collection;

    /**
     * Apply a clarification to one Inbox item: move it to its destination and record any
     * fields the branch implies. The first write path in this interface that MODIFIES an
     * existing row rather than creating one.
     *
     * Fails loudly rather than updating nothing — a clarify that silently no-ops would
     * leave the item in the Inbox while telling the user it had moved, which is the
     * failure FR-008 exists to prevent.
     *
     * @throws ItemNotFoundException no item with this id
     * @throws ItemNotInInboxException the item has already been clarified (FR-010 is v2)
     * @throws ItemPersistenceException the write itself failed
     */
    public function clarify(int $itemId, ClarifyOutcome $outcome): ItemDto;
}
