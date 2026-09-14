<?php

namespace App\Http\Requests;

use App\Const\ClarifyConst;
use App\Const\ItemConst;
use App\Domain\Clarify\TwoMinuteOutcome;
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
                'prohibits:actionable,nonActionableDestination,singleStep,twoMinutes,twoMinuteOutcome,twoMinuteLoops,delegable',
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
            /**
             * FR-006: the two-minute rule. Asked once the item is a single step, and BEFORE
             * "can it be delegated?" — the canonical GTD order (FR-003).
             */
            'twoMinutes' => [
                'required_if_accepted:singleStep',
                // ...and refused on the paths that never ask it. Without these, timer
                // answers can ride a non-actionable or multi-step payload: the decision tree
                // ignores them there, so they used to be silently accepted rather than
                // rejected, which is how a Projects item came to log a completed timer.
                'prohibited_if_declined:actionable',
                'prohibited_if_declined:singleStep',
                'boolean',
            ],
            /**
             * How the timer ended. Required once a timer ran, and meaningless without one —
             * an outcome for a timer that never started would be a recorded fact about
             * nothing.
             */
            'twoMinuteOutcome' => [
                'required_if_accepted:twoMinutes',
                'prohibited_if_declined:twoMinutes',
                'prohibited_if_declined:actionable',
                'prohibited_if_declined:singleStep',
                Rule::enum(TwoMinuteOutcome::class),
            ],
            /**
             * How many times the user asked for more time. Observability only (it is logged,
             * never stored), but it still arrives from a client, so it is bounded like any
             * other input rather than trusted straight into a log line.
             */
            'twoMinuteLoops' => [
                'prohibited_if_declined:twoMinutes',
                'prohibited_if_declined:actionable',
                'prohibited_if_declined:singleStep',
                'nullable',
                'integer',
                'min:0',
                'max:'.ClarifyConst::MAX_TWO_MINUTE_LOOPS,
            ],
            /**
             * Asked only when the item is still unfiled after the two-minute question:
             * either it does not fit in two minutes, or the timer ran and the user deferred.
             * Prohibited when the timer ended in "done" — you cannot delegate a thing you
             * have already finished, and accepting the field would make two contradictory
             * answers valid in one payload.
             */
            'delegable' => [
                'required_if_declined:twoMinutes',
                'required_if:twoMinuteOutcome,'.TwoMinuteOutcome::Deferred->value,
                'prohibited_if:twoMinuteOutcome,'.TwoMinuteOutcome::Done->value,
                'boolean',
            ],
            /**
             * FR-007: Delegation IS the who/what note — without it the bucket records that
             * something is delegated but not whom to chase.
             */
            'delegatedTo' => [
                'required_if_accepted:delegable',
                // Delegation is a legal quick-route target, and the note is what makes the
                // bucket meaningful — so the edge must demand it there too. Without this the
                // domain rejects the payload instead, which is the edge/domain disagreement
                // InvalidClarificationException says should never happen.
                'required_if:quickRouteBucket,'.GtdBucket::Delegation->value,
                // The other half of the contradiction `delegable` already refuses: naming
                // who you are waiting on for something you have just finished. Prohibiting
                // only the boolean left the invariant half-enforced.
                'prohibited_if:twoMinuteOutcome,'.TwoMinuteOutcome::Done->value,
                'nullable',
                'string',
                'max:'.ItemConst::DELEGATED_TO_MAX_LENGTH,
            ],
        ];
    }
}
