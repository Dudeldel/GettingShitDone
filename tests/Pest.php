<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests run against the application + a fresh in-memory DB per test.
// Unit tests stay light (no app, no DB) per tests/CLAUDE.md and opt in explicitly.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');
