<?php

use App\Domain\Item\GtdBucket;
use App\Domain\Item\ItemRepositoryInterface;
use App\Dto\ItemDto;
use App\Dto\Payload\CaptureItemPayload;
use App\Exceptions\ItemPersistenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Monolog\Level;
use Monolog\LogRecord;
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
function seedItem(string $title, GtdBucket $bucket): void
{
    app(ItemRepositoryInterface::class)->create(new CaptureItemPayload($title, null), $bucket);
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

        public function listByBucket(GtdBucket $bucket): Collection
        {
            $this->listedBucket = $bucket;

            return new Collection;
        }
    };
}
