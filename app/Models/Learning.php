<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class Learning extends Model
{
    public static function getCardsForLearning($filter){
        if($filter === 'due') {
            try {
                $dueCardsCount = Auth::user()->cards()
                    ->whereDate('next_study_at', '<=', now()->toDateString())
                    ->count();

                if ($dueCardsCount > 20) {
                    $cards = Auth::user()->cards()
                        ->with('theme:id,name')
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with('theme:id,name')
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->get();
                    session(['more_cards_available' => false]);
                }
            }catch (\Exception $exception){
                $cards = [];
            }

        } elseif (is_numeric($filter)){
            try {
                $cards = Auth::user()->wordboxes()
                    ->where('id', $filter)
                    ->firstOrFail()
                    ->cards()
                    ->with('theme:id,name')
                    ->get();
            }catch (\Exception $exception){
                $cards = [];
            }
        } else{
            try {
                $theme = Theme::where('name', $filter)->first();
                $dueCardsCount = Auth::user()->cards()
                    ->where('theme_id', $theme->id)
                    ->whereDate('next_study_at', '<=', now()->toDateString())
                    ->count();

                if ($dueCardsCount > 20) {
                    $cards = Auth::user()->cards()
                        ->with('theme:id,name')
                        ->where('theme_id', $theme->id)
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with('theme:id,name')
                        ->where('theme_id', $theme->id)
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->get();
                    session(['more_cards_available' => false]);
                }

            }catch (\Exception $exception){
                $cards = [];
            }
            }
        return $cards->shuffle();
    }

    public static function setLearning($filter){
        //Cache::put('learning_filter',$filter, now()->addMinutes(15));
        session(['learning_filter' => $filter]);
        return redirect('/setLearning');
    }

    public static function startLearning($wbid,$mode){
        if($wbid != 0){
            $hasWordbox = Auth::user()->wordboxes()->where('id', $wbid)->first();
            if($hasWordbox){
                session(['learning_filter' => $wbid]);
            }else{
                abort(403, 'Unauthorized action.');
            }
        }
        if (!session()->has('learning_filter')) {
            return redirect('/');
        }
        session(['learning_mode' => $mode]);

        $cardsForLearning = self::getCardsForLearning(session('learning_filter'));
        $cards = [];
        if($mode === 'sentences'){
            foreach ($cardsForLearning as $index => $card) {
                $front = preg_replace('/\[.*?\]/', '...', $card->example_sentence);
                $cards[] = [
                    'id' => $card->id,
                    'front' => $front,
                    'back' => $card->phrase,
                    'hint' => $card->translation,
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);

        } elseif ($mode === 'questions'){
            foreach ($cardsForLearning as $index => $card) {
                $hint = preg_replace('/\[.*?\]/', '...', $card->example_sentence);
                $cards[] = [
                    'id' => $card->id,
                    'front' => $card->question,
                    'back' => $card->phrase,
                    'hint' => $hint,
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
        } elseif ($mode === 'words'){
            foreach ($cardsForLearning as $index => $card) {
                $hint = preg_replace('/\[.*?\]/', '...', $card->example_sentence);
                $cards[] = [
                    'id' => $card->id,
                    'front' => $card->translation,
                    'back' => $card->phrase,
                    'hint' => $hint,
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
        } elseif ($mode === 'definitions'){
            foreach ($cardsForLearning as $index => $card) {
                $cards[] = [
                    'id' => $card->id,
                    'front' => $card->definition,
                    'back' => $card->phrase,
                    'hint' => $card->translation,
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
        }
    }

    public static function getNextStudyDay($level, $result)
    {
        if($result === 1){
            return now()->addDays(pow(2,$level - 1));
        } else{
            return now()->addDays(1);
        }

    }
}
