<?php

namespace App\Http\Requests;

use App\Domain\Item\GtdBucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RefileItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Rule|Enum>>
     */
    public function rules(): array
    {
        return [
            /**
             * FR-010: the destination the user picked. The allowed set is derived from
             * GtdBucket::isDestination() rather than listed here, so the edge cannot drift
             * from the domain — the enum is the single place that knows the Inbox is not one.
             */
            'bucket' => [
                'required',
                Rule::enum(GtdBucket::class),
                Rule::in(array_map(
                    static fn (GtdBucket $bucket): string => $bucket->value,
                    array_filter(GtdBucket::cases(), static fn (GtdBucket $b): bool => $b->isDestination()),
                )),
            ],
        ];
    }
}
