<?php

namespace App\Http\Requests;

use App\Domain\Item\GtdBucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class RefileItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            /**
             * FR-010: the destination the user picked. The allowed set is derived from
             * GtdBucket::isDestination() rather than listed here, so the edge cannot drift
             * from the domain — the enum is the single place that knows the Inbox is not one.
             *
             * Rule::enum(GtdBucket::class) is deliberately absent. It was redundant — a
             * seven-value Rule::in already rejects everything outside its list, the Inbox
             * included — but it made Scramble publish a $ref to the full eight-value GtdBucket
             * schema, so the document offered `inbox` as a destination while the endpoint
             * answered 422 for it. Without it the narrowed set is what gets published, and
             * tests/Feature/Api/ContractParityTest.php now fails if the two ever diverge again.
             */
            'bucket' => [
                'required',
                Rule::in(array_map(
                    static fn (GtdBucket $bucket): string => $bucket->value,
                    array_filter(GtdBucket::cases(), static fn (GtdBucket $b): bool => $b->isDestination()),
                )),
            ],
        ];
    }
}
