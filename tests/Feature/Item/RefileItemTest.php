<?php

use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-filing a clarified item (FR-010), end to end.
 *
 * Same durability oracle as every write path since S-01: a 200 proves the service returned,
 * not that the row moved, so every transition is read back through a SEPARATE request.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('moves a clarified item to the destination the user picked', function () {
    $id = seedItem('przeczytać o sourdough', GtdBucket::SomedayMaybe);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'next_actions'])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'next_actions');

    test()->getJson('/api/items?bucket=next_actions')->assertJsonPath('0.id', $id);
    // And really gone from where it was — FR-008 is exactly one bucket, not at least one.
    test()->getJson('/api/items?bucket=someday_maybe')->assertJsonCount(0);
});

it('carries an existing completion across the move', function () {
    // THE test for this slice. `clarify` writes completed_at => null in the same statement as
    // the bucket, and it is safe there only because of its Inbox guard. A re-file that reused
    // that array would silently un-finish a finished item the moment it was moved — a defect
    // no assertion on the bucket alone could see.
    $id = seedItem('oddzwonić do Marka', GtdBucket::NextActions);
    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'calendar'])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'calendar');

    $moved = test()->getJson('/api/items?bucket=calendar')->json('0');

    expect($moved['completedAt'])->not->toBeNull();
});

it('lets an item leave the Trash, because only emptying it is final', function () {
    $id = seedItem('stary newsletter', GtdBucket::Trash);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'reference'])
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=trash')->assertJsonCount(0);
    test()->getJson('/api/items?bucket=reference')->assertJsonPath('0.id', $id);
});

it('refuses the Inbox as a destination', function () {
    // Items arrive in the Inbox; they are not filed there. Re-filing into it would mean
    // un-clarifying something, which is a different operation nobody asked for.
    $id = seedItem('cokolwiek', GtdBucket::Reference);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'inbox'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['bucket']);

    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::Reference);
});

it('refuses to re-file an item that is still in the Inbox', function () {
    // An unclarified item wants clarify, not a move. 422 from the domain, not the edge:
    // the bucket asked for is perfectly legal, the item just is not ready for this verb.
    $id = seedItem('świeży pomysł', GtdBucket::Inbox);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'next_actions'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::Inbox);
});

it('refuses an unknown bucket with a validation error rather than a 500', function () {
    $id = seedItem('cokolwiek', GtdBucket::Reference);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'nonsense'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['bucket']);
});

it('returns 404 for an item that does not exist', function () {
    test()->postJson('/api/items/999999/refile', ['bucket' => 'reference'])
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

it('rejects an unauthenticated re-file', function () {
    $id = seedItem('cokolwiek', GtdBucket::Reference);
    app('auth')->forgetGuards();

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'trash'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('records the move without the item text', function () {
    $id = seedItem('my bank pin is 4711', GtdBucket::Reference);
    Log::spy();

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'projects'])
        ->assertStatus(Response::HTTP_OK);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($id) {
        return $message === 'item.refiled.success'
            && $context['item_id'] === $id
            && $context['bucket'] === 'projects'
            && ! str_contains(json_encode($context), '4711');
    });
});
