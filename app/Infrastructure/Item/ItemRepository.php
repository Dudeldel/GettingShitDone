<?php

namespace App\Infrastructure\Item;

use App\Domain\Clarify\ClarifyOutcome;
use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
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
                    'delegation_done' => $outcome->delegatedTo === null ? null : false,
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

        $item = Item::query()->find($itemId);

        if ($item === null) {
            // Deleted between the update and the read. Nothing to return, and the caller must
            // not be handed a DTO for a row that no longer exists.
            throw new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        return $this->toDto($item);
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
            delegationDone: $item->delegation_done,
        );
    }
}
