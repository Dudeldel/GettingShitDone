<?php

use App\Dto\Payload\ItemAttributesPayload;

it('carries all five attributes off a validated payload', function () {
    $payload = ItemAttributesPayload::fromArray([
        'dueDate' => '2026-09-30',
        'tags' => ['work', 'deep'],
        'context' => '@computer',
        'important' => true,
        'urgent' => false,
    ]);

    expect($payload->dueDate)->toBe('2026-09-30')
        ->and($payload->tags)->toBe(['work', 'deep'])
        ->and($payload->context)->toBe('@computer')
        ->and($payload->important)->toBeTrue()
        ->and($payload->urgent)->toBeFalse();
});

it('reads an all-null payload as a clear rather than as an absence', function () {
    // Full-replacement semantics: this is the shape the UI sends when the user empties every
    // field, and it must survive as five nulls rather than collapsing into "nothing to do".
    $payload = ItemAttributesPayload::fromArray([
        'dueDate' => null,
        'tags' => null,
        'context' => null,
        'important' => null,
        'urgent' => null,
    ]);

    expect($payload->dueDate)->toBeNull()
        ->and($payload->tags)->toBeNull()
        ->and($payload->context)->toBeNull()
        ->and($payload->important)->toBeNull()
        ->and($payload->urgent)->toBeNull();
});

it('keeps false distinct from null on the Eisenhower flags', function () {
    // S-08 derives four quadrants from these two, so "judged not important" (false) and "not
    // yet judged" (null) are different answers. A payload that folded false into null would
    // make the distinction unrepresentable before it ever reached the column.
    $payload = ItemAttributesPayload::fromArray([
        'dueDate' => null, 'tags' => null, 'context' => null,
        'important' => false, 'urgent' => null,
    ]);

    expect($payload->important)->toBeFalse()
        ->and($payload->urgent)->toBeNull();
});

it('casts the boolean rule\'s accepted spellings to real booleans', function () {
    // Laravel's `boolean` rule validates without casting, so "1" and 0 arrive as themselves.
    $payload = ItemAttributesPayload::fromArray([
        'dueDate' => null, 'tags' => null, 'context' => null,
        'important' => '1', 'urgent' => 0,
    ]);

    expect($payload->important)->toBeTrue()
        ->and($payload->urgent)->toBeFalse();
});

it('re-packs tags into a list even when fromArray is reached without the FormRequest', function () {
    $payload = ItemAttributesPayload::fromArray([
        'dueDate' => null, 'tags' => ['a' => 'x', 'b' => 'y'], 'context' => null,
        'important' => null, 'urgent' => null,
    ]);

    expect($payload->tags)->toBe(['x', 'y']);
});
