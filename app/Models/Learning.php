<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class Learning extends Model
{
    public static function getCardsForLearning($filter)
    {
        if (is_array($filter)) {
            return self::getCardsForSelection($filter);
        }
        if ($filter === 'due') {
            try {
                $dueCardsCount = Auth::user()->cards()
                    ->whereDate('next_study_at', '<=', now()->toDateString())
                    ->count();

                if ($dueCardsCount > 20) {
                    $cards = Auth::user()->cards()
                        ->with('wordbox:id,name')
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with('wordbox:id,name')
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->get();
                    session(['more_cards_available' => false]);
                }
            } catch (\Exception $exception) {
                $cards = [];
            }

        } elseif (is_numeric($filter)) {
            try {
                $cards = Auth::user()->wordboxes()
                    ->where('id', $filter)
                    ->firstOrFail()
                    ->cards()
                    ->with('wordbox:id,name')
                    ->get();
            } catch (\Exception $exception) {
                $cards = [];
            }
        } else {
            try {
                $theme = Theme::where('name', $filter)->first();
                $dueCardsCount = Auth::user()->cards()
                    ->where('theme_id', $theme->id)
                    ->whereDate('next_study_at', '<=', now()->toDateString())
                    ->count();

                if ($dueCardsCount > 20) {
                    $cards = Auth::user()->cards()
                        ->with('wordbox:id,name')
                        ->where('theme_id', $theme->id)
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with('wordbox:id,name')
                        ->where('theme_id', $theme->id)
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->get();
                    session(['more_cards_available' => false]);
                }

            } catch (\Exception $exception) {
                $cards = [];
            }
        }

        return $cards->shuffle();
    }

    /**
     * Build a learning set from the card-set builder selection:
     * language + (all | general vocabulary | a wordbox) + (due | cram).
     */
    protected static function getCardsForSelection(array $filter)
    {
        $languageId = $filter['language_id'] ?? null;
        $wordbox = $filter['wordbox'] ?? 'all';
        $scope = $filter['scope'] ?? 'due';

        $query = Auth::user()->cards()->with('wordbox:id,name');

        if ($languageId) {
            $query->where('language_id', $languageId);
        }

        if ($wordbox === 'general') {
            // "General vocabulary" = terms not attached to any wordbox.
            $query->whereDoesntHave('wordbox');
        } elseif (is_numeric($wordbox)) {
            $query->whereHas('wordbox', function ($q) use ($wordbox) {
                $q->where('wordboxes.id', $wordbox);
            });
        }
        // 'all' → no wordbox constraint (every term in the language).

        if ($scope === 'due') {
            $query->whereDate('next_study_at', '<=', now()->toDateString());

            if ((clone $query)->count() > 20) {
                session(['more_cards_available' => true]);
                $query->limit(15);
            } else {
                session(['more_cards_available' => false]);
            }
        } else {
            session(['more_cards_available' => false]);
        }

        return $query->get()->shuffle();
    }

    public static function setLearning($filter)
    {
        // Cache::put('learning_filter',$filter, now()->addMinutes(15));
        session(['learning_filter' => $filter]);

        return redirect('/setLearning');
    }

    public static function startLearning($wbid, $mode)
    {
        if ($wbid != 0) {
            $hasWordbox = Auth::user()->wordboxes()->where('id', $wbid)->first();
            if ($hasWordbox) {
                session(['learning_filter' => $wbid]);
            } else {
                abort(403, 'Unauthorized action.');
            }
        }
        if (! session()->has('learning_filter')) {
            return redirect('/');
        }

        return self::renderLearningView($mode);
    }

    /**
     * Start a learning session from the card-set builder (/setLearning).
     * Selection comes in as query params: language_id, wordbox, scope.
     */
    public static function startLearningSet(\Illuminate\Http\Request $request, $mode)
    {
        $user = Auth::user();
        $languageId = $request->query('language_id');
        $wordbox = $request->query('wordbox', 'all');
        $scope = $request->query('scope', 'due');

        if ($languageId && ! $user->languages()->where('languages.id', $languageId)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        if (is_numeric($wordbox)) {
            $box = $user->wordboxes()->where('id', $wordbox)->first();
            if (! $box) {
                abort(403, 'Unauthorized action.');
            }
            // Keep language consistent with the chosen wordbox.
            $languageId = $box->language_id;
        } elseif (! in_array($wordbox, ['all', 'general'], true)) {
            $wordbox = 'all';
        }

        if (! in_array($scope, ['due', 'cram'], true)) {
            $scope = 'due';
        }

        session(['learning_filter' => [
            'language_id' => $languageId,
            'wordbox' => $wordbox,
            'scope' => $scope,
        ]]);

        return self::renderLearningView($mode);
    }

    /**
     * Render the flashcard view for the current session filter in the given mode.
     */
    protected static function renderLearningView($mode)
    {
        session(['learning_mode' => $mode]);

        $cardsForLearning = self::getCardsForLearning(session('learning_filter'));
        $cards = [];

        foreach ($cardsForLearning as $card) {
            $blankedSentence = preg_replace('/\[.*?\]/', '...', $card->example_sentence);
            $wordbox = $card->wordbox->first()?->name ?? '';

            $entry = match ($mode) {
                'sentences' => ['front' => $blankedSentence, 'back' => $card->phrase, 'hint' => $card->translation],
                'questions' => ['front' => $card->question, 'back' => $card->phrase, 'hint' => $blankedSentence],
                'words' => ['front' => $card->translation, 'back' => $card->phrase, 'hint' => $blankedSentence],
                'definitions' => ['front' => $card->definition, 'back' => $card->phrase, 'hint' => $card->translation],
                default => null,
            };

            if ($entry === null) {
                abort(404);
            }

            $cards[] = ['id' => $card->id] + $entry + ['wordbox' => $wordbox];
        }

        $cardsForJS = 'let cards = '.json_encode($cards).';';

        return view('learning.index', ['cards' => $cardsForJS, 'cardCount' => count($cards)]);
    }

    public static function getNextStudyDay($level, $result)
    {
        if ($result === 1) {
            return now()->addDays(pow(2, $level - 1));
        } else {
            return now()->addDays(1);
        }

    }
}
