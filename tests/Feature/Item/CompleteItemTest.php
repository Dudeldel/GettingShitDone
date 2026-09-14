<?php

use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marking an item done outside the two-minute timer.
 *
 * There is no FR for this — FR-006 covers only the timer's outcome and FR-007's done flag
 * belonged to Delegation alone. Nothing in the PRD let a user finish a Next Action, which is
 * the list that exists to be finished.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('marks an item done and reports when', function () {
    $id = seedItem('oddzwonić do Marka', GtdBucket::NextActions);

    $completedAt = test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK)
        ->json('completedAt');

    expect($completedAt)->not->toBeNull();
    expect(test()->getJson('/api/items?bucket=next_actions')->json('0.completedAt'))->not->toBeNull();
});

it('un-marks it again, because one click should not be irreversible', function () {
    // The Trash purge is this product's only irreversible operation and it is guarded by a
    // confirmation. A checkbox must not quietly become the second.
    $id = seedItem('oddzwonić do Marka', GtdBucket::NextActions);
    test()->postJson("/api/items/{$id}/complete", ['completed' => true])->assertStatus(Response::HTTP_OK);

    test()->postJson("/api/items/{$id}/complete", ['completed' => false])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('completedAt', null);

    expect(test()->getJson('/api/items?bucket=next_actions')->json('0.completedAt'))->toBeNull();
});

it('never moves the item it completes', function () {
    // The mirror of the re-file test: completing writes completed_at and nothing else. An
    // assertion on completedAt alone would pass against a write that also clobbered bucket.
    $id = seedItem('zaplanować urlop', GtdBucket::Projects);

    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('bucket', 'projects');

    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::Projects);
    test()->getJson('/api/items?bucket=projects')->assertJsonCount(1);
});

it('allows completion in each of the four action buckets', function () {
    foreach ([GtdBucket::NextActions, GtdBucket::Projects, GtdBucket::Calendar, GtdBucket::Delegation] as $bucket) {
        $id = seedItem('zobowiązanie', $bucket);

        test()->postJson("/api/items/{$id}/complete", ['completed' => true])
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('bucket', $bucket->value);
    }
});

it('refuses completion where done means nothing', function () {
    // FR-004 already split actionable from not. Marking a Reference article or a discarded
    // item "done" is a sentence nobody can interpret.
    foreach ([GtdBucket::Reference, GtdBucket::SomedayMaybe, GtdBucket::Trash, GtdBucket::Inbox] as $bucket) {
        $id = seedItem('nie-zobowiązanie', $bucket);

        test()->postJson("/api/items/{$id}/complete", ['completed' => true])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        expect(Item::query()->find($id)->completed_at)->toBeNull();
    }
});

it('requires the caller to say which way', function () {
    // "Mark done" and "un-mark" are different intentions; a missing field must not pick one.
    $id = seedItem('oddzwonić', GtdBucket::NextActions);

    test()->postJson("/api/items/{$id}/complete", [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['completed']);
});

it('returns 404 for an item that does not exist', function () {
    test()->postJson('/api/items/999999/complete', ['completed' => true])
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

it('rejects an unauthenticated completion', function () {
    $id = seedItem('oddzwonić', GtdBucket::NextActions);
    app('auth')->forgetGuards();

    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('records marking and clearing as different events, without the item text', function () {
    $id = seedItem('my bank pin is 4711', GtdBucket::NextActions);
    Log::spy();

    test()->postJson("/api/items/{$id}/complete", ['completed' => true])->assertStatus(Response::HTTP_OK);
    test()->postJson("/api/items/{$id}/complete", ['completed' => false])->assertStatus(Response::HTTP_OK);

    foreach (['item.completion.marked' => true, 'item.completion.cleared' => false] as $action => $completed) {
        Log::shouldHaveReceived('log')->withArgs(
            fn ($level, $message, $context) => $message === $action
                && $context['item_id'] === $id
                && $context['completed'] === $completed
                && ! str_contains(json_encode($context), '4711'),
        );
    }
});
