<?php

use App\Domain\Item\GtdBucket;

it('declares exactly the eight canonical GTD buckets', function () {
    // Locked deliberately: S-02 routes into these values and the frontend reads them,
    // so a rename or an addition is a breaking contract change, not a refactor.
    expect(array_map(fn (GtdBucket $b): string => $b->value, GtdBucket::cases()))
        ->toBe([
            'inbox',
            'next_actions',
            'projects',
            'calendar',
            'delegation',
            'someday_maybe',
            'reference',
            'trash',
        ]);
});

it('resolves a bucket from its stored string', function () {
    expect(GtdBucket::from('inbox'))->toBe(GtdBucket::Inbox);
});
