<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Support\Lexicon;
use Illuminate\Http\Request;

/**
 * The one lexicon search endpoint, behind the shared <x-lexicon-search> component: /base's
 * "Add word manually", the ignored-words page and the status page all call it. The lexicon is
 * reference data shared by every learner, so any language with one can be searched. See
 * docs/cards.md "The lexicon".
 */
class LexiconController extends Controller
{
    public function search(Request $request)
    {
        $data = $request->validate([
            'language_id' => ['required', 'integer', 'exists:languages,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $language = Language::findOrFail($data['language_id']);

        return response()->json(['results' => Lexicon::search($language->code, (string) ($data['q'] ?? ''))]);
    }
}
