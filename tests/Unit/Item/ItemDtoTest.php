<?php

use App\Domain\Item\GtdBucket;
use App\Dto\ItemDto;

it('serializes an item with the bucket as its backing string', function () {
    $dto = new ItemDto(
        id: 7,
        title: 'buy milk',
        note: null,
        bucket: GtdBucket::Inbox,
        createdAt: '2026-09-07T10:00:00+00:00',
        updatedAt: '2026-09-07T10:00:00+00:00',
    );

    expect($dto->toArray())->toBe([
        'id' => 7,
        'title' => 'buy milk',
        'note' => null,
        'bucket' => 'inbox',
        'dueDate' => null,
        'tags' => null,
        'context' => null,
        'important' => null,
        'urgent' => null,
        'createdAt' => '2026-09-07T10:00:00+00:00',
        'updatedAt' => '2026-09-07T10:00:00+00:00',
    ]);
});

it('defaults every dormant metadata field to null', function () {
    $dto = new ItemDto(
        id: 1,
        title: 'idea',
        note: 'longer thought',
        bucket: GtdBucket::Inbox,
        createdAt: '2026-09-07T10:00:00+00:00',
        updatedAt: '2026-09-07T10:00:00+00:00',
    );

    expect($dto->dueDate)->toBeNull()
        ->and($dto->tags)->toBeNull()
        ->and($dto->context)->toBeNull()
        ->and($dto->important)->toBeNull()
        ->and($dto->urgent)->toBeNull();
});

it('round-trips through fromArray and toArray', function () {
    $payload = [
        'id' => 3,
        'title' => 'ship the slice',
        'note' => "line one\nline two",
        'bucket' => 'next_actions',
        'dueDate' => '2026-09-30',
        'tags' => ['work', 'urgent'],
        'context' => '@computer',
        'important' => true,
        'urgent' => false,
        'createdAt' => '2026-09-07T10:00:00+00:00',
        'updatedAt' => '2026-09-07T11:00:00+00:00',
    ];

    expect(ItemDto::fromArray($payload)->toArray())->toBe($payload);
});

it('exposes the same shape through jsonSerialize', function () {
    $dto = new ItemDto(
        id: 2,
        title: 'idea',
        note: null,
        bucket: GtdBucket::Trash,
        createdAt: '2026-09-07T10:00:00+00:00',
        updatedAt: '2026-09-07T10:00:00+00:00',
    );

    expect($dto->jsonSerialize())->toBe($dto->toArray());
});
