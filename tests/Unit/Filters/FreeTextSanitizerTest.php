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

it('passes null through', function () {
    expect(FreeTextSanitizer::sanitizeNullable(null))->toBeNull();
});

it('sanitizes a non-null nullable value', function () {
    expect(FreeTextSanitizer::sanitizeNullable("note\x01"))->toBe('note');
});
