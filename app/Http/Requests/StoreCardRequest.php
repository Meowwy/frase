<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:120', 'min:2'],
            'definition' => ['required', 'string'],
            'translation' => ['nullable', 'string'],
            'example_sentence' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'theme_id' => ['nullable'],
        ];
    }
}
