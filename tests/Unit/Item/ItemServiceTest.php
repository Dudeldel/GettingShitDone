<?php

use App\Domain\Item\GtdBucket;
use App\Dto\Payload\CaptureItemPayload;
use App\Exceptions\ItemPersistenceException;
use App\Services\ItemService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Boots the app because LogEvent goes through the Log facade, but no database — the
// repository is a hand-rolled fake, as in tests/Unit/Auth/AuthServiceTest.php.
uses(TestCase::class);

it('captures into the Inbox regardless of what the caller passes', function () {
    $repo = fakeItemRepository();

    $dto = (new ItemService($repo))->capture(new CaptureItemPayload('buy milk', null));

    expect($repo->createdInBucket)->toBe(GtdBucket::Inbox)
        ->and($repo->createdFrom?->title)->toBe('buy milk')
        ->and($dto->id)->toBe(42)
        ->and($dto->bucket)->toBe(GtdBucket::Inbox);
});

it('emits the capture domain event with the new item id', function () {
    Log::spy();

    (new ItemService(fakeItemRepository()))->capture(new CaptureItemPayload('idea', null));

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $message === 'item.captured.success'
            && $context['item_id'] === 42
            && $context['bucket'] === 'inbox';
    });
});

it('delegates listing to the repository with the requested bucket', function () {
    $repo = fakeItemRepository();

    (new ItemService($repo))->listByBucket(GtdBucket::Reference);

    expect($repo->listedBucket)->toBe(GtdBucket::Reference);
});

it('emits a failure event and rethrows when the write fails', function () {
    Log::spy();

    expect(fn () => (new ItemService(fakeItemRepository(failing: true)))
        ->capture(new CaptureItemPayload('my bank pin is 4711', null)))
        ->toThrow(ItemPersistenceException::class);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'error'
            && $message === 'item.captured.failure'
            && $context['event']['outcome'] === 'failure'
            && $context['reason'] === 'HY000'
            // the captured text must never reach a log line
            && ! str_contains(json_encode($context), '4711');
    });
});
