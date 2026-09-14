<?php

use App\Domain\Item\GtdBucket;
use App\Models\Item;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Calendar/Dates view (FR-011), which is derived rather than stored.
 *
 * Calendar shows two kinds of item: anything filed there on purpose, and anything in an action
 * bucket carrying a due date. Crucially an item appearing here is NOT a member of two buckets —
 * its stored `bucket` never changes, which is what keeps FR-008 true of the column while
 * FR-011's "date-specific items appear in the Calendar/Dates bucket" is true of the view.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('shows a dated Next Action without taking it out of Next Actions', function () {
    // THE test for this slice. A date makes an item appear on the calendar; it does not move it.
    $id = seedDatedItem('wysłać raport', GtdBucket::NextActions, '2026-09-30');

    test()->getJson('/api/items?bucket=calendar')->assertJsonPath('0.id', $id);
    test()->getJson('/api/items?bucket=next_actions')->assertJsonPath('0.id', $id);

    // And the column itself is untouched — the view is derived, not a second membership.
    expect(Item::query()->find($id)->bucket)->toBe(GtdBucket::NextActions);
});

it('keeps showing an item filed to Calendar by hand even with no date', function () {
    // Shipped behaviour since S-02: Calendar is a quick-route and refile destination. The
    // derived view must ADD to that, never replace it.
    $id = seedItem('dentysta we wtorek', GtdBucket::Calendar);

    test()->getJson('/api/items?bucket=calendar')->assertJsonPath('0.id', $id);
});

it('orders dated items by date and leaves undated ones at the end', function () {
    // Both SQLite and MySQL sort NULL lowest in ASC, so a plain orderBy('due_date') would open
    // this list with the item that has no date — above every real deadline. The assertion is on
    // the exact sequence for that reason: "contains all three" could not tell the two apart.
    $undated = seedItem('kiedyś w tym tygodniu', GtdBucket::Calendar);
    $later = seedDatedItem('grudniowy termin', GtdBucket::NextActions, '2026-12-31');
    $sooner = seedDatedItem('styczniowy termin', GtdBucket::Projects, '2026-01-01');

    expect(test()->getJson('/api/items?bucket=calendar')->json('*.id'))
        ->toBe([$sooner, $later, $undated]);
});

it('leaves a dated Reference item off the calendar', function () {
    // Reference is not a commitment. A date on an article you filed to read later is metadata,
    // not something the calendar should claim you owe anyone.
    $id = seedItem('artykuł o GTD', GtdBucket::Reference);
    // Set the date directly: the endpoint allows it (Reference is editable), which is exactly
    // why the VIEW has to be the thing that excludes it.
    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '2026-09-30'))
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(0);
    test()->getJson('/api/items?bucket=reference')->assertJsonPath('0.dueDate', '2026-09-30');
});

it('leaves a dated Someday-Maybe item off the calendar', function () {
    $id = seedItem('może kiedyś sourdough', GtdBucket::SomedayMaybe);
    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '2026-09-30'))
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(0);
});

it('leaves an item in the Trash off the calendar', function () {
    // It cannot even be dated any more — the attributes verb refuses the Trash — but a date set
    // before it was binned must not keep it on the calendar either.
    $id = seedDatedItem('nieaktualny termin', GtdBucket::NextActions, '2026-09-30');
    test()->postJson("/api/items/{$id}/refile", ['bucket' => 'trash'])
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(0);
});

it('still shows a completed dated item, exactly as every other bucket does', function () {
    // No special rule: listByBucket has never filtered completed_at, and the SPA hides completed
    // items behind its own toggle. Excluding them HERE would make Calendar the one bucket where
    // that toggle silently means something different.
    $id = seedDatedItem('zrobione na czas', GtdBucket::NextActions, '2026-09-30');
    test()->postJson("/api/items/{$id}/complete", ['completed' => true])
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')
        ->assertJsonPath('0.id', $id)
        ->assertJsonPath('0.completedAt', fn ($value) => $value !== null);
});

it('drops a Next Action off the calendar when its date is cleared', function () {
    $id = seedDatedItem('przesunięty termin', GtdBucket::NextActions, '2026-09-30');
    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(1);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes())
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')->assertJsonCount(0);
    // Still exactly where it always was.
    test()->getJson('/api/items?bucket=next_actions')->assertJsonPath('0.id', $id);
});

it('keeps a Calendar-filed item on the calendar when its date is cleared', function () {
    // The mirror of the test above, and the reason the view is a union rather than a date
    // filter: this item's membership is the answer, so losing its date changes nothing.
    $id = seedItem('dentysta', GtdBucket::Calendar);
    test()->postJson("/api/items/{$id}/attributes", itemAttributes(dueDate: '2026-09-30'))
        ->assertStatus(Response::HTTP_OK);

    test()->postJson("/api/items/{$id}/attributes", itemAttributes())
        ->assertStatus(Response::HTTP_OK);

    test()->getJson('/api/items?bucket=calendar')->assertJsonPath('0.id', $id);
});

it('does not let a dated item leak into an unrelated bucket view', function () {
    // The derived rule belongs to Calendar alone. Every other bucket stays a plain
    // `where bucket = ?`, so a dated Next Action must not appear under Projects.
    seedDatedItem('wysłać raport', GtdBucket::NextActions, '2026-09-30');

    test()->getJson('/api/items?bucket=projects')->assertJsonCount(0);
    test()->getJson('/api/items?bucket=inbox')->assertJsonCount(0);
});
