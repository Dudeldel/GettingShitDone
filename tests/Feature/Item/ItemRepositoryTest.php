<?php

use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Models\Item;

beforeEach(function () {
    // Resolved through the container so the AppServiceProvider binding is exercised too.
    $this->items = app(ItemRepositoryInterface::class);
});

it('creates an item in the given bucket and returns a DTO', function () {
    $dto = $this->items->create(new CaptureItemPayload('buy milk', null), GtdBucket::Inbox);

    expect($dto)->toBeInstanceOf(ItemDto::class)
        ->and($dto->title)->toBe('buy milk')
        ->and($dto->note)->toBeNull()
        ->and($dto->bucket)->toBe(GtdBucket::Inbox)
        ->and($dto->id)->toBeGreaterThan(0);

    expect(Item::query()->count())->toBe(1);
});

it('persists the optional note', function () {
    $dto = $this->items->create(new CaptureItemPayload('idea', 'the longer thought'), GtdBucket::Inbox);

    expect($dto->note)->toBe('the longer thought');
});

it('leaves the dormant metadata fields null on capture', function () {
    $dto = $this->items->create(new CaptureItemPayload('idea', null), GtdBucket::Inbox);

    expect($dto->dueDate)->toBeNull()
        ->and($dto->tags)->toBeNull()
        ->and($dto->context)->toBeNull()
        ->and($dto->important)->toBeNull()
        ->and($dto->urgent)->toBeNull();
});

it('lists only the items in the requested bucket', function () {
    $this->items->create(new CaptureItemPayload('in the inbox', null), GtdBucket::Inbox);
    $this->items->create(new CaptureItemPayload('already trashed', null), GtdBucket::Trash);

    $inbox = $this->items->listByBucket(GtdBucket::Inbox);

    expect($inbox)->toHaveCount(1)
        ->and($inbox->first()->title)->toBe('in the inbox');
});

it('lists the newest item first', function () {
    $this->items->create(new CaptureItemPayload('older', null), GtdBucket::Inbox);
    $this->items->create(new CaptureItemPayload('newer', null), GtdBucket::Inbox);

    // Both rows can share a created_at second, so the id tiebreaker is what makes this
    // deterministic — that is exactly what the ordering contract promises.
    expect($this->items->listByBucket(GtdBucket::Inbox)->pluck('title')->all())
        ->toBe(['newer', 'older']);
});

it('returns an empty collection for a bucket with no items', function () {
    expect($this->items->listByBucket(GtdBucket::Projects))->toBeEmpty();
});

it('sorts by created_at before falling back to the id tiebreaker', function () {
    $this->items->create(new CaptureItemPayload('backdated', null), GtdBucket::Inbox);
    $newer = $this->items->create(new CaptureItemPayload('recent', null), GtdBucket::Inbox);

    // The NEWER id gets the OLDER timestamp, so id desc alone would order these wrongly.
    Item::query()->whereKey($newer->id)->update(['created_at' => now()->subDay()]);

    expect($this->items->listByBucket(GtdBucket::Inbox)->pluck('title')->all())
        ->toBe(['backdated', 'recent']);
});
