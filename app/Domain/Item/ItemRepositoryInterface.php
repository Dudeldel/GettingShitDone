<?php

namespace App\Domain\Item;

use App\Domain\Clarify\ClarifyOutcome;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Exceptions\ItemActionNotAllowedException;
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
     * Move an already-clarified item into a different destination bucket (FR-010).
     *
     * A SEPARATE verb rather than a relaxed clarify, deliberately. The `where bucket = inbox`
     * predicate on clarify() is what makes its guard unraceable, and it also writes
     * completed_at => null — so reusing that path to re-file would erase the completion of a
     * finished Next Action moved to Calendar. This method writes the bucket and nothing else.
     *
     * @throws ItemNotFoundException no item with this id
     * @throws ItemActionNotAllowedException the item is still in the Inbox, so it wants clarify
     * @throws ItemPersistenceException the write failed
     */
    public function refile(int $itemId, GtdBucket $destination): ItemDto;

    /**
     * Mark an item done, or un-mark it.
     *
     * A toggle rather than a one-way door: this product has exactly one irreversible
     * operation (the Trash purge) and it is guarded by a confirmation. A single click should
     * not create a second. Writes completed_at and nothing else — in particular never the
     * bucket, so completing cannot move an item.
     *
     * @throws ItemNotFoundException no item with this id
     * @throws ItemActionNotAllowedException the item is not in a bucket where done means anything
     * @throws ItemPersistenceException the write failed
     */
    public function setCompleted(int $itemId, bool $completed): ItemDto;

    /**
     * Permanently delete every item in the Trash, returning how many went.
     *
     * Takes NO arguments on purpose. A signature accepting an item id would be a generic
     * "delete this row" verb, and the moment one exists it becomes reachable from every
     * bucket view. FR-010's re-filing now has its own verb above, with its own guard and its
     * own destination rule — which is precisely why this one must NOT become a generic
     * "delete this row". The Trash is the only place a permanent discard belongs (FR-004), so
     * the operation stays scoped to the bucket and cannot be aimed anywhere else.
     *
     * @return int the number of rows deleted; 0 is a success, not a failure
     *
     * @throws ItemPersistenceException the delete failed
     */
    public function emptyTrash(): int;
}
