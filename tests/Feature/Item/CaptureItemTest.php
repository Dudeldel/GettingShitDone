<?php

use App\Const\ItemConst;
use App\Domain\Item\GtdBucket;
use App\Exceptions\ItemPersistenceException;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    $this->postJson('/api/items', ['title' => str_repeat('a', ItemConst::TITLE_MAX_LENGTH + 1)])
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

it('ignores a client-supplied bucket and metadata', function () {
    Sanctum::actingAs(User::factory()->create());

    // `bucket` is fillable on the model, so this is the test that has to be able to fail:
    // the invariant is that capture always targets the Inbox, whatever the body says.
    $this->postJson('/api/items', [
        'title' => 'idea',
        'bucket' => 'trash',
        'id' => 999,
        'important' => true,
        'dueDate' => '2026-12-31',
    ])->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('bucket', 'inbox')
        ->assertJsonPath('important', null)
        ->assertJsonPath('dueDate', null);

    $item = Item::query()->sole();
    expect($item->bucket)->toBe(GtdBucket::Inbox)
        ->and($item->important)->toBeNull();
});

it('reports a write failure as 500 without echoing the payload', function () {
    Sanctum::actingAs(User::factory()->create());
    Schema::drop('items');

    $response = $this->postJson('/api/items', ['title' => 'my bank pin is 4711']);

    $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
    expect($response->getContent())->not->toContain('4711');
});

it('keeps the captured text out of the failure path entirely', function () {
    Sanctum::actingAs(User::factory()->create());
    Schema::drop('items');

    try {
        $this->withoutExceptionHandling()->postJson('/api/items', ['title' => 'my bank pin is 4711']);
        $this->fail('expected the capture to fail');
    } catch (ItemPersistenceException $e) {
        // Neither the message nor a chained previous may carry the interpolated bindings.
        expect($e->getMessage())->not->toContain('4711')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('rejects a note longer than the limit', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', [
        'title' => 'idea',
        'note' => str_repeat('a', ItemConst::NOTE_MAX_LENGTH + 1),
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('strips control characters from the note as well as the title', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/items', ['title' => 'idea', 'note' => "line\x00 two"])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('note', 'line two');

    expect(Item::query()->value('note'))->toBe('line two');
});
