<?php

use App\Const\ItemConst;
use App\Domain\Item\GtdBucket;
use App\Exceptions\ItemPersistenceException;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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

/**
 * The durability oracle for the PRD guardrail "capture never loses an entry".
 *
 * A 201 only attests that Eloquent's insert call returned: ItemRepository::create maps the
 * same in-memory model it just built, with no re-read, so the 201 body's timestamps are the
 * app's clock and its dormant fields are null whatever the schema says. Reading the item back
 * through a SECOND request is what proves it is really there — a fresh FormRequest, a freshly
 * resolved service and repository (both bound, not singletons), and the real listByBucket
 * query rather than the object the POST serialized.
 *
 * A literal "fresh session" is out of reach here: the Feature suite runs SQLite :memory: under
 * RefreshDatabase, so the write is rolled back and the database dies with the connection. This
 * asserts readability through the real read path, which is the strongest available oracle.
 */
it('reads a captured item back through a separate list request', function () {
    Sanctum::actingAs(User::factory()->create());

    $id = $this->postJson('/api/items', ['title' => 'ring the dentist'])
        ->assertStatus(Response::HTTP_CREATED)
        ->json('id');

    $this->getJson('/api/items')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $id)
        ->assertJsonPath('0.title', 'ring the dentist')
        ->assertJsonPath('0.bucket', 'inbox');
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
    //
    // Verified by deliberate breakage (S-06): loosening #[Fillable] ALONE leaves this green,
    // because ItemRepository::create() builds an explicit three-key array from a payload that
    // has no metadata fields — #[Fillable] is the second lock, not the one doing the work.
    // It goes red once request data actually reaches create(), which is the regression a
    // "let users tag at capture time" change would really introduce. So this guards the
    // endpoint's behaviour, not the attribute on the model.
    $this->postJson('/api/items', [
        'title' => 'idea',
        'bucket' => 'trash',
        'id' => 999,
        'important' => true,
        'urgent' => true,
        'dueDate' => '2026-12-31',
        'tags' => ['work'],
        'context' => '@computer',
    ])->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('bucket', 'inbox')
        ->assertJsonPath('important', null)
        ->assertJsonPath('urgent', null)
        ->assertJsonPath('dueDate', null)
        ->assertJsonPath('tags', null)
        ->assertJsonPath('context', null);

    $item = Item::query()->sole();
    expect($item->bucket)->toBe(GtdBucket::Inbox)
        ->and($item->important)->toBeNull()
        // All five, not just the two this test started with. S-06 gave the attributes a write
        // path of their own, which makes "capture still cannot set them" a claim about five
        // columns rather than two — and an untested column is where a loosened #[Fillable]
        // would slip through unnoticed.
        ->and($item->urgent)->toBeNull()
        ->and($item->due_date)->toBeNull()
        ->and($item->tags)->toBeNull()
        ->and($item->context)->toBeNull();
});

it('reports a write failure as 500 without echoing the payload', function () {
    Sanctum::actingAs(User::factory()->create());
    Schema::drop('items');

    $response = $this->postJson('/api/items', ['title' => 'my bank pin is 4711']);

    $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
    expect($response->getContent())->not->toContain('4711');

    // The literal is pinned here on purpose. bootstrap/app.php is its only declaration —
    // there is no constant to derive from — and the SPA reads exactly this field
    // (api.ts parses `message` out of any non-2xx body and renders it to the user).
    // Changing the message without changing this test would silently reword the UI.
    $response->assertJsonPath('message', 'The item could not be saved. Please try again.');
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

/**
 * The other half of the guardrail: a write that fails must leave nothing behind, and the user
 * must not be told otherwise.
 *
 * The failure is forced with a unique index rather than by dropping the table, because the
 * assertion needs the read path to still work afterwards — a dropped table would make the
 * follow-up GET fail too and prove nothing. The duplicate insert raises a real QueryException
 * inside the real ItemRepository, so the whole chain (QueryException -> ItemPersistenceException
 * -> the fixed-message 500) is exercised, not simulated.
 *
 * Today capture is one statement, so a partial write is impossible and this is close to
 * tautological. It earns its place as the guard for when capture stops being one statement:
 * there is no transaction anywhere in the item path, so a second write added later would have
 * nothing holding it to the first.
 */
it('leaves nothing readable when the write fails', function () {
    Sanctum::actingAs(User::factory()->create());
    Schema::table('items', function (Blueprint $table) {
        $table->unique('title');
    });

    $this->postJson('/api/items', ['title' => 'the only survivor'])
        ->assertStatus(Response::HTTP_CREATED);

    $this->postJson('/api/items', ['title' => 'the only survivor'])
        ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
        // The failure body carries the fixed message and no item — nothing a client could
        // mistake for a confirmation.
        ->assertJsonPath('message', 'The item could not be saved. Please try again.');

    // The read path still works, and the failed capture is simply not in it.
    $this->getJson('/api/items')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonCount(1);
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
