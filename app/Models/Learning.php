<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Learning
{
    /**
     * Words mode deals individual base words, not cards, so its cap is counted in words.
     * A due card enters the pool only if ALL of its base words fit under this — no card
     * ever contributes a partial word set, or it could never clear. See
     * docs/learning-flow.md "Words mode".
     */
    public const WORDS_PER_SESSION = 15;

    /**
     * The cards a session serves. Takes either the structured selection from the builder,
     * or a legacy string filter from the older entry points: 'due', a wordbox id (that
     * wordbox's own page — always cram) or a theme name.
     */
    public static function getCardsForLearning($filter)
    {
        if (is_array($filter)) {
            return self::getCardsForSelection($filter);
        }

        $query = Auth::user()->cards()->with(['wordbox:id,name', 'baseWords']);

        if (is_numeric($filter)) {
            session(['more_cards_available' => false]);

            return $query->whereHas('wordbox', fn ($q) => $q->where('wordboxes.id', $filter))->get()->shuffle();
        }

        if ($filter !== 'due') {
            $theme = Auth::user()->themes()->where('name', $filter)->first();

            if (! $theme) {
                return collect();
            }

            $query->where('theme_id', $theme->id);
        }

        self::onlyDue($query);

        return $query->get()->shuffle();
    }

    /**
     * Narrow a query to due cards, capped: past 20 due, only 15 are served and the session
     * is told there are more.
     */
    private static function onlyDue($query): void
    {
        $query->whereDate('next_study_at', '<=', now()->toDateString());

        $more = (clone $query)->count() > 20;
        session(['more_cards_available' => $more]);

        if ($more) {
            $query->limit(15);
        }
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
            self::onlyDue($query);
        } else {
            session(['more_cards_available' => false]);
        }

        return $query->get()->shuffle();
    }

    public static function setLearning($filter)
    {
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
            $entry = match ($mode) {
                // A native-language card has no translation, so its definition stands in.
                // No hint: it was the example sentence, which cards no longer have.
                'translation' => ['front' => $card->translation ?: $card->definition, 'back' => $card->target(), 'hint' => ''],
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
     * card costs a later one nothing against the cap.
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
            $wordbox = $card->wordbox->first()?->name ?? '';

            foreach ($baseWords as $baseWord) {
                $entries[$baseWord->id] ??= self::wordEntry($baseWord) + ['wordbox' => $wordbox];
                $entries[$baseWord->id]['card_ids'][] = $card->id;
            }
        }

        $entries = array_values($entries);
        shuffle($entries);

        return $entries;
    }

    /**
     * One base word as a flashcard. The back is always the LEMMA in its display form —
     * "ett hus", never bare "hus" and never the inflected form the parent card's
     * Term happens to use. Part of speech travels with it because two base words can share
     * a lemma and differ only by it. No hint: it was the parent card's example sentence,
     * which cards no longer have.
     */
    public static function wordEntry(BaseWord $baseWord): array
    {
        return [
            'id' => $baseWord->id,
            'front' => $baseWord->translation,
            'back' => $baseWord->displayForm(),
            'part_of_speech' => $baseWord->part_of_speech,
            'hint' => '',
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
