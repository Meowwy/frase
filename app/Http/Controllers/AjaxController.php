<?php

namespace App\Http\Controllers;

use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller
{
    /**
     * Persist a finished flashcard session: `results`, `{id, result}` per card, batched into
     * this one request at session end. A correct result clears the card (level and schedule
     * advance) and stamps last-recall on every base word and fixed expression linked to it:
     * producing the Term is producing all of them.
     *
     * See docs/learning-flow.md "SRS algorithm".
     */
    public function saveLearning(Request $request)
    {
        $results = json_decode($request->input('results'), true) ?? [];

        // Scope to the current user's own cards in one query, then skip any id that
        // isn't in that set — silently ignores both a missing id and an id the user
        // doesn't own, rather than mutating another user's SRS state.
        $cards = Auth::user()->cards()
            ->with(['baseWords', 'fixedExpressions'])
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
                $card->stampRecall();
            }
        }

        return redirect('/completeLearning');
    }
}
