<?php

namespace App\Filters;

/**
 * Brings a client-supplied tag array into the one shape the column is allowed to hold.
 *
 * Technical, not domain — it *fixes* the input in prepareForValidation(), the way
 * FreeTextSanitizer does, while the rules in the FormRequest *reject* it. Both run.
 *
 * This exists because the `tags` json cast makes no promise about shape: json_decode returns
 * whatever it was given, so a JSON object yields a string-keyed array and a scalar yields a
 * scalar — neither matching the list<string>|null that ItemDto declares. The S-01 review
 * recorded that gap (impl-review-phase-1 F6) and named this slice as the one that would hit
 * it. Normalising on the way in is what makes the declared type true rather than hoped-for.
 */
final class TagListNormalizer
{
    /**
     * Trim, drop empties, de-duplicate case-insensitively, and re-pack into a list.
     *
     * De-duplication keeps the FIRST spelling seen: typing "Work" when "work" is already
     * there keeps "work". Case-insensitive because "Work" and "work" are one tag to the
     * person filtering by it, and two tags that render identically would be a list the user
     * cannot tell apart.
     *
     * A null element is dropped, because null is not something a user types — it is what
     * Laravel's own TrimStrings + ConvertEmptyStringsToNull middleware have already made of a
     * whitespace-only tag by the time prepareForValidation runs. Treating it as hostile input
     * would answer 422 for the entirely ordinary act of leaving a trailing comma.
     *
     * Any OTHER non-string element is NOT dropped — the whole array is handed back untouched
     * so the `tags.*` => string rule rejects it with a 422. Silently discarding a number or a
     * nested array would turn a malformed payload into a successful write that lost data.
     *
     * @param  array<array-key, mixed>  $tags
     * @return array<array-key, mixed> a list<string> when every element was a string or null
     */
    public static function normalize(array $tags): array
    {
        foreach ($tags as $tag) {
            if (! is_string($tag) && $tag !== null) {
                return $tags;
            }
        }

        $seen = [];

        foreach ($tags as $tag) {
            $trimmed = $tag === null ? '' : trim(FreeTextSanitizer::sanitize($tag));

            if ($trimmed === '') {
                continue;
            }

            // The lowercased form is the identity; the value keeps the original spelling.
            $seen[mb_strtolower($trimmed)] ??= $trimmed;
        }

        return array_values($seen);
    }
}
