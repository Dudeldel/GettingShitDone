<?php

namespace App\Http\Requests;

use App\Const\ItemConst;
use App\Filters\FreeTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class CaptureItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Scrub control characters before the rules run. Sanitizing afterwards would let a
     * max-length payload of control characters through as an empty title.
     */
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $note = $this->input('note');

        $this->merge([
            'title' => is_string($title) ? FreeTextSanitizer::sanitize($title) : $title,
            'note' => is_string($note) ? FreeTextSanitizer::sanitize($note) : $note,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // The captured idea, as free text.
            'title' => ['required', 'string', 'max:'.ItemConst::TITLE_MAX_LENGTH],
            // Optional longer thought attached to the idea.
            'note' => ['nullable', 'string', 'max:'.ItemConst::NOTE_MAX_LENGTH],
        ];
    }
}
