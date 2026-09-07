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
            'bucket' => ['nullable', Rule::enum(GtdBucket::class)],
        ];
    }

    /**
     * The validated bucket, as the domain enum — keeps the mapping out of the controller.
     */
    public function bucket(): GtdBucket
    {
        $bucket = $this->validated('bucket');

        return is_string($bucket) ? GtdBucket::from($bucket) : GtdBucket::default();
    }
}
