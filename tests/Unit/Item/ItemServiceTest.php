<?php

use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Services\ItemService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Boots the app because LogEvent goes through the Log facade, but no database — the
// repository is a hand-rolled fake, as in tests/Unit/Auth/AuthServiceTest.php.
uses(TestCase::class);

/**
 * Hand-rolled fake repository recording what the service asked it for. No return type on
 * purpose: the tests read the recorder properties off the anonymous class.
 */
function fakeItemRepository()
{
    return new class implements ItemRepositoryInterface
    {
        public ?GtdBucket $createdInBucket = null;

        public ?CaptureItemPayload $createdFrom = null;

        public ?GtdBucket $listedBucket = null;

        public function create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto
        {
            $this->createdFrom = $payload;
            $this->createdInBucket = $bucket;

            return new ItemDto(
                id: 42,
                title: $payload->title,
                note: $payload->note,
                bucket: $bucket,
                createdAt: '2026-09-07T10:00:00+00:00',
                updatedAt: '2026-09-07T10:00:00+00:00',
            );
        }

        public function listByBucket(GtdBucket $bucket): Collection
        {
            $this->listedBucket = $bucket;

            return new Collection;
        }
    };
}

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
