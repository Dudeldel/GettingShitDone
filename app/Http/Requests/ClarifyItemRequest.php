<?php

namespace App\Http\Requests;

use App\Const\ItemConst;
use App\Domain\Item\GtdBucket;
use App\Filters\FreeTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ClarifyItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Scrub control characters from the delegation note before the rules run — same
     * is_string() guard as CaptureItemRequest, so a payload like {"delegatedTo": []}
     * yields 422 rather than a TypeError.
     */
    protected function prepareForValidation(): void
    {
        $delegatedTo = $this->input('delegatedTo');

        $this->merge([
            'delegatedTo' => is_string($delegatedTo) ? FreeTextSanitizer::sanitize($delegatedTo) : $delegatedTo,
        ]);
    }

    /**
     * The tree answers and the quick-route are mutually exclusive, enforced with built-ins
     * rather than a custom rule (see app/CLAUDE.md "Cross-field invariants").
     *
     * required_if_accepted / required_if_declined are used instead of required_if:field,true
     * because the answers arrive as JSON booleans, and these rules are the ones that treat a
     * real `true` / `false` as such.
     *
     * @return array<string, list<string|Rule|Enum>>
     */
    public function rules(): array
    {
        return [
            /**
             * FR-002 quick-route: name the destination outright and skip the tree.
             * Mutually exclusive with every tree answer.
             */
            'quickRouteBucket' => [
                'nullable',
                Rule::enum(GtdBucket::class),
                'prohibits:actionable,nonActionableDestination,singleStep,delegable',
            ],
            // The first question of the tree (FR-003). Required unless quick-routing.
            'actionable' => ['required_without:quickRouteBucket', 'boolean'],
            /**
             * FR-004: a non-actionable item goes to one of exactly three destinations.
             * Next Actions or Delegation here would make the answer meaningless.
             */
            'nonActionableDestination' => [
                'required_if_declined:actionable',
                Rule::in([
                    GtdBucket::Trash->value,
                    GtdBucket::SomedayMaybe->value,
                    GtdBucket::Reference->value,
                ]),
            ],
            // Asked only once the item is actionable.
            'singleStep' => ['required_if_accepted:actionable', 'boolean'],
            // Asked only once the item is a single step.
            'delegable' => ['required_if_accepted:singleStep', 'boolean'],
            /**
             * FR-007: Delegation IS the who/what note — without it the bucket records that
             * something is delegated but not whom to chase.
             */
            'delegatedTo' => [
                'required_if_accepted:delegable',
                'nullable',
                'string',
                'max:'.ItemConst::DELEGATED_TO_MAX_LENGTH,
            ],
        ];
    }
}
