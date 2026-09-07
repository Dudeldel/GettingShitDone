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
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    public static function sanitizeNullable(?string $value): ?string
    {
        return $value === null ? null : self::sanitize($value);
    }
}
