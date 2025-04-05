<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Wordbox;
use App\Models\GapFillExercise;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\WordboxController;


class GapFillController extends Controller
{
    public function show(Wordbox $wordbox, GapFillExercise $gapFillExercise)
    {
        //Check if the wordbox belongs to the user
        if ($wordbox->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $textWithGaps = $gapFillExercise->text_with_gaps;
        $usedWords = json_decode($gapFillExercise->used_words, true); // Decode JSON

        return view('gapfill.show', compact('textWithGaps', 'usedWords', 'wordbox'));
    }


    public function generate(Request $request, Wordbox $wordbox) {
        //Check if the wordbox belongs to the user
        if ($wordbox->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $result = (new WordboxController())->generateGapFill($wordbox);

        // Check if the result is an error response
        if (isset($result->original) && is_array($result->original) && isset($result->original['error'])) {
            // Handle the error (e.g., return an error view or redirect with an error message)
            return back()->with('error', $result->original['error']);
        }

        // Create a new GapFillExercise record in the database
        $gapFillExercise = new GapFillExercise();
        $gapFillExercise->wordbox_id = $wordbox->id;
        $gapFillExercise->text_with_gaps = $result['textWithGaps'];
        $gapFillExercise->used_words = json_encode($result['usedWords']);
        $gapFillExercise->save();

        // Redirect to the gapfill.show route with the necessary parameters
        return Redirect::route('gapfill.show', [
            'wbid' => $wordbox->id,
            'gapFillId' => $gapFillExercise->id,
        ]);

    }

}
