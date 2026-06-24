<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
