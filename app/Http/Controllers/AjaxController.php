<?php

namespace App\Http\Controllers;

use App\Jobs\CallAIJob;
use App\Jobs\CreateCardJob;
use App\Models\AI;
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
            'capturedWord' => ["required", "string", "min:2", "max:50"],
            'context' => ["nullable", "string", "min:2", "max:250"]
        ]);

        // Extract the captured word
        $capturedWord = $request->input('capturedWord');
        $context = $request->input('context');

        $userId = Auth::id();
        $phrase = request('capturedWord');
        if (!request()->filled('capturedWord')) {
            return redirect('/');
        }

        //CreateCardJob::dispatch($userId, $phrase);
        //CallAIJob::dispatch($userId, $phrase,$user->native_language,$user->target_language, $themeString);
        //obsah CreateCardJob
        $user = Auth::user();
        // Retrieve all themes of the authenticated user
        $themes = $user->themes()->select('id', 'name')->get();


        if (count($themes) !== 0) {
            $themeStrings = $themes->take(20)->map(function ($theme) {
                return "\"{$theme->name}\"";
            });
            $themeString = $themeStrings->implode(',');
        } else {
            $themeString = 'no categories defined';
        }

        if(is_null($context)){
            $content = AI::getContentForCard($capturedWord, $themeString, $user->target_language, $user->native_language);
        }else{
            $content = AI::getContentForCardWithContext($capturedWord, $themeString, $user->target_language, $user->native_language,$context);
        }
        if(is_null($content)){
            logger('The model refused to create the card for '.$request->capturedWord);
            //return;
        }
        try {
            $cleanedContent = trim($content);
            $output = json_decode($cleanedContent);
            /*$user->currency_amount = $user->currency_amount - 1;
            if ($user->currency_amount < 0) {
                $user->currency_amount = 0;
            }
            $user->save();*/

            $selectedTheme = $themes->firstWhere('name', strtolower($output->theme));

            $recentThemeId = null;

            if(is_null($selectedTheme)){
                $themeCount = Auth::user()->themes()->count();
                if($themeCount < 20){
                    Auth::user()->themes()->create([
                        'name' => strtolower($output->theme)
                    ]);
                    $recentThemeId = Auth::user()->themes()
                        ->orderBy('created_at', 'desc')
                        ->value('id');
                }
            }

            $user->cards()->create([
                'phrase' => $output->phrase,
                'theme_id' => ($selectedTheme ? $selectedTheme->id : $recentThemeId),
                'level' => 1,
                'translation' => $output->translation,
                'example_sentence' => $output->sentence,
                'question' => $output->question,
                'definition' => $output->definition,
                'next_study_at' => now()
            ]);
            logger('Card has been created for '.$output->phrase);
        }catch (\Exception){

            return redirect("/")->with('popup_message', 'There was an error while creating the card. Click OK to continue.');
        }

        //konec obsahu CreateCardJob
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
            $card->last_studied = now();
            $card->save();
        }

        return redirect('/completeLearning');
    }

    public function saveThemes(Request $request){

    }
}
