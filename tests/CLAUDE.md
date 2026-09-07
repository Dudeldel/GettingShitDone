# tests/ — test conventions

Rules for tests. See root @../CLAUDE.md for the project overview and @../app/CLAUDE.md for backend code conventions (the patterns these tests exercise).

## Pest — the target framework (not yet installed)

The scaffold currently ships PHPUnit `ExampleTest`s (`tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`). **Pest** is the target — install it and migrate. The `pestphp/pest-plugin` is already pre-allowed in `composer.json`, so installation is frictionless.

Split tests:

- **Unit** (`tests/Unit/`) — isolated classes: DTOs, services, strategies, Value Objects. No HTTP, no DB where avoidable.
- **Feature** (`tests/Feature/`) — anything needing the booted app **and** a database,
  against SQLite in-memory: endpoints end-to-end, and repository/integration tests that
  exercise real persistence. `tests/Pest.php` applies `RefreshDatabase` to this suite only,
  which is what makes it the home for both.

## Conventions

- `it('...')` naming, describing observable behaviour.
- **`Response::HTTP_*` constants in assertions, never integers** (same rule as production code — see @../app/CLAUDE.md).
- Imports via `use`, never inline FQN — including the test traits:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
```

```php
// Feature — endpoint
it('routes a non-actionable item to reference', function () {
    $response = $this->postJson('/v1/items/ITEM-1/clarify', ['actionable' => false, 'bucket' => 'reference']);
    $response->assertStatus(Response::HTTP_OK);
});

// Unit — domain logic
it('files a sub-2-minute actionable item without a project', function () {
    $entity = InboxItemEntity::fromDto($dto);
    expect($entity->clarify($answers)->bucket())->toBe(GtdBucket::NextActions);
});
```

- Every new feature ships feature **and** unit tests — it's a CI gate (root @../CLAUDE.md "CI gates").
- Run: `php artisan test` (or `./vendor/bin/pest` once installed); single test `php artisan test --filter=Name`.
