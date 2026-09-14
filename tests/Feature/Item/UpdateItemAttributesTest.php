<?php

use App\Const\ItemConst;
use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editing an item's five attribute columns (FR-011 + FR-013), end to end.
 *
 * Same durability oracle as every write path since S-01: a 200 proves the service returned,
 * not that the row changed, so every write is read back through a SEPARATE request.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('sets all five attributes on an item', function () {
    $id = seedItem('napisać raport', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(
        dueDate: '2026-09-30',
        tags: ['work', 'deep'],
        context: '@computer',
        important: true,
        urgent: false,
    ))->assertStatus(Response::HTTP_OK);

    $read = test()->getJson('/api/items?bucket=next_actions')->json('0');

    expect($read['dueDate'])->toBe('2026-09-30')
        ->and($read['tags'])->toBe(['work', 'deep'])
        ->and($read['context'])->toBe('@computer')
        ->and($read['important'])->toBeTrue()
        ->and($read['urgent'])->toBeFalse();
});

it('leaves the bucket exactly where it was', function () {
    // THE invariant of this slice. A date is an attribute, never a destination: the Calendar
    // view is derived from due_date precisely so that dating a Next Action does not reclassify
    // it. An update array that picked up `bucket` would pass every assertion above and still
    // destroy the item's classification.
    $id = seedItem('zadzwonić do kliniki', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '2026-09-30'))
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'next_actions');

    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::NextActions);
    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(0);
});

it('clears every attribute when the payload is all nulls', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);
    test()->postJson("/api/items/{$id}/attributes", itemAttributes(
        dueDate: '2026-09-30', tags: ['work'], context: '@computer', important: true, urgent: true,
    ))->assertStatus(Response::HTTP_OK);

    // Full replacement: the same verb that set them clears them, which is why there is no
    // second endpoint for "remove the date".
    test()->postJson("/api/items/{$id}/attributes", itemAttributes())
        ->assertStatus(Response::HTTP_OK);

    $read = test()->getJson('/api/items?bucket=next_actions')->json('0');

    expect($read['dueDate'])->toBeNull()
        ->and($read['tags'])->toBeNull()
        ->and($read['context'])->toBeNull()
        ->and($read['important'])->toBeNull()
        ->and($read['urgent'])->toBeNull();
});

it('stores tags as a list that survives the json column', function () {
    // A query-builder update bypasses the Eloquent cast, so this proves the driver grammar
    // really encoded the array rather than the assumption that it would. Read back through the
    // `array` cast it must be a packed list — the shape ItemDto declares and S-01's F6 warned
    // was unguaranteed.
    $id = seedItem('cokolwiek', GtdBucket::Projects);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(tags: ['b' => 'work', 'a' => 'deep']))
        ->assertStatus(Response::HTTP_OK);

    $stored = Item::query()->find($id)->tags;

    expect($stored)->toBe(['work', 'deep'])
        ->and(array_keys($stored))->toBe([0, 1]);
});

it('normalises tags on the way in', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(tags: [' Work ', 'work', '   ', 'home']))
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('tags', ['Work', 'home']);
});

it('accepts a date in the past, because overdue is a real state', function () {
    $id = seedItem('zaległy podatek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '2020-01-01'))
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('dueDate', '2020-01-01');
});

it('does not disturb the columns it has no business touching', function () {
    // The S-11 F2 defect class: a write that quietly clobbers a sibling column. completed_at
    // and the item's text are written by other verbs entirely, and an attribute edit must not
    // be able to reach them.
    $id = seedItem('oddzwonić do Marka', GtdBucket::NextActions);
    $completedAt = test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK)
        ->json('completedAt');

    // Move the clock so a re-stamped completed_at is not string-identical to a carried one.
    test()->travel(1)->minutes();

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(context: '@phone'))
        ->assertStatus(Response::HTTP_OK);

    $read = test()->getJson('/api/items?bucket=next_actions')->json('0');

    expect($read['completedAt'])->toBe($completedAt)
        ->and($read['title'])->toBe('oddzwonić do Marka');
});

it('refuses to edit an item in the Trash', function () {
    $id = seedItem('stary newsletter', GtdBucket::Trash);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(context: '@computer'))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    expect(Item::query()->find($id)->context)->toBeNull();
});

it('returns 404 for an item that does not exist', function () {
    test()->postJson('/api/items/999999/attributes', itemAttributes(context: '@computer'))
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

it('rejects an unauthenticated edit', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);
    app('auth')->forgetGuards();

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(context: '@computer'))
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('requires every field to be present, because the write is a replacement', function () {
    // An omitted key would be ambiguous — "leave it alone" or "clear it" — and the two
    // readings differ on exactly the operation users reach for most: removing a date.
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", ['dueDate' => '2026-09-30'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['tags', 'context', 'important', 'urgent']);
});

it('rejects a malformed date rather than storing something unreadable', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '30-09-2026'))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['dueDate']);

    expect(Item::query()->find($id)->due_date)->toBeNull();
});

it('rejects a context longer than the column holds', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(
        context: str_repeat('a', ItemConst::CONTEXT_MAX_LENGTH + 1),
    ))->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['context']);
});

it('rejects more tags than an item is allowed', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);
    // Distinct values, or the normaliser would collapse them below the cap before the rule runs.
    $tooMany = array_map(static fn (int $i): string => 'tag'.$i, range(1, ItemConst::TAGS_MAX_COUNT + 1));

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(tags: $tooMany))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['tags']);
});

it('rejects a tag longer than one label', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(
        tags: [str_repeat('a', ItemConst::TAG_MAX_LENGTH + 1)],
    ))->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['tags.0']);
});

it('answers 422 rather than 500 when a field arrives as the wrong type', function () {
    // The is_string()/is_array() guards in prepareForValidation exist for exactly this: a
    // nullable helper would raise a TypeError here and answer 500.
    $id = seedItem('cokolwiek', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(context: ['@computer']))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['context']);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(tags: 'work'))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['tags']);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(important: 'maybe'))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['important']);
});

it('records the edit without the attribute values', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);
    Log::spy();

    test()->postJson("/api/items/{$id}/attributes", itemAttributes(context: '@pin-4711'))
        ->assertStatus(Response::HTTP_OK);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($id) {
        return $message === 'item.attributes.success'
            && $context['item_id'] === $id
            && ! str_contains(json_encode($context), '4711');
    });
});
