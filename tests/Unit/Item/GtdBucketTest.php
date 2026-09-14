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

/**
 * The two predicates this slice added. Both are read by the HTTP edge AND asserted in the
 * domain, so the expectations below come from the PRD rather than from the enum: FR-004 names
 * Trash / Someday-Maybe / Reference as the destinations for items judged NOT actionable, and
 * the Inbox is where unprocessed capture lands rather than somewhere anything is filed.
 */
describe('which buckets are legal destinations', function () {
    it('accepts every bucket except the Inbox', function () {
        foreach (GtdBucket::cases() as $bucket) {
            expect($bucket->isDestination())->toBe($bucket !== GtdBucket::Inbox);
        }
    });

    it('counts exactly seven of them', function () {
        // A count, so adding a ninth bucket without deciding about it fails here rather than
        // silently becoming filable.
        $destinations = array_filter(GtdBucket::cases(), fn (GtdBucket $b) => $b->isDestination());

        expect($destinations)->toHaveCount(7);
    });
});

describe('which buckets can hold something done', function () {
    it('accepts the four commitments and refuses the rest', function () {
        $expected = [
            'next_actions' => true,
            'projects' => true,
            'calendar' => true,
            'delegation' => true,
            // Not commitments — FR-004 offers these three for items judged not actionable.
            'someday_maybe' => false,
            'reference' => false,
            'trash' => false,
            // Not decided about yet.
            'inbox' => false,
        ];

        foreach (GtdBucket::cases() as $bucket) {
            expect($bucket->isActionBucket())->toBe($expected[$bucket->value]);
        }
    });
});
