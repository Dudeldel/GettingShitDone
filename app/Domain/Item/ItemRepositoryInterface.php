<?php

namespace App\Domain\Item;

use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
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
}
