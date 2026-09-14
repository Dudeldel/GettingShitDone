<?php

use App\Filters\TagListNormalizer;

it('trims each tag and drops the ones that were only whitespace', function () {
    expect(TagListNormalizer::normalize(['  work ', '   ', "\tdeep\t", '']))
        ->toBe(['work', 'deep']);
});

it('de-duplicates case-insensitively and keeps the first spelling seen', function () {
    // "Work" and "work" are one tag to anyone filtering by it, and two entries that render
    // identically would be a list the user cannot tell apart. First spelling wins so the
    // result is stable rather than dependent on which duplicate arrived last.
    expect(TagListNormalizer::normalize(['Work', 'work', 'WORK', 'home']))
        ->toBe(['Work', 'home']);
});

it('re-packs into a list, so a JSON object cannot reach the column as a map', function () {
    // THE test for S-01's F6. The `tags` json cast returns whatever json_decode produced, so
    // {"a":"x"} arrives as a string-keyed array — which is not the list<string> ItemDto
    // declares. Without the re-pack the DTO's declared type is a wish, not a fact.
    $normalized = TagListNormalizer::normalize(['a' => 'x', 'b' => 'y']);

    expect($normalized)->toBe(['x', 'y'])
        ->and(array_keys($normalized))->toBe([0, 1]);
});

it('strips control characters the way stored free text is stripped elsewhere', function () {
    expect(TagListNormalizer::normalize(["wo\x00rk"]))->toBe(['work']);
});

it('hands a non-string element back untouched so validation can reject it', function () {
    // Deliberately NOT dropped. Silently discarding it would turn a malformed payload into a
    // successful write that lost data; passing it through means the tags.* => string rule
    // answers 422 and the user finds out.
    $tags = ['work', 123];

    expect(TagListNormalizer::normalize($tags))->toBe($tags);
});

it('drops a null element, because that is what the framework makes of a blank tag', function () {
    // Laravel's TrimStrings + ConvertEmptyStringsToNull run BEFORE prepareForValidation, so a
    // whitespace-only tag has already become null by the time this sees it. Treating null like
    // any other non-string would answer 422 for the ordinary act of leaving a trailing comma —
    // the user typed a blank, not a number.
    expect(TagListNormalizer::normalize(['work', null, 'home']))->toBe(['work', 'home']);
});

it('still rejects a non-string that arrives alongside a null', function () {
    // The null exemption must not become a general "skip anything odd" — a real type error in
    // the same payload has to survive to the validator.
    $tags = ['work', null, 123];

    expect(TagListNormalizer::normalize($tags))->toBe($tags);
});

it('returns an empty list for an empty input', function () {
    expect(TagListNormalizer::normalize([]))->toBe([]);
});
