<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCardJob;
use App\Models\Card;
use App\Models\Learning;
use App\Models\Theme;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller
{

    public function index(Request $request)
    {
        $request->validate([
            'capturedWord' => ["required", "string", "min:2"]
        ]);

        // Extract the captured word
        $capturedWord = $request->input('capturedWord');

        $userId = Auth::id();
        $phrase = request('capturedWord');
        if (!request()->filled('capturedWord')) {
            return redirect('/');
        }

        CreateCardJob::dispatch($userId, $phrase);

        return redirect('/');
        /*return response()->json([
            'success' => 'Word "' . $phrase . '" has been submitted successfully.',
            'capturedWord' => $phrase
        ], 200);*/

        //return response(200);
    }

    public function saveLearning(Request $request){
        // Validate the incoming request
        $results = json_decode($request->input('results'), true);

        foreach ($results as $r) {
            $card = Card::find($r['id']);
            $card->next_study_at = Learning::getNextStudyDay($card->level, $r['result']);
            $r['result'] === 1 ? $card->level++ : $card->level = 1;
            $card->save();
        }

        return redirect('/completeLearning');
    }

    public function saveThemes(Request $request){

    }
}
