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
            createdAt: $item->created_at->toIso8601String(),
            updatedAt: $item->updated_at->toIso8601String(),
            dueDate: $item->due_date?->toDateString(),
            tags: $item->tags,
            context: $item->context,
            important: $item->important,
            urgent: $item->urgent,
        );
    }
}
