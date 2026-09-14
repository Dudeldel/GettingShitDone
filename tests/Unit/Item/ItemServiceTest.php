<?php

use App\Domain\Clarify\ClarifyDecision;
use App\Domain\Clarify\TwoMinuteOutcome;
use App\Domain\Item\GtdBucket;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ClarifyItemPayload;
use App\Dto\Payload\CompleteItemPayload;
use App\Dto\Payload\RefileItemPayload;
use App\Exceptions\ItemPersistenceException;
use App\Services\ItemService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Boots the app because LogEvent goes through the Log facade, but no database — the
// repository is a hand-rolled fake, as in tests/Unit/Auth/AuthServiceTest.php.
uses(TestCase::class);

it('captures into the Inbox regardless of what the caller passes', function () {
    $repo = fakeItemRepository();

    $dto = (new ItemService($repo, new ClarifyDecision))->capture(new CaptureItemPayload('buy milk', null));

    expect($repo->createdInBucket)->toBe(GtdBucket::Inbox)
        ->and($repo->createdFrom?->title)->toBe('buy milk')
        ->and($dto->id)->toBe(42)
        ->and($dto->bucket)->toBe(GtdBucket::Inbox);
});

it('emits the capture domain event with the new item id', function () {
    Log::spy();

    (new ItemService(fakeItemRepository(), new ClarifyDecision))->capture(new CaptureItemPayload('idea', null));

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $message === 'item.captured.success'
            && $context['item_id'] === 42
            && $context['bucket'] === 'inbox';
    });
});

it('delegates listing to the repository with the requested bucket', function () {
    $repo = fakeItemRepository();

    (new ItemService($repo, new ClarifyDecision))->listByBucket(GtdBucket::Reference);

    expect($repo->listedBucket)->toBe(GtdBucket::Reference);
});

it('emits a failure event and rethrows when the write fails', function () {
    Log::spy();

    expect(fn () => (new ItemService(fakeItemRepository(failing: true), new ClarifyDecision))
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

it('derives the destination from the answers and hands it to the repository', function () {
    $repo = fakeItemRepository();

    $dto = (new ItemService($repo, new ClarifyDecision))->clarify(
        7,
        ClarifyItemPayload::treePath(actionable: true, singleStep: true, twoMinutes: false, delegable: false),
    );

    // The service must not accept a destination — it asks the domain for one.
    expect($repo->clarifiedItemId)->toBe(7)
        ->and($repo->clarifiedTo->bucket)->toBe(GtdBucket::NextActions)
        ->and($dto->bucket)->toBe(GtdBucket::NextActions);
});

it('emits a failure event and rethrows when the clarify write fails', function () {
    Log::spy();

    expect(fn () => (new ItemService(fakeItemRepository(failing: true), new ClarifyDecision))
        ->clarify(7, ClarifyItemPayload::quickRoute(GtdBucket::Trash)))
        ->toThrow(ItemPersistenceException::class);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'error'
            && $message === 'item.clarified.failure'
            && $context['bucket'] === 'trash'
            && $context['reason'] === 'HY000';
    });
});

it('reports how many items the purge discarded', function () {
    $repo = fakeItemRepository();

    $count = (new ItemService($repo, new ClarifyDecision))->emptyTrash();

    expect($repo->trashEmptied)->toBeTrue()
        ->and($count)->toBe(3);
});

it('emits a failure event and rethrows when the purge fails', function () {
    Log::spy();

    expect(fn () => (new ItemService(fakeItemRepository(failing: true), new ClarifyDecision))
        ->emptyTrash())
        ->toThrow(ItemPersistenceException::class);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'error'
            && $message === 'trash.emptied.failure'
            && $context['reason'] === 'HY000';
    });
});

/**
 * FR-006's record, at the seam where it is decided.
 *
 * These are the tests the implementation review found missing — and the ones that would
 * have caught the defect it found instead: the service used to ask the PAYLOAD whether a
 * timer had run, so timer answers attached to a path that never asks the question produced
 * a "completed timer" log line for an item filed somewhere else entirely.
 */
it('records the timer outcome the DOMAIN decided, not the one the request claimed', function () {
    Log::spy();

    (new ItemService(fakeItemRepository(), new ClarifyDecision))->clarify(7, ClarifyItemPayload::treePath(
        actionable: true,
        singleStep: true,
        twoMinutes: true,
        twoMinuteOutcome: TwoMinuteOutcome::Deferred,
        twoMinuteLoops: 3,
        delegable: false,
    ));

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $message === 'clarify.two_minute_rule.deferred'
            && $context['item_id'] === 7
            && $context['two_minute_loops'] === 3;
    });
});

it('records nothing about a timer on a branch that never asks the question', function () {
    // The forged payload: a multi-step item claiming a completed two-minute timer. The tree
    // files it in Projects and leaves completed_at null, so a log line saying the timer
    // finished would contradict the item's own state — and that contradiction was reachable.
    Log::spy();

    $dto = (new ItemService(fakeItemRepository(), new ClarifyDecision))->clarify(7, ClarifyItemPayload::treePath(
        actionable: true,
        singleStep: false,
        twoMinutes: true,
        twoMinuteOutcome: TwoMinuteOutcome::Done,
        twoMinuteLoops: 7,
    ));

    expect($dto->bucket)->toBe(GtdBucket::Projects)
        ->and($dto->completedAt)->toBeNull();

    Log::shouldNotHaveReceived('log', ['info', 'clarify.two_minute_rule.done', Mockery::any()]);
    Log::shouldNotHaveReceived('log', ['info', 'clarify.two_minute_rule.deferred', Mockery::any()]);
});

it('does not report a timer for an item filed by the quick-route', function () {
    Log::spy();

    (new ItemService(fakeItemRepository(), new ClarifyDecision))
        ->clarify(7, ClarifyItemPayload::quickRoute(GtdBucket::Reference));

    Log::shouldNotHaveReceived('log', ['info', 'clarify.two_minute_rule.done', Mockery::any()]);
    Log::shouldNotHaveReceived('log', ['info', 'clarify.two_minute_rule.deferred', Mockery::any()]);
});

it('hands the destination to the repository and records the move', function () {
    Log::spy();
    $repo = fakeItemRepository();

    $dto = (new ItemService($repo, new ClarifyDecision))
        ->refile(7, RefileItemPayload::fromArray(['bucket' => 'calendar']));

    expect($repo->refiledItemId)->toBe(7)
        ->and($repo->refiledTo)->toBe(GtdBucket::Calendar)
        // The fake returns a completed item, so a service that dropped the completion on the
        // way out would show up here rather than only in an integration test.
        ->and($dto->completedAt)->not->toBeNull();

    Log::shouldHaveReceived('log')->withArgs(
        fn ($level, $message, $context) => $message === 'item.refiled.success'
            && $context['bucket'] === 'calendar',
    );
});

it('records marking and clearing as different events', function () {
    Log::spy();
    $service = new ItemService(fakeItemRepository(), new ClarifyDecision);

    $service->setCompleted(7, CompleteItemPayload::fromArray(['completed' => true]));
    $service->setCompleted(7, CompleteItemPayload::fromArray(['completed' => false]));

    Log::shouldHaveReceived('log')->withArgs(
        fn ($level, $message, $context) => $message === 'item.completion.marked' && $context['completed'] === true,
    );
    Log::shouldHaveReceived('log')->withArgs(
        fn ($level, $message, $context) => $message === 'item.completion.cleared' && $context['completed'] === false,
    );
});

it('emits a failure event and rethrows when a re-file write fails', function () {
    Log::spy();

    expect(fn () => (new ItemService(fakeItemRepository(failing: true), new ClarifyDecision))
        ->refile(7, RefileItemPayload::fromArray(['bucket' => 'trash'])))
        ->toThrow(ItemPersistenceException::class);

    Log::shouldHaveReceived('log')->withArgs(
        fn ($level, $message, $context) => $level === 'error'
            && $message === 'item.refiled.failure'
            && $context['reason'] === 'HY000',
    );
});
