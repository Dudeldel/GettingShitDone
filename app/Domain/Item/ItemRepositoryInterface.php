<?php

namespace App\Domain\Item;

use App\Domain\Clarify\ClarifyOutcome;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ItemAttributesPayload;
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
     * The Calendar/Dates view (FR-011), which is DERIVED rather than stored.
     *
     * Separate from listByBucket() because it is not a bucket query at all, and giving it its
     * own name keeps that visible: every other view in this interface answers "what is filed
     * here", this one answers "what is on the calendar", and those are different questions.
     *
     * Two kinds of item qualify:
     *   - anything filed to Calendar on purpose, dated or not — its membership is the answer;
     *   - anything in an ACTION bucket carrying a due date — a commitment with a day attached.
     *
     * Action buckets only, so a dated Reference note or a dated item in the Trash does not
     * surface as something to do. And an item appearing here does NOT make it a member of two
     * buckets: its stored `bucket` is untouched, which is what keeps FR-008 literally true of
     * the column while FR-011's "date-specific items appear in the Calendar/Dates bucket" is
     * true of the view.
     *
     * Ordered dated-first by date, then newest-first like every other listing.
     *
     * @return Collection<int, ItemDto>
     */
    public function listCalendar(): Collection;

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
     * @throws ItemNotInInboxException the item has already been clarified; moving it is
     *                                 refile()'s job, not a second clarify
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
     * Replace an item's five attribute columns: due date, tags, context and the two
     * Eisenhower flags (FR-011 + FR-013).
     *
     * A FULL replacement, not a patch — the payload always carries all five, so a null field
     * clears that attribute. This is what lets one verb both set and remove a due date.
     *
     * Writes those five columns and updated_at, and NOTHING else. In particular never the
     * bucket: setting a date must not move an item, which is the whole basis of the Calendar
     * view being derived rather than stored. Copying an update array from clarify() or
     * refile() here would reintroduce exactly the class of defect S-11's F2 recorded — a
     * write that quietly clobbers a sibling column.
     *
     * @throws ItemNotFoundException no item with this id
     * @throws ItemActionNotAllowedException the item is in the Trash, where editing is moot
     * @throws ItemPersistenceException the write failed
     */
    public function updateAttributes(int $itemId, ItemAttributesPayload $payload): ItemDto;

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
