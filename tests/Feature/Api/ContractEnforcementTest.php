<?php

use App\Domain\Item\GtdBucket;
use App\Http\Requests\CaptureItemRequest;
use App\Http\Requests\RefileItemRequest;
use App\Http\Requests\UpdateItemAttributesRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PublishedContract;

/**
 * The documented boundary is the enforced one, on BOTH sides (test plan §2 Risk #4).
 *
 * The companion to ContractParityTest. That one proves every enforced rule survived into the
 * document; this one proves the document's promises are real — a constraint nothing applies is the
 * opposite failure and the structural check cannot see it.
 *
 * Every limit is read from the PUBLISHED schema, never restated here. Restating one would make the
 * test agree with itself; reading it means the document is the oracle and the endpoint is the
 * system under test.
 *
 * Scope note: login publishes no length or enum constraint at all, and register is gated to a
 * single account so each attempt mutates state the next one depends on. Both are therefore out,
 * and refile is in — its state costs one seeded item and its enum is exactly the one that drifted.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('enforces the published length limit on captured text, and accepts the limit itself', function () {
    foreach (['title', 'note'] as $field) {
        $limit = PublishedContract::property(CaptureItemRequest::class, $field)['maxLength'];

        // At the limit: no error FOR THIS FIELD. Not "is 2xx" — a sibling field could fail for
        // its own reasons and would mask the answer.
        test()->postJson('/api/items', ['title' => 'x', $field => str_repeat('a', $limit)])
            ->assertJsonMissingValidationErrors([$field]);

        // One past it: the error must name this field. A bare 422 could come from anywhere,
        // which is the "a 422 means the right rule fired" assumption §2 asks us to challenge.
        test()->postJson('/api/items', ['title' => 'x', $field => str_repeat('a', $limit + 1)])
            ->assertJsonValidationErrors([$field]);
    }
});

it('enforces the published limits on item attributes, and accepts them', function () {
    $id = seedItem('cokolwiek', GtdBucket::NextActions);
    $context = PublishedContract::property(UpdateItemAttributesRequest::class, 'context');
    $tags = PublishedContract::property(UpdateItemAttributesRequest::class, 'tags');

    $post = fn (array $override) => test()->postJson("/api/items/{$id}/attributes", itemAttributes(...$override));

    $post(['context' => str_repeat('a', $context['maxLength'])])->assertJsonMissingValidationErrors(['context']);
    $post(['context' => str_repeat('a', $context['maxLength'] + 1)])->assertJsonValidationErrors(['context']);

    // Distinct values: the normaliser de-duplicates case-insensitively, so repeating one tag
    // would collapse the array below the cap and test nothing.
    $distinct = static fn (int $n): array => array_map(static fn (int $i): string => 'tag'.$i, range(1, $n));

    $post(['tags' => $distinct($tags['maxItems'])])->assertJsonMissingValidationErrors(['tags']);
    $post(['tags' => $distinct($tags['maxItems'] + 1)])->assertJsonValidationErrors(['tags']);

    $post(['tags' => [str_repeat('a', $tags['items']['maxLength'])]])->assertJsonMissingValidationErrors(['tags.0']);
    $post(['tags' => [str_repeat('a', $tags['items']['maxLength'] + 1)]])->assertJsonValidationErrors(['tags.0']);
});

it('accepts every destination the document publishes, and nothing else', function () {
    // The other half of the enum parity, and the one that proves the drift this change fixed is
    // really gone: `inbox` is absent from the published list, so it must be refused.
    $published = PublishedContract::property(RefileItemRequest::class, 'bucket')['enum'];

    expect($published)->not->toBeEmpty();

    foreach ($published as $destination) {
        $id = seedItem('cokolwiek', GtdBucket::Reference);

        test()->postJson("/api/items/{$id}/refile", ['bucket' => $destination])
            ->assertJsonMissingValidationErrors(['bucket']);
    }

    foreach (array_diff(array_column(GtdBucket::cases(), 'value'), $published) as $refused) {
        $id = seedItem('cokolwiek', GtdBucket::Reference);

        test()->postJson("/api/items/{$id}/refile", ['bucket' => $refused])
            ->assertJsonValidationErrors(['bucket']);
    }
});
