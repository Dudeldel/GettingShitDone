<?php

use App\Filters\FreeTextSanitizer;

it('strips control characters from free text', function () {
    expect(FreeTextSanitizer::sanitize("call\x00 Bob\x07"))->toBe('call Bob');
});

it('keeps newlines and tabs so multi-line notes survive', function () {
    expect(FreeTextSanitizer::sanitize("line one\nline\ttwo"))->toBe("line one\nline\ttwo");
});

it('leaves clean text untouched', function () {
    expect(FreeTextSanitizer::sanitize('buy milk'))->toBe('buy milk');
});

it('keeps the entry intact when the input is not valid UTF-8', function () {
    // With a /u-modified pattern preg_replace returns null here and a (string) cast would
    // silently yield '' — losing the whole capture, which the PRD guardrail forbids.
    $malformed = "cafe\xE9 idea";

    expect(FreeTextSanitizer::sanitize($malformed."\x00"))->toBe($malformed);
});
