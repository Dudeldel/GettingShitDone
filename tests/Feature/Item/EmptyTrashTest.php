<?php

use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * The product's only irreversible operation.
 *
 * Two things matter here and they are different: that the Trash really empties, and that
 * nothing else does. The second is the one worth losing sleep over — a purge with the wrong
 * blast radius destroys work the user never asked to discard, and there is no undo.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('permanently discards everything in the Trash', function () {
    seedItem('junk one', GtdBucket::Trash);
    seedItem('junk two', GtdBucket::Trash);

    test()->deleteJson('/api/trash')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('deleted', 2);

    // Read back through a separate request: the response said 2, the list proves it.
    test()->getJson('/api/items?bucket=trash')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(0);
});

it('leaves every other bucket untouched', function () {
    // The blast-radius guard. If this ever goes green with a broken scope, the user loses
    // their whole system rather than their bin.
    seedItem('junk', GtdBucket::Trash);
    seedItem('still in the inbox', GtdBucket::Inbox);
    seedItem('a next action', GtdBucket::NextActions);
    seedItem('a reference', GtdBucket::Reference);
    seedItem('waiting on Ania', GtdBucket::Delegation);

    test()->deleteJson('/api/trash')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('deleted', 1);

    expect(Item::query()->count())->toBe(4);
    test()->getJson('/api/items')->assertJsonCount(1);
    test()->getJson('/api/items?bucket=next_actions')->assertJsonCount(1);
    test()->getJson('/api/items?bucket=reference')->assertJsonCount(1);
    test()->getJson('/api/items?bucket=delegation')->assertJsonCount(1);
});

it('succeeds on an already-empty Trash', function () {
    // Idempotent: a second click, a retry or a double-submit must not be an error.
    test()->deleteJson('/api/trash')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('deleted', 0);
});

it('rejects an unauthenticated purge', function () {
    seedItem('junk', GtdBucket::Trash);
    app('auth')->forgetGuards();

    test()->deleteJson('/api/trash')->assertStatus(Response::HTTP_UNAUTHORIZED);

    // Nothing was destroyed on the way to the 401.
    expect(Item::query()->count())->toBe(1);
});

it('emits the purge domain event with a count and no item text', function () {
    seedItem('my bank pin is 4711', GtdBucket::Trash);
    Log::spy();

    test()->deleteJson('/api/trash')->assertStatus(Response::HTTP_OK);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $message === 'trash.emptied.success'
            && $context['event']['outcome'] === 'success'
            && $context['deleted_count'] === 1
            && ! str_contains(json_encode($context), '4711');
    });
});
