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
    $completedAt = test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK)
        ->json('completedAt');

    // Move the clock before the second write. Without this the two land in the same second and
    // a re-stamped completed_at is string-identical to a carried one — the assertion below
    // would then pass against exactly the write it exists to catch.
    test()->travel(1)->minutes();

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'calendar'])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'calendar');

    $moved = test()->getJson('/api/items?bucket=calendar')->json('0');

    // The ORIGINAL timestamp, not merely "something non-null". `not->toBeNull()` cannot tell a
    // carried completion from a re-stamped one, so a refile writing completed_at => now() would
    // destroy when the work was actually finished and still pass.
    expect($moved['completedAt'])->toBe($completedAt);
});

it('drops a completion when the move lands somewhere done means nothing', function () {
    // The mirror image of the test above, and the reason it is not a contradiction: Reference,
    // Someday/Maybe and Trash cannot hold a completion — setCompleted() refuses them — so an
    // item carrying one INTO them would render "✓ Done" with no control able to clear it again.
    $id = seedItem('artykuł o GTD', GtdBucket::NextActions);
    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK);

    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'reference'])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('completedAt', null);

    expect(test()->getJson('/api/items?bucket=reference')->json('0.completedAt'))->toBeNull();

    // And the item is not stranded: it can be completed again once it is back somewhere that
    // means something.
    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'next_actions'])
        ->assertStatus(Response::HTTP_OK);
    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK);
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
