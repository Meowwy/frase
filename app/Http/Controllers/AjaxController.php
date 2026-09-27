<?php

namespace App\Http\Controllers;

use App\Models\BaseWord;
use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller
{
    /**
     * Persist a finished flashcard session. Two parallel arrays, both batched into this
     * one request at session end:
     *
     *  - `results` — `{id, result}` per CARD. A correct result clears the card (level and
     *    schedule advance) and stamps last-recall on every base word linked to it:
     *    producing the Term is producing all of its words.
     *  - `words` — `{id, result}` per BASE WORD, which Words mode sends. A correct answer
     *    stamps that word's own last recall; nothing here touches a card's schedule, since
     *    whether the card cleared is already decided in the `results` array.
     *
     * See docs/learning-flow.md "SRS algorithm".
     */
    public function saveLearning(Request $request)
    {
        $results = json_decode($request->input('results'), true) ?? [];
        $wordResults = json_decode($request->input('words'), true) ?? [];

        // Scope to the current user's own cards in one query, then skip any id that
        // isn't in that set — silently ignores both a missing id and an id the user
        // doesn't own, rather than mutating another user's SRS state.
        $cards = Auth::user()->cards()
            ->with('baseWords')
            ->whereIn('id', array_column($results, 'id'))
            ->get()
            ->keyBy('id');

        foreach ($results as $r) {
            $card = $cards->get($r['id']);
            if (! $card) {
                continue;
            }

            $card->next_study_at = Learning::getNextStudyDay($card->level, $r['result']);
            $r['result'] === 1 ? $card->level++ : $card->level = 1;
            $card->last_studied = now();
            $card->save();

            if ($r['result'] === 1) {
                BaseWord::whereIn('id', $card->baseWords->modelKeys())->update(['last_recalled_at' => now()]);
            }
        }

        $correctWordIds = array_column(array_filter($wordResults, fn ($w) => ($w['result'] ?? 0) === 1), 'id');

        if ($correctWordIds) {
            Auth::user()->baseWords()->whereIn('id', $correctWordIds)->update(['last_recalled_at' => now()]);
        }

        return redirect('/completeLearning');
    }
}
