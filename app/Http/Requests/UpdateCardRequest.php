<?php

namespace App\Http\Requests;

use App\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is checked in the controller via CardPolicy (needs the route-bound
     * Card, which isn't reliably available here before route-model binding runs).
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
            'term' => ['required', 'string'],
            // The derived predicate over `card_shape` stays user-correctable; the
            // controller resolves it back to a shape — see CardController::update().
            'term_type' => ['required', Rule::in(Card::TERM_TYPES)],
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            // Nullable so the field can be cleared; the column is NOT NULL, hence the
            // coalesce in the controller.
            'example_sentence' => ['nullable', 'string'],
            // Word-shape only, and rendered only then; the controller drops them if the
            // term-type correction moves the card off the word shape.
            'anchor' => ['nullable', 'string'],
            'anchor_translation' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
        ];
    }
}
