<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\Language;
use App\Support\Lexicon;
use Illuminate\Support\Facades\Auth;

/**
 * A small status page under the account settings: can the app reach OpenAI, and is the
 * lexicon loaded for the languages the learner studies. See docs/auth-and-users.md.
 */
class StatusController extends Controller
{
    public function index()
    {
        $loaded = Lexicon::summary();
        $languages = Auth::user()->languages()->orderBy('name')->get()
            ->concat(Language::whereIn('code', $loaded->keys())->get())
            ->unique('id')
            ->sortBy('name');

        return view('user.status', ['languages' => $languages, 'lexicon' => $loaded]);
    }

    /** The OpenAI check is a live request, so the page asks for it after it has rendered. */
    public function openai()
    {
        return response()->json(AI::checkConnection());
    }
}
