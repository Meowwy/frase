<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            // Nullable so the field can be cleared; the column is NOT NULL, hence the
            // coalesce in the controller.
            'note' => ['nullable', 'string'],
        ];
    }
}
