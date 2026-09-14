<?php

namespace App\Services;

use App\Domain\Clarify\ClarifyDecision;
use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ClarifyItemPayload;
use App\Dto\Payload\CompleteItemPayload;
use App\Dto\Payload\RefileItemPayload;
use App\Exceptions\InvalidClarificationException;
use App\Exceptions\ItemActionNotAllowedException;
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

        // FR-006 asks for the timer's outcome to be RECORDED, not merely acted on. Read off
        // the OUTCOME, never the payload: the payload only says what a client sent, and the
        // decision tree ignores timer answers on the paths that never ask the question — so
        // trusting it here logs a completed two-minute timer for an item filed in Projects.
        // Emitted after the write, so a logged timer always matches an item that really moved.
        if ($outcome->twoMinuteOutcome !== null) {
            LogEvent::twoMinuteRuleApplied($item->id, $outcome->twoMinuteOutcome, $outcome->twoMinuteLoops);
        }

        return $item;
    }

    /**
     * Move an already-clarified item to a different destination (FR-010).
     *
     * @throws ItemActionNotAllowedException the item is still in the Inbox
     * @throws ItemNotFoundException no item with this id
     * @throws ItemPersistenceException the write failed
     */
    public function refile(int $itemId, RefileItemPayload $payload): ItemDto
    {
        // The edge validates this too (RefileItemRequest derives its rule from the same
        // predicate). It is asserted again HERE because GtdBucket promises the rule is read by
        // both the edge and the domain, and a promise only one layer keeps is worse than none:
        // a job, a command or a future controller would walk an item back into the Inbox, where
        // clarify's `bucket = inbox` predicate matches again and its `completed_at => null`
        // write erases a real completion.
        if (! $payload->destination->isDestination()) {
            throw ItemActionNotAllowedException::refileToInbox();
        }

        try {
            $item = $this->items->refile($itemId, $payload->destination);
        } catch (ItemPersistenceException $e) {
            LogEvent::itemRefileFailed($itemId, $payload->destination, $e->sqlState());

            throw $e;
        }

        LogEvent::itemRefiled($item->id, $item->bucket);

        return $item;
    }

    /**
     * Mark an item done, or un-mark it.
     *
     * @throws ItemActionNotAllowedException the item is not in a bucket where done means anything
     * @throws ItemNotFoundException no item with this id
     * @throws ItemPersistenceException the write failed
     */
    public function setCompleted(int $itemId, CompleteItemPayload $payload): ItemDto
    {
        try {
            $item = $this->items->setCompleted($itemId, $payload->completed);
        } catch (ItemPersistenceException $e) {
            LogEvent::itemCompletionFailed($itemId, $payload->completed, $e->sqlState());

            throw $e;
        }

        LogEvent::itemCompletionChanged($item->id, $payload->completed);

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
