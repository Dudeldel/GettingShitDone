<?php

use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * The clarify endpoint end to end.
 *
 * The durability oracle is the one established by the Phase 1 test rollout: a transition is
 * only proven by reading the item back through a SEPARATE request. A 200 means the service
 * returned; it does not mean the row moved.
 */
function captureForClarify(string $title = 'ring the dentist'): int
{
    return (int) test()->postJson('/api/items', ['title' => $title])
        ->assertStatus(Response::HTTP_CREATED)
        ->json('id');
}

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('routes an actionable single-step item to Next Actions', function () {
    $id = captureForClarify();

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => true,
        'singleStep' => true,
        'delegable' => false,
    ])->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'next_actions');

    // It is really in Next Actions...
    test()->getJson('/api/items?bucket=next_actions')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $id);

    // ...and really gone from the Inbox. Both halves matter: an item that appeared in its
    // destination while still listed in the Inbox would be in two buckets at once.
    test()->getJson('/api/items')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(0);
});

it('routes a delegable item to Delegation and keeps the who/what note', function () {
    $id = captureForClarify('chase the invoice');

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => true,
        'singleStep' => true,
        'delegable' => true,
        'delegatedTo' => 'Ania — sent the contract on Tuesday',
    ])->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'delegation')
        ->assertJsonPath('delegatedTo', 'Ania — sent the contract on Tuesday')
        ->assertJsonPath('delegationDone', false);

    test()->getJson('/api/items?bucket=delegation')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('0.delegatedTo', 'Ania — sent the contract on Tuesday');
});

it('routes a multi-step item to Projects', function () {
    $id = captureForClarify('replace the boiler');

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => true,
        'singleStep' => false,
    ])->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'projects');

    test()->getJson('/api/items?bucket=projects')->assertJsonPath('0.id', $id);
});

it('routes a non-actionable item to the destination the user picked', function () {
    $id = captureForClarify('that article about sourdough');

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => false,
        'nonActionableDestination' => 'reference',
    ])->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'reference');

    test()->getJson('/api/items?bucket=reference')->assertJsonPath('0.id', $id);
});

it('sends an item straight to a bucket on the quick-route', function () {
    $id = captureForClarify('junk');

    test()->postJson("/api/items/{$id}/clarify", ['quickRouteBucket' => 'trash'])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'trash');

    test()->getJson('/api/items')->assertJsonCount(0);
});

it('refuses to clarify an item that has already been clarified', function () {
    $id = captureForClarify();
    test()->postJson("/api/items/{$id}/clarify", ['quickRouteBucket' => 'reference'])
        ->assertStatus(Response::HTTP_OK);

    // 409, not 404: the item exists, the door is closed. Re-filing is FR-010, deferred to v2.
    test()->postJson("/api/items/{$id}/clarify", ['quickRouteBucket' => 'trash'])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', 'That item has already been clarified.');

    // The second attempt must not have moved it.
    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::Reference);
});

it('returns 404 for an item that does not exist', function () {
    test()->postJson('/api/items/999999/clarify', ['quickRouteBucket' => 'trash'])
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

it('refuses to delegate without saying who is being waited on', function () {
    $id = captureForClarify();

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => true,
        'singleStep' => true,
        'delegable' => true,
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Still in the Inbox — a rejected clarify must not half-move the item.
    test()->getJson('/api/items')->assertJsonCount(1);
});

it('refuses a non-actionable destination outside the three FR-004 offers', function () {
    $id = captureForClarify();

    test()->postJson("/api/items/{$id}/clarify", [
        'actionable' => false,
        'nonActionableDestination' => 'next_actions',
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('refuses a payload that mixes the quick-route with tree answers', function () {
    $id = captureForClarify();

    // The two modes are mutually exclusive; accepting both would let a client name a
    // destination while pretending to have answered the tree.
    test()->postJson("/api/items/{$id}/clarify", [
        'quickRouteBucket' => 'trash',
        'actionable' => true,
        'singleStep' => true,
        'delegable' => false,
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('rejects an unauthenticated clarify', function () {
    $id = captureForClarify();
    app('auth')->forgetGuards();

    test()->postJson("/api/items/{$id}/clarify", ['quickRouteBucket' => 'trash'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('emits the clarify domain event with the destination and no captured text', function () {
    $id = captureForClarify('my bank pin is 4711');
    Log::spy();

    test()->postJson("/api/items/{$id}/clarify", ['quickRouteBucket' => 'reference'])
        ->assertStatus(Response::HTTP_OK);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($id) {
        return $message === 'item.clarified.success'
            && $context['event']['outcome'] === 'success'
            && $context['item_id'] === $id
            && $context['bucket'] === 'reference'
            && ! str_contains(json_encode($context), '4711');
    });
});
