<?php

use App\Domain\Clarify\ClarifyOutcome;
use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Dto\Payload\ItemAttributesPayload;
use App\Exceptions\ItemPersistenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

// Feature tests run against the application + a fresh in-memory DB per test.
// Unit tests stay light (no app, no DB) per tests/CLAUDE.md and opt in explicitly.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Build a Monolog LogRecord for unit-testing log processors/formatters.
 *
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function makeLogRecord(array $context = [], array $extra = [], string $message = 'test'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

/**
 * Persist an item straight through the repository. Shared here rather than declared in a
 * test file: Pest loads every test into one process, so a duplicate file-scope function
 * name in a later slice is a fatal redeclaration, not a test failure.
 */
function seedItem(string $title, GtdBucket $bucket): int
{
    return app(ItemRepositoryInterface::class)
        ->create(new CaptureItemPayload($title, null), $bucket)
        ->id;
}

/**
 * A full attribute payload with everything null, overridden by whatever the caller names.
 *
 * Lives here for the same reason seedItem() does: Pest loads every test into one process, so
 * a file-scope helper in a test file becomes a fatal redeclaration the day a later slice picks
 * the same name.
 *
 * Spelling out all five keys is the point — the endpoint replaces the whole attribute set, so
 * a test that sent a partial body would be exercising a 422 rather than the behaviour it named.
 *
 * @return array<string, mixed>
 */
function itemAttributes(mixed ...$named): array
{
    return array_merge([
        'dueDate' => null,
        'tags' => null,
        'context' => null,
        'important' => null,
        'urgent' => null,
    ], $named);
}

/**
 * Seed an item and, when a date is given, set it through the real attributes endpoint.
 *
 * Goes through HTTP rather than writing the column directly on purpose: the Calendar view's
 * whole claim is that a date set the ordinary way surfaces the item, so a test that bypassed
 * the write path would be proving something weaker than it reads.
 *
 * Lives here for the same reason seedItem() does — Pest loads every test into one process, so
 * a file-scope helper becomes a fatal redeclaration the day a later slice reuses the name.
 */
function seedDatedItem(string $title, GtdBucket $bucket, ?string $dueDate): int
{
    $id = seedItem($title, $bucket);

    if ($dueDate !== null) {
        test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: $dueDate))
            ->assertStatus(SymfonyResponse::HTTP_OK);
    }

    return $id;
}

/**
 * Hand-rolled fake repository recording what the service asked it for. No return type on
 * purpose: the tests read the recorder properties off the anonymous class.
 */
function fakeItemRepository(bool $failing = false)
{
    return new class($failing) implements ItemRepositoryInterface
    {
        public function __construct(private bool $failing = false) {}

        public ?GtdBucket $createdInBucket = null;

        public ?CaptureItemPayload $createdFrom = null;

        public ?GtdBucket $listedBucket = null;

        public ?int $clarifiedItemId = null;

        public ?ClarifyOutcome $clarifiedTo = null;

        public bool $trashEmptied = false;

        public ?int $refiledItemId = null;

        public ?GtdBucket $refiledTo = null;

        public ?int $completedItemId = null;

        public ?bool $completedTo = null;

        public ?int $attributedItemId = null;

        public ?ItemAttributesPayload $attributesWritten = null;

        public bool $calendarListed = false;

        public function create(CaptureItemPayload $payload, GtdBucket $bucket): ItemDto
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

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

        public function clarify(int $itemId, ClarifyOutcome $outcome): ItemDto
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

            $this->clarifiedItemId = $itemId;
            $this->clarifiedTo = $outcome;

            return new ItemDto(
                id: $itemId,
                title: 'an idea',
                note: null,
                bucket: $outcome->bucket,
                createdAt: '2026-09-07T10:00:00+00:00',
                updatedAt: '2026-09-07T10:00:00+00:00',
                delegatedTo: $outcome->delegatedTo,
                completedAt: $outcome->completed ? '2026-09-14T12:00:00+00:00' : null,
            );
        }

        public function refile(int $itemId, GtdBucket $destination): ItemDto
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

            $this->refiledItemId = $itemId;
            $this->refiledTo = $destination;

            return new ItemDto(
                id: $itemId,
                title: 'an idea',
                note: null,
                bucket: $destination,
                createdAt: '2026-09-07T10:00:00+00:00',
                updatedAt: '2026-09-14T12:00:00+00:00',
                // A re-file must carry an existing completion across; the fake keeps one set
                // so a service that dropped it would be visible here.
                completedAt: '2026-09-14T11:00:00+00:00',
            );
        }

        public function setCompleted(int $itemId, bool $completed): ItemDto
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

            $this->completedItemId = $itemId;
            $this->completedTo = $completed;

            return new ItemDto(
                id: $itemId,
                title: 'an idea',
                note: null,
                // Unchanged by design: completing must never move an item.
                bucket: GtdBucket::NextActions,
                createdAt: '2026-09-07T10:00:00+00:00',
                updatedAt: '2026-09-14T12:00:00+00:00',
                completedAt: $completed ? '2026-09-14T12:00:00+00:00' : null,
            );
        }

        public function updateAttributes(int $itemId, ItemAttributesPayload $payload): ItemDto
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

            $this->attributedItemId = $itemId;
            $this->attributesWritten = $payload;

            return new ItemDto(
                id: $itemId,
                title: 'an idea',
                note: null,
                // Unchanged by design: setting attributes must never move an item. A service
                // that somehow rebucketed would be visible here.
                bucket: GtdBucket::NextActions,
                createdAt: '2026-09-07T10:00:00+00:00',
                updatedAt: '2026-09-14T12:00:00+00:00',
                dueDate: $payload->dueDate,
                tags: $payload->tags,
                context: $payload->context,
                important: $payload->important,
                urgent: $payload->urgent,
            );
        }

        public function emptyTrash(): int
        {
            if ($this->failing) {
                throw new ItemPersistenceException('HY000');
            }

            $this->trashEmptied = true;

            return 3;
        }

        public function listByBucket(GtdBucket $bucket): Collection
        {
            $this->listedBucket = $bucket;

            return new Collection;
        }

        public function listCalendar(): Collection
        {
            $this->calendarListed = true;

            return new Collection;
        }
    };
}
