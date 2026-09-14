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

    /**
     * Permanently delete every item in the Trash, returning how many went.
     *
     * Takes NO arguments on purpose. A signature accepting an item id would be a generic
     * "delete this row" verb, and the moment one exists it becomes reachable from every
     * bucket view — which is FR-010's re-filing semantics arriving by the back door, parked
     * for v2. The Trash is the only place a permanent discard belongs (FR-004), so the
     * operation is scoped to the bucket and cannot be aimed anywhere else.
     *
     * @return int the number of rows deleted; 0 is a success, not a failure
     *
     * @throws ItemPersistenceException the delete failed
     */
    public function emptyTrash(): int;
}
