<?php

namespace App\Http\Requests;

use App\Domain\Item\GtdBucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Absent or empty means the Inbox, so the rules can treat the field as always present.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('bucket') === null) {
            $this->merge(['bucket' => GtdBucket::Inbox->value]);
        }
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            /**
             * Which GTD bucket to list. Defaults to the Inbox.
             *
             * @query
             */
            'bucket' => ['required', Rule::enum(GtdBucket::class)],
        ];
    }

    /**
     * The validated bucket, as the domain enum — keeps the mapping out of the controller.
     */
    public function bucket(): GtdBucket
    {
        return GtdBucket::from((string) $this->validated('bucket'));
    }
}
