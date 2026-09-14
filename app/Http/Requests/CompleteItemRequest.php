<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CompleteItemRequest extends FormRequest
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
            // Required rather than defaulted: "mark done" and "un-mark" are different
            // intentions, and a missing field should not silently pick one of them.
            'completed' => ['required', 'boolean'],
        ];
    }
}
