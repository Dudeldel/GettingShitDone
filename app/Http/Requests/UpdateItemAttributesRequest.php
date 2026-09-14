<?php

namespace App\Http\Requests;

use App\Const\ItemConst;
use App\Filters\FreeTextSanitizer;
use App\Filters\TagListNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Scrub and normalise before the rules run.
     *
     * Only keys the request actually sent are merged. An unconditional merge would write
     * `context => null` for an absent key and thereby MANUFACTURE its presence — silently
     * disabling the `present` rule below for the two fields it touches, so a partial payload
     * would be accepted as a full replacement and clear whatever it omitted.
     *
     * Each branch is guarded by a type check rather than a nullable helper: a payload like
     * {"context": []} then falls through to the `string` rule and answers 422, instead of
     * raising a TypeError and answering 500.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('context')) {
            $context = $this->input('context');
            $merge['context'] = is_string($context) ? FreeTextSanitizer::sanitize($context) : $context;
        }

        if ($this->has('tags')) {
            $tags = $this->input('tags');
            $merge['tags'] = is_array($tags) ? TagListNormalizer::normalize($tags) : $tags;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Every field is `present` AND `nullable`: this verb replaces the whole attribute set, so
     * the payload always carries all five and null means "clear this one". Making them merely
     * optional would make an omitted key ambiguous — "leave it alone" or "clear it" — and the
     * two readings differ on exactly the operation the user reaches for most (removing a date).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // The date this item is tied to (FR-011). Null clears it. Past dates are allowed:
            // an overdue item is a real GTD state, not a validation error.
            'dueDate' => ['present', 'nullable', 'date_format:'.ItemConst::DATE_FORMAT],
            // Labels to filter by (FR-013). Trimmed, de-duplicated case-insensitively and
            // re-packed into a list before these rules run.
            'tags' => ['present', 'nullable', 'array', 'max:'.ItemConst::TAGS_MAX_COUNT],
            'tags.*' => ['string', 'max:'.ItemConst::TAG_MAX_LENGTH],
            // The GTD context this item belongs to, e.g. "@computer" (FR-013).
            'context' => ['present', 'nullable', 'string', 'max:'.ItemConst::CONTEXT_MAX_LENGTH],
            // Eisenhower inputs (FR-013, read by FR-014). Two independent flags rather than one
            // scale, and nullable rather than defaulting to false: "not yet judged" is a
            // different answer from "judged not important", and collapsing them would make the
            // quadrant view underivable.
            'important' => ['present', 'nullable', 'boolean'],
            'urgent' => ['present', 'nullable', 'boolean'],
        ];
    }
}
