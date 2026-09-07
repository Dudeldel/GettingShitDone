<?php

namespace App\Infrastructure\Item;

use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Models\Item;
use Illuminate\Support\Collection;

class ItemRepository implements ItemRepositoryInterface
{
    public function create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto
    {
        $item = Item::query()->create([
            'title' => $payload->title,
            'note' => $payload->note,
            'bucket' => $bucket,
        ]);

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
        );
    }
}
