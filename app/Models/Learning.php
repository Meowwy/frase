<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Learning extends Model
{
    /**
     * Words mode deals individual base words, not cards, so its cap is counted in words.
     * A due card enters the pool only if ALL of its base words fit under this — no card
     * ever contributes a partial word set, or it could never clear. See
     * docs/learning-flow.md "Words mode".
     */
    public const WORDS_PER_SESSION = 15;

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
                        ->with(['wordbox:id,name', 'baseWords'])
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with(['wordbox:id,name', 'baseWords'])
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
                    ->with(['wordbox:id,name', 'baseWords'])
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
                        ->with(['wordbox:id,name', 'baseWords'])
                        ->where('theme_id', $theme->id)
                        ->whereDate('next_study_at', '<=', now()->toDateString())
                        ->limit(15)
                        ->get();
                    session(['more_cards_available' => true]);
                } else {
                    $cards = Auth::user()->cards()
                        ->with(['wordbox:id,name', 'baseWords'])
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
     * Split a bracketed example sentence around its blank: the text before and after the
     * `[term]`, plus the exact form the brackets hide. The answer is that form, never the
     * card's `term` — the brackets hold the Term in the form this sentence happens to use
     * it in.
     */
    protected static function sentenceParts(Card $card): array
    {
        $sentence = (string) $card->example_sentence;

        if (! preg_match('/\[(.*?)\]/', $sentence, $matches, PREG_OFFSET_CAPTURE)) {
            return ['before' => $sentence, 'answer' => $card->target(), 'after' => ''];
        }

        [$blank, $offset] = $matches[0];

        return [
            'before' => substr($sentence, 0, $offset),
            'answer' => $matches[1][0],
            'after' => substr($sentence, $offset + strlen($blank)),
        ];
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

        $query = Auth::user()->cards()->with(['wordbox:id,name', 'baseWords']);

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

        // The conversation mode is a live AI chat, not a flashcard deck — hand off to
        // its own setup + view instead of building front/back/hint cards.
        if ($mode === 'conversation') {
            return self::startConversation();
        }

        $cardsForLearning = self::getCardsForLearning(session('learning_filter'));

        // Words mode deals base words rather than cards, so it builds its own deck.
        $cards = $mode === 'words'
            ? self::wordEntries($cardsForLearning)
            : self::cardEntries($cardsForLearning, $mode);

        return self::renderDeck($cards, $mode);
    }

    /**
     * Serialize a deck for the shared flashcard view, which drives the whole session
     * client-side from this one JS variable — no per-card request during review.
     */
    public static function renderDeck(array $cards, string $mode)
    {
        return view('learning.index', [
            'cards' => 'let cards = '.json_encode($cards).';',
            'cardCount' => count($cards),
            'mode' => $mode,
        ]);
    }

    /**
     * One entry per card, for every mode but Words. The answer is always the card's Term —
     * there is no focus word to elicit instead of it.
     */
    protected static function cardEntries($cards, string $mode): array
    {
        $entries = [];

        foreach ($cards as $card) {
            $blankedSentence = preg_replace('/\[.*?\]/', '...', $card->example_sentence);

            $entry = match ($mode) {
                // The back is the form the brackets actually hide, not the stored Term: the
                // gap is the question, and the sentence inflects the Term as it needs to.
                'sentences' => ['front' => $blankedSentence, 'back' => self::sentenceParts($card)['answer'], 'hint' => $card->translation],
                // Writing variant of Sentences: the front is the sentence split around the
                // blank so the view can render an inline input between the two halves. Its
                // answer comes out of that same split, so there is no separate back.
                'sentences_write' => ['hint' => $card->translation] + self::sentenceParts($card),
                'definitions' => ['front' => $card->definition, 'back' => $card->target(), 'hint' => $card->translation],
                default => abort(404),
            };

            $entries[] = ['id' => $card->id] + $entry + ['wordbox' => $card->wordbox->first()?->name ?? ''];
        }

        return $entries;
    }

    /**
     * Words mode's deck: the individual base words of the due cards, shuffled, capped at
     * WORDS_PER_SESSION. A card is admitted only if all of its base words fit, so the
     * session can actually clear it; cards with no base words at all (an expression whose
     * words were all filtered at capture) can only clear through the other modes.
     *
     * The deck is keyed by base word, not by (card, word) pair, because a word is routinely
     * linked to several cards — that is what the base is for. Asking for it once and
     * crediting the answer to every card that uses it is both less tedious and the right
     * arithmetic; `card_ids` is what carries that, and a word already dealt for an earlier
     * card costs a later one nothing against the cap. The hint is the first such card's
     * sentence.
     */
    protected static function wordEntries($cards): array
    {
        $entries = [];
        $budget = self::WORDS_PER_SESSION;

        foreach ($cards as $card) {
            $baseWords = $card->baseWords;
            $unseen = $baseWords->reject(fn (BaseWord $baseWord) => isset($entries[$baseWord->id]));

            if ($baseWords->isEmpty() || $unseen->count() > $budget) {
                continue;
            }

            $budget -= $unseen->count();
            $hint = preg_replace('/\[.*?\]/', '...', $card->example_sentence);
            $wordbox = $card->wordbox->first()?->name ?? '';

            foreach ($baseWords as $baseWord) {
                $entries[$baseWord->id] ??= self::wordEntry($baseWord, $hint) + ['wordbox' => $wordbox];
                $entries[$baseWord->id]['card_ids'][] = $card->id;
            }
        }

        $entries = array_values($entries);
        shuffle($entries);

        return $entries;
    }

    /**
     * One base word as a flashcard. The back is always the LEMMA in its display form —
     * "ett hus", never bare "hus" and never the inflected surface form the parent card's
     * Term happens to use. Part of speech travels with it because two base words can share
     * a lemma and differ only by it.
     */
    public static function wordEntry(BaseWord $baseWord, string $hint): array
    {
        return [
            'id' => $baseWord->id,
            'front' => $baseWord->translation,
            'back' => $baseWord->displayForm(),
            'part_of_speech' => $baseWord->part_of_speech,
            'hint' => $hint,
            // Which cards this answer counts towards. Empty for Refresher, which clears
            // nothing — see BaseWordController::refresher().
            'card_ids' => [],
        ];
    }

    /**
     * Set up a "Conversation" practice session: take up to 10 cards from the current
     * selection, ask the AI for an opening line, seed the ephemeral chat state in the
     * session, and render the chat view.
     */
    protected static function startConversation()
    {
        $user = Auth::user();
        $cards = self::getCardsForLearning(session('learning_filter'))->take(10)->values();

        if ($cards->isEmpty()) {
            return redirect('/setLearning');
        }

        // All cards in a selection share a language; use it for CEFR level + prompting.
        $language = $cards->first()->language;
        $level = $user->levelForLanguage($language);

        $targetWords = $cards->map(fn ($c) => ['id' => $c->id, 'term' => $c->term, 'translation' => $c->translation])->values()->all();

        // Stable per-chat key so every turn is routed to the same prompt cache.
        $cacheKey = 'conv-'.$user->id.'-'.Str::uuid()->toString();

        $opening = AI::startConversation($targetWords, $language->name, $level, $cacheKey);
        if (is_null($opening)) {
            return redirect('/setLearning')->with('popup_message', 'Could not start the conversation. Please try again.');
        }

        session(['chat_practice' => [
            'messages' => [['role' => 'assistant', 'content' => $opening]],
            'target_words' => $targetWords,
            'used_ids' => [],
            'stale_count' => 0,
            'language_id' => $language->id,
            'level' => $level,
            'cache_key' => $cacheKey,
        ]]);

        return view('learning.conversation', [
            'opening' => $opening,
            'targetWords' => $targetWords,
        ]);
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
