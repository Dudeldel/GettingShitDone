<?php

use App\Domain\Item\GtdBucket;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

it('lists only the Inbox by default', function () {
    Sanctum::actingAs(User::factory()->create());
    seedItem('in the inbox', GtdBucket::Inbox);
    seedItem('already trashed', GtdBucket::Trash);

    $this->getJson('/api/items')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(1)
        ->assertJsonPath('0.title', 'in the inbox');
});

it('lists the newest item first', function () {
    Sanctum::actingAs(User::factory()->create());
    seedItem('older', GtdBucket::Inbox);
    seedItem('newer', GtdBucket::Inbox);

    $this->getJson('/api/items')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('0.title', 'newer')
        ->assertJsonPath('1.title', 'older');
});

it('filters by an explicit bucket', function () {
    Sanctum::actingAs(User::factory()->create());
    seedItem('in the inbox', GtdBucket::Inbox);
    seedItem('already trashed', GtdBucket::Trash);

    $this->getJson('/api/items?bucket=trash')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(1)
        ->assertJsonPath('0.title', 'already trashed');
});

it('returns an empty list for a bucket with no items', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/items?bucket=projects')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(0);
});

it('rejects an unknown bucket', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/items?bucket=not_a_bucket')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('rejects an unauthenticated listing', function () {
    $this->getJson('/api/items')->assertStatus(Response::HTTP_UNAUTHORIZED);
});
