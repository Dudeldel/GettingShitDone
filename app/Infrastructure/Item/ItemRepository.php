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
        $item = Item::query()->find($itemId);

        if ($item === null) {
            throw new ItemNotFoundException('No item with id '.$itemId.'.');
        }

        // Inbox-only, by design: re-filing an already-bucketed item is FR-010, deferred to
        // v2. Checked before the write so a second clarify cannot quietly overwrite the
        // destination the user already chose.
        if ($item->bucket !== GtdBucket::Inbox) {
            throw new ItemNotInInboxException('Item '.$itemId.' has already been clarified.');
        }

        try {
            // Explicit assignment rather than mass assignment: bucket is fillable, and the
            // delegation columns deliberately are not, so an array arriving from anywhere
            // near a request can never reach them.
            $item->bucket = $outcome->bucket;
            $item->delegated_to = $outcome->delegatedTo;
            $item->delegation_done = $outcome->delegatedTo === null ? null : false;
            $item->save();
        } catch (QueryException $e) {
            // Same reasoning as create(): Laravel interpolates bindings into the message,
            // so the driver exception must not travel.
            throw new ItemPersistenceException((string) $e->getCode());
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
