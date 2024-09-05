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
            return $cards;
        }
    }

    public static function setLearning($filter){
        //Cache::put('learning_filter',$filter, now()->addMinutes(15));
        session(['learning_filter' => $filter]);
        return redirect('/setLearning');
    }

    public static function startLearning($mode){
        if (!session()->has('learning_filter')) {
            //inform user
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
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);

        } elseif ($mode === 'questions'){
            foreach ($cardsForLearning as $index => $card) {
                $cards[] = [
                    'id' => $card->id,
                    'front' => $card->question,
                    'back' => $card->phrase,
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
        } elseif ($mode === 'words'){
            foreach ($cardsForLearning as $index => $card) {
                $cards[] = [
                    'id' => $card->id,
                    'front' => $card->translation,
                    'back' => $card->phrase,
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
                    'theme' => $card->theme ? $card->theme->name : 'no theme',
                ];
            }
            $cardsForJS = "let cards = " .json_encode($cards).";";
            return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
        }
    }

    public  static function saveLearning(){

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
