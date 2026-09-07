<?php

namespace App\Filters;

/**
 * Strips control characters from free text before it is stored.
 *
 * Technical, not domain: this *fixes* the input in prepareForValidation(), whereas the
 * rules in a FormRequest *reject* it. Both run — sanitize and validate side by side.
 */
final class FreeTextSanitizer
{
    /**
     * Remove control characters, keeping LF (\n) and TAB (\t) so multi-line notes survive.
     */
    public static function sanitize(string $value): string
    {
        // No /u modifier: the class is pure ASCII, and with /u a subject that is not
        // valid UTF-8 makes preg_replace return null — which a (string) cast would
        // silently turn into '', destroying the whole entry. On any PCRE failure keep
        // the original: the guardrail is that capture never loses an entry.
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return $sanitized ?? $value;
    }

    public static function sanitizeNullable(?string $value): ?string
    {
        return $value === null ? null : self::sanitize($value);
    }
}
