<?php

namespace App\Http\Requests;

use App\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // No AI answer to read the shape off on this path, so the form asks for it.
            'card_shape' => ['required', Rule::in(Card::SHAPES)],
            'definition' => ['required', 'string'],
            'translation' => ['nullable', 'string'],
            'example_sentence' => ['nullable', 'string'],
            // Word-shape only; the controller drops them for the other two shapes.
            'anchor' => ['nullable', 'string'],
            'anchor_translation' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'theme_id' => ['nullable'],
        ];
    }
}
