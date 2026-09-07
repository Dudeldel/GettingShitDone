<?php

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

it('captures an idea into the Inbox', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => 'buy milk'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('title', 'buy milk')
        ->assertJsonPath('bucket', 'inbox')
        ->assertJsonPath('note', null);

    expect(Item::query()->count())->toBe(1);
});

it('persists the optional note', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => 'idea', 'note' => 'the longer thought'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('note', 'the longer thought');
});

it('returns the dormant metadata fields as null', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => 'idea'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('dueDate', null)
        ->assertJsonPath('tags', null)
        ->assertJsonPath('context', null)
        ->assertJsonPath('important', null)
        ->assertJsonPath('urgent', null);
});

it('strips control characters before storing the captured text', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => "call\x07 Bob"])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('title', 'call Bob');

    expect(Item::query()->value('title'))->toBe('call Bob');
});

it('emits the capture domain event', function () {
    Sanctum::actingAs(User::factory()->create());
    Log::spy();

    $this->postJson('/api/items', ['title' => 'idea'])->assertStatus(Response::HTTP_CREATED);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $message === 'item.captured.success'
            && $context['event']['action'] === 'item.captured.success'
            && $context['event']['outcome'] === 'success'
            && $context['bucket'] === 'inbox';
    });
});

it('rejects a capture without a title', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', [])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(Item::query()->count())->toBe(0);
});

it('rejects a title longer than the column allows', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => str_repeat('a', 256)])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('rejects a whitespace-only title', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => '   '])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('rejects an unauthenticated capture', function () {
    $this->postJson('/api/items', ['title' => 'idea'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);

    expect(Item::query()->count())->toBe(0);
});
