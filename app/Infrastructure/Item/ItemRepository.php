<?php

namespace App\Infrastructure\Item;

use App\Domain\Clarify\ClarifyOutcome;
use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ItemAttributesPayload;
use App\Exceptions\ItemActionNotAllowedException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemNotInInboxException;
use App\Exceptions\ItemPersistenceException;
use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class ItemRepository implements ItemRepositoryInterface
{
    public function create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto
    {
        try {
            $item = Item::query()->create([
                'title' => $payload->title,
                'note' => $payload->note,
                'bucket' => $bucket,
            ]);
        } catch (QueryException $e) {
            // Never rethrow the driver message and never chain it as `previous`: Laravel
            // interpolates the bindings into it, which would put the captured text into
            // the error log past the key-based redaction processor.
            throw new ItemPersistenceException((string) $e->getCode());
        }

        return $this->toDto($item);
    }

    public function clarify(int $itemId, ClarifyOutcome $outcome): ItemDto
    {
        try {
            // Guard and write in ONE statement. A find/compare/save sequence is check-then-act:
            // two concurrent clarifies both read `inbox`, both pass the check, and both write —
            // returning 200 with contradictory destinations and leaving the row corrupt, because
            // save() only writes dirty attributes so one request's null delegated_to never lands.
            // The `where bucket = inbox` predicate makes the database the arbiter instead.
            // Same defect class as the archived email-password-auth F1 (check-then-act on the
            // first-run gate), fixed there with a transaction; a conditional update closes this
            // one without needing a transaction seam at all.
            $affected = Item::query()
                ->where('id', $itemId)
                ->where('bucket', GtdBucket::Inbox->value)
                ->update([
                    'bucket' => $outcome->bucket->value,
                    'delegated_to' => $outcome->delegatedTo,
                    // FR-006: written in the SAME statement as the bucket, so an item can
                    // never be filed-but-not-marked-done (or the reverse) — the two facts
                    // about a two-minute completion land together or not at all.
                    //
                    // The null branch is safe ONLY because of the `bucket = inbox` predicate
                    // above: a completed item has left the Inbox, so it can never be clarified
                    // again and this can never overwrite a real completion. This is why FR-010
                    // landed as refile() — a SEPARATE verb that writes the bucket and nothing
                    // else — instead of a relaxed clarify. Anyone loosening this predicate must
                    // stop writing null here, or re-filing a finished Next Action to Calendar
                    // will silently erase the fact that it was done.
                    'completed_at' => $outcome->completed ? now() : null,
                    // Query-builder updates bypass Eloquent, so timestamps are ours to set.
                    'updated_at' => now(),
                ]);
        } catch (QueryException $e) {
            // Same reasoning as create(): Laravel interpolates bindings into the message,
            // so the driver exception must not travel.
            throw new ItemPersistenceException((string) $e->getCode());
        }

        if ($affected === 0) {
            // Nothing matched. One read tells the two cases apart, and it is only reached on
            // the failure path, so the happy path stays a single statement.
            throw Item::query()->whereKey($itemId)->exists()
                ? new ItemNotInInboxException('Item '.$itemId.' has already been clarified.')
                : new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->readBack($itemId);
    }

    public function refile(int $itemId, GtdBucket $destination): ItemDto
    {
        try {
            // Guarded and written in ONE statement, same reasoning as clarify(): the predicate
            // makes the database the arbiter so two concurrent re-files cannot both pass a
            // check and then both write.
            //
            // `bucket <> inbox` rather than clarify's `= inbox`: an item still in the Inbox has
            // not been decided about, so it wants clarify, not a move.
            //
            // The update array holds the bucket and the timestamp and NOTHING else. Copying
            // clarify's array here is the one mistake this method exists to avoid — it writes
            // completed_at => null, which would silently erase the completion of a finished
            // item the moment somebody moved it.
            $affected = Item::query()
                ->where('id', $itemId)
                ->where('bucket', '<>', GtdBucket::Inbox->value)
                ->update($this->refileWrite($destination));
        } catch (QueryException $e) {
            throw new ItemPersistenceException((string) $e->getCode());
        }

        if ($affected === 0) {
            throw Item::query()->whereKey($itemId)->exists()
                ? ItemActionNotAllowedException::refileAnUnclarifiedItem()
                : new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->readBack($itemId);
    }

    /**
     * The columns a re-file writes.
     *
     * Normally bucket and timestamp only — copying clarify's array here is the one mistake this
     * method exists to avoid, because it writes completed_at => null and would erase the
     * completion of a finished item the moment somebody moved it.
     *
     * The exception is a destination where "done" means nothing. isActionBucket() says a
     * completion is uninterpretable in Reference, Someday/Maybe and Trash, and setCompleted()
     * refuses to write one there — so carrying a completion INTO one of them strands it: the
     * item renders "✓ Done" with no control able to clear it again, because the verb that
     * clears it refuses that bucket. Clearing it on the way in is the deliberate opposite of
     * the trap above: the trap is erasing a completion that still means something, this is
     * dropping one that no longer can.
     *
     * @return array<string, mixed>
     */
    private function refileWrite(GtdBucket $destination): array
    {
        $write = [
            'bucket' => $destination->value,
            'updated_at' => now(),
        ];

        if (! $destination->isActionBucket()) {
            $write['completed_at'] = null;
        }

        return $write;
    }

    public function setCompleted(int $itemId, bool $completed): ItemDto
    {
        try {
            // Same single-statement discipline. The bucket appears only in the PREDICATE,
            // never in the update — completing an item must not be able to move it.
            $affected = Item::query()
                ->where('id', $itemId)
                ->whereIn('bucket', array_map(
                    static fn (GtdBucket $bucket): string => $bucket->value,
                    array_filter(GtdBucket::cases(), static fn (GtdBucket $b): bool => $b->isActionBucket()),
                ))
                ->update([
                    'completed_at' => $completed ? now() : null,
                    'updated_at' => now(),
                ]);
        } catch (QueryException $e) {
            throw new ItemPersistenceException((string) $e->getCode());
        }

        if ($affected === 0) {
            throw Item::query()->whereKey($itemId)->exists()
                ? ItemActionNotAllowedException::completeOutsideActionBuckets()
                : new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->readBack($itemId);
    }

    public function updateAttributes(int $itemId, ItemAttributesPayload $payload): ItemDto
    {
        try {
            // Same single-statement discipline as the other write verbs. The predicate is
            // `bucket <> trash`: an item in the bin is on its way out, and editing it invites
            // "where did my change go" the moment the Trash is emptied.
            //
            // The update array is the five attribute columns and the timestamp. The bucket is
            // absent ON PURPOSE and must stay absent — a due date that moved an item would make
            // the Calendar view stored rather than derived, which is the one thing this whole
            // slice is built not to do.
            //
            // All five are written unconditionally, including nulls: this verb replaces the
            // attribute set rather than patching it, so "clear the date" is the same statement
            // as "set the date" with a different value.
            $affected = Item::query()
                ->where('id', $itemId)
                ->where('bucket', '<>', GtdBucket::Trash->value)
                ->update([
                    'due_date' => $payload->dueDate,
                    // A query-builder update bypasses the Eloquent cast, but both the MySQL and
                    // SQLite grammars json_encode an array value in prepareBindingsForUpdate,
                    // so the column still receives JSON. Proven by the repository test rather
                    // than assumed — read back through the `array` cast, it must come out a list.
                    'tags' => $payload->tags,
                    'context' => $payload->context,
                    'important' => $payload->important,
                    'urgent' => $payload->urgent,
                    'updated_at' => now(),
                ]);
        } catch (QueryException $e) {
            throw new ItemPersistenceException((string) $e->getCode());
        }

        if ($affected === 0) {
            throw Item::query()->whereKey($itemId)->exists()
                ? ItemActionNotAllowedException::editAttributesInTrash()
                : new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->readBack($itemId);
    }

    public function listByBucket(GtdBucket $bucket): Collection
    {
        return Item::query()
            ->where('bucket', $bucket->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Item $item): ItemDto => $this->toDto($item))
            ->values();
    }

    public function emptyTrash(): int
    {
        try {
            // One statement, scoped to the bucket. Safe to repeat: a second call deletes
            // nothing and returns 0.
            return Item::query()
                ->where('bucket', GtdBucket::Trash->value)
                ->delete();
        } catch (QueryException $e) {
            throw new ItemPersistenceException((string) $e->getCode());
        }
    }

    /**
     * Read a row back after a guarded write. Shared by all three write paths so they cannot
     * drift on the deleted-between-update-and-read case.
     *
     * @throws ItemNotFoundException the row vanished between the update and this read
     */
    private function readBack(int $itemId): ItemDto
    {
        $item = Item::query()->find($itemId);

        if ($item === null) {
            // Deleted between the update and the read. Nothing to return, and the caller must
            // not be handed a DTO for a row that no longer exists.
            throw new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->toDto($item);
    }

    private function toDto(Item $item): ItemDto
    {
        return new ItemDto(
            id: (int) $item->getKey(),
            title: $item->title,
            note: $item->note,
            bucket: $item->bucket,
            // Nullable at the schema level ($table->timestamps()), so a row written
            // outside Eloquent can carry nulls. Empty reads as visibly absent; an
            // invented timestamp would look real.
            createdAt: $item->created_at?->toIso8601String() ?? '',
            updatedAt: $item->updated_at?->toIso8601String() ?? '',
            dueDate: $item->due_date?->toDateString(),
            // array_values keeps the DTO's list<string> promise honest: the json cast
            // returns whatever json_decode produced, which need not be a packed list.
            tags: $item->tags === null ? null : array_values($item->tags),
            context: $item->context,
            important: $item->important,
            urgent: $item->urgent,
            delegatedTo: $item->delegated_to,
            completedAt: $item->completed_at?->toIso8601String(),
        );
    }
}
