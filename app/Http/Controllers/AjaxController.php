<?php

namespace App\Http\Controllers;

use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller
{
    /**
     * Persist flashcard results: `results`, `{id, result}` per card, its first answer, sent as
     * soon as the card leaves the deck (answered correctly), so closing the browser loses
     * nothing already cleared, and a card still waiting on a repeat after a miss is not saved
     * at all — it stays due as if never shown. A correct result clears the card (level and schedule
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

        // Each card is saved by itself (AJAX) as it leaves the deck; the plain post at the end
        // carries nothing new and only leaves the session.
        return $request->ajax() ? response()->noContent() : redirect('/completeLearning');
    }
}
