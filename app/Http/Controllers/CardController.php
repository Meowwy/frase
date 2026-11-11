<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\IgnoredWord;
use App\Models\Theme;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    /**
     * Display the vocabulary list, filtered by language + live search.
     *
     * One endpoint serves both the full page and the live updates: a normal request
     * renders the page, an AJAX request returns just the rows + pagination so the
     * shared <x-language-switcher> and the header search input can refresh in place.
     */
    public function index(\Illuminate\Http\Request $request)
    {
        $user = Auth::user();

        $term = trim((string) $request->query('term', ''));

        // Legacy entry: dashboard theme card links here with ?theme=<name>. Pre-filter
        // by that theme and open the picker on the theme's language.
        $theme = null;
        if ($themeName = $request->query('theme')) {
            $theme = $user->themes()->where('name', $themeName)->first();
        }

        // Resolve which language the list (and picker) is scoped to.
        $languageId = $request->query('language_id') ?: ($theme?->language_id ?? $user->currentSaveLanguage()?->id);
        if ($languageId && ! $user->languages()->where('languages.id', $languageId)->exists()) {
            $languageId = $user->currentSaveLanguage()?->id;
        }

        $query = $user->cards();

        if ($languageId) {
            $query->where('language_id', $languageId);
        }
        if ($theme) {
            $query->where('theme_id', $theme->id);
        }

        if ($term !== '') {
            $query->where('term', 'like', '%'.$term.'%');
        }

        $cards = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->appends($request->query());

        if ($request->ajax()) {
            return response()->json([
                'rows' => view('cards._rows', ['cards' => $cards])->render(),
                'pagination' => $cards->links()->toHtml(),
            ]);
        }

        return view('cards.index', [
            'cards' => $cards,
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            'activeLanguageId' => $languageId,
            'term' => $term,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Card $card)
    {
        $this->authorize('view', $card);

        if (! is_null($card->theme_id)) {
            $theme = Theme::where('user_id', Auth::id())
                ->where('id', $card->theme_id)
                ->pluck('name')
                ->first();
        } else {
            $theme = null;
        }

        if (! is_null($card->last_studied)) {

            // Assuming $card->last_studied is a date variable
            $lastStudied = Carbon::parse($card->last_studied);

            // Format the date in the form of "1 January 2024"
            $formattedDate = $lastStudied->format('j F Y');

            // Calculate the number of days since last studied
            $daysAgo = floor($lastStudied->diffInDays(Carbon::now()));

            // Save the number of days to a new property
            $card->last_studied_days = $daysAgo;

            // Save the string with "days ago (1 January 2024)"
            $card->last_studied = $formattedDate;
        }

        $nextStudyAt = Carbon::parse($card->next_study_at);

        // Format the date in the form of "1 January 2024"
        $card->next_study_at = $nextStudyAt->format('l j F Y');

        $linkedCards = $card->linkedCards()
            ->orderBy('term')
            ->get(['cards.id', 'term', 'translation']);

        $card->load(['language', 'baseWords', 'fixedExpressions']);

        // Neighbours for the prev/next arrows: the user's other cards in this card's
        // language, walked in time: "previous" is the next older card (the row below on
        // /cards, which lists newest first) and "next" the next newer one. Ids are
        // monotonic with insertion, so they stand in for created_at without needing a
        // tie-breaker.
        $neighbours = Auth::user()->cards()->where('language_id', $card->language_id);
        $previousCard = (clone $neighbours)->where('id', '<', $card->id)->orderByDesc('id')->first(['id']);
        $nextCard = (clone $neighbours)->where('id', '>', $card->id)->orderBy('id')->first(['id']);

        return view('cards.show', [
            'card' => $card,
            'theme' => $theme,
            'linkedCards' => $linkedCards,
            'previousCard' => $previousCard,
            'nextCard' => $nextCard,
        ]);
    }

    /**
     * Search the user's cards (same language as the given card) to link, excluding
     * the card itself and any already-linked cards. Returns JSON for the link picker.
     */
    public function linkSearch(\Illuminate\Http\Request $request, Card $card)
    {
        $this->authorize('view', $card);

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $excludedIds = $card->linkedCards()->pluck('cards.id')->push($card->id)->all();

        $results = Auth::user()->cards()
            ->where('language_id', $card->language_id)
            ->whereNotIn('id', $excludedIds)
            ->where('term', 'like', '%'.$q.'%')
            ->orderBy('term')
            ->limit(10)
            ->get(['id', 'term', 'translation']);

        return response()->json(['results' => $results]);
    }

    /**
     * Manually link another (same-language) card to this one. Links are stored
     * bidirectionally in the `synonyms` pivot (mirrors the auto-link convention).
     */
    public function link(\Illuminate\Http\Request $request, Card $card)
    {
        $this->authorize('update', $card);

        $data = $request->validate(['card_id' => ['required', 'integer']]);

        $other = Auth::user()->cards()
            ->where('id', $data['card_id'])
            ->where('language_id', $card->language_id)
            ->first();

        if (! $other || $other->id === $card->id) {
            return response()->json(['message' => 'That card cannot be linked.'], 422);
        }

        $card->linkedCards()->syncWithoutDetaching([$other->id]);
        $other->linkedCards()->syncWithoutDetaching([$card->id]);

        return response()->json(['card' => $other->only('id', 'term', 'translation')]);
    }

    /**
     * Remove a manual link between two cards (drops both mirrored pivot rows).
     */
    public function unlink(Card $card, Card $other)
    {
        $this->authorize('update', $card);
        $this->authorize('update', $other);

        $card->linkedCards()->detach($other->id);
        $other->linkedCards()->detach($card->id);

        return response()->json(['unlinked' => true]);
    }

    /**
     * Save the user-editable note from the card detail page.
     */
    public function saveNote(\Illuminate\Http\Request $request, Card $card)
    {
        $this->authorize('update', $card);

        $data = $request->validate(['note' => ['nullable', 'string']]);

        $card->update(['note' => $data['note']]);

        return response()->json(['saved' => true]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Card $card)
    {
        $this->authorize('update', $card);

        $card->load(['language', 'baseWords.senses', 'baseWords.language', 'fixedExpressions']);

        return view('cards.edit', ['card' => $card]);
    }

    /**
     * The edit page's "Add word": the learner's own base words in the card's language (never
     * the lexicon — a link is to an entry the base already has) that the card doesn't link
     * yet, with their meanings to choose from.
     */
    public function wordSearch(\Illuminate\Http\Request $request, Card $card)
    {
        $this->authorize('update', $card);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $words = Auth::user()->baseWords()->with(['senses', 'language'])
            ->where('language_id', $card->language_id)
            ->whereNotIn('id', $card->baseWords()->pluck('base_words.id'))
            ->where('lemma', 'like', $q.'%')
            ->orderBy('lemma')
            ->limit(10)
            ->get();

        return response()->json(['results' => $words->map(fn (BaseWord $word) => [
            'id' => $word->id,
            'display_form' => $word->displayForm(),
            'part_of_speech' => $word->part_of_speech,
            'senses' => $word->senses->map->only('id', 'translation')->values(),
        ])]);
    }

    /**
     * Link one of the learner's base words to this card, in the meaning chosen (the word's
     * first when it has one only). The base itself is untouched: one entry per lemma + part
     * of speech, so linking never creates one.
     */
    public function linkWord(\Illuminate\Http\Request $request, Card $card)
    {
        $this->authorize('update', $card);

        $data = $request->validate([
            'base_word_id' => ['required', 'integer'],
            'base_word_sense_id' => ['nullable', 'integer'],
        ]);

        $word = Auth::user()->baseWords()->where('language_id', $card->language_id)->findOrFail($data['base_word_id']);
        $sense = $word->senses()->find($data['base_word_sense_id'] ?? 0) ?? $word->senses()->first();

        $card->baseWords()->syncWithoutDetaching([$word->id => ['base_word_sense_id' => $sense?->id]]);

        return response()->noContent();
    }

    /**
     * Strike a word off this card, as in staging: the card no longer links it, and it becomes
     * an ignored word of the language, so staging never proposes it again. The base entry
     * itself stays — other cards may use it.
     */
    public function strikeWord(Card $card, BaseWord $baseWord)
    {
        $this->authorize('update', $card);
        abort_unless($card->baseWords()->whereKey($baseWord->id)->exists(), 404);

        $card->baseWords()->detach($baseWord->id);

        $attributes = $baseWord->only('user_id', 'language_id', 'part_of_speech');

        if (! IgnoredWord::where($attributes)->whereLemma($baseWord->lemma)->exists()) {
            IgnoredWord::create($attributes + ['lemma' => $baseWord->lemma]);
        }

        return response()->noContent();
    }

    /**
     * Take a fixed expression off this card. An expression is never remembered as ignored
     * (as in staging), so this only drops the link.
     */
    public function strikeExpression(Card $card, FixedExpression $expression)
    {
        $this->authorize('update', $card);

        $card->fixedExpressions()->detach($expression->id);

        return response()->noContent();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCardRequest $request, Card $card)
    {
        $this->authorize('update', $card);

        $card->update($request->validated());

        return redirect('/cards/'.$card->id);
    }

    /**
     * Delete one or more of the user's cards (bulk row action from /cards).
     * Also serves the single-row delete (the 3-dot menu) with a one-id array.
     */
    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Scoping through cards() enforces ownership; ids not owned are ignored.
        $cards = Auth::user()->cards()->whereIn('id', $data['ids'])->get();

        $cards->each->delete();

        return response()->json(['deleted' => $cards->count()]);
    }

    /**
     * Display a form to create a resource.
     */
    public function create()
    {
        return view('cards.add', [
            'themes' => Theme::where('user_id', Auth::id())->get(['id', 'name']),
        ]);
    }

    /**
     * Manually create a card (no AI involved) — the /add form. It never goes through
     * staging: there is nothing to approve that the learner didn't already type, and no
     * base words are proposed, since nothing extracted the Term's words.
     */
    public function save(StoreCardRequest $request)
    {
        $user = Auth::user();

        $language = $user->currentSaveLanguage();
        if (! $language) {
            return redirect('/profile/edit');
        }

        $data = $request->validated();

        $card = $user->cards()->create([
            'term' => $data['term'],
            'theme_id' => ($request->theme_id != -1 ? $request->theme_id : null),
            'language_id' => $language->id,
            'level' => 1,
            'translation' => $data['translation'] ?? '',
            'note' => $data['note'] ?? null,
            'definition' => '',
            'next_study_at' => now(),
        ]);

        logger('Card has been created for '.$card->term);

        return redirect('/cards/'.$card->id);
    }
}
