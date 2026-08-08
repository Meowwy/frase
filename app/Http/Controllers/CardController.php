<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Jobs\CreateCardJob;
use App\Models\AI;
use App\Models\Card;
use App\Models\Theme;
use App\Models\User;
use Carbon\Carbon;
use http\Env\Request;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    /**
     * Display the vocabulary list, filtered by language + wordbox + live search.
     *
     * One endpoint serves both the full page and the live updates: a normal request
     * renders the page, an AJAX request returns just the rows + pagination so the
     * shared <x-wordbox-picker> and the header search inputs can refresh in place.
     */
    public function index(\Illuminate\Http\Request $request)
    {
        $user = Auth::user();

        $wordbox = $request->query('wordbox', 'all'); // 'all' | 'general' | <wordbox id>
        $term = trim((string) $request->query('term', ''));
        $definition = trim((string) $request->query('definition', ''));

        // Term-type filter: one of the two types, or 'both' (the default, no constraint).
        $type = (string) $request->query('type', 'both');
        if (! in_array($type, Card::TERM_TYPES, true)) {
            $type = 'both';
        }

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

        $query = $user->cards()->with('wordbox:id,name');

        if ($languageId) {
            $query->where('language_id', $languageId);
        }
        if ($theme) {
            $query->where('theme_id', $theme->id);
        }

        if ($wordbox === 'general') {
            $query->whereDoesntHave('wordbox');
        } elseif (is_numeric($wordbox)) {
            $query->whereHas('wordbox', fn ($q) => $q->where('wordboxes.id', $wordbox));
        }

        if ($type !== 'both') {
            $query->where('term_type', $type);
        }

        if ($term !== '') {
            $query->where('phrase', 'like', '%'.$term.'%');
        }
        if ($definition !== '') {
            $query->where('definition', 'like', '%'.$definition.'%');
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

        $targetLanguages = $user->languages()->orderBy('name')->get();
        $wordboxesByLanguage = $user->wordboxes()
            ->select('id', 'name', 'language_id', 'position')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->groupBy('language_id');

        return view('cards.index', [
            'cards' => $cards,
            'targetLanguages' => $targetLanguages,
            'wordboxesByLanguage' => $wordboxesByLanguage,
            'activeLanguageId' => $languageId,
            'term' => $term,
            'definition' => $definition,
            'type' => $type,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCardRequest $request)
    {
        /* this method is unused, the functionality is in the Ajax controller */
        if (Auth::user()->currency_amount <= 0) {
            return redirect('/');
        }

        $request->validate([
            'capturedWord' => ['required', 'string', 'max:40', 'regex:/^[^0-9]*$/'],
        ]);

        $userId = Auth::id();
        $phrase = request('capturedWord');
        if (! request()->filled('capturedWord')) {
            return redirect('/');
        }

        // CreateCardJob::dispatch($userId, $phrase);
        // obsah CreateCardJob
        $user = Auth::user();

        // Retrieve all themes of the authenticated user
        $themes = $user->themes()->select('id', 'name')->get();

        if (count($themes) !== 0) {
            $themeStrings = $themes->map(function ($theme) {
                return "\"{$theme->name}\"";
            });
            $themeString = $themeStrings->implode(',');
        } else {
            $themeString = '';
        }

        $content = AI::getContentForCard($this->phrase, $themeString, $user->target_language, $user->native_language);
        if (is_null($content)) {
            logger('The model refused to create the card for '.$this->phrase);

            return;
        }
        logger($content);
        $output = json_decode($content);
        $user->currency_amount = $user->currency_amount - 1;
        if ($user->currency_amount < 0) {
            $user->currency_amount = 0;
        }
        $user->save();

        try {
            $selectedTheme = $themes->firstWhere('name', $output->theme);

            $user->cards()->create([
                'phrase' => $output->phrase,
                'theme_id' => ($selectedTheme ? $selectedTheme->id : null),
                'level' => 1,
                'translation' => $output->translation,
                'example_sentence' => $output->sentence,
                'question' => $output->question,
                'definition' => $output->definition,
                'next_study_at' => now(),
            ]);
            logger('Card has been created for '.$this->phrase);
        } catch (\Exception $e) {
            logger($e->getMessage());
        }
        // konec obsahu CreateCardJob

        // return redirect('/');
        /*return response()->json([
            'success' => 'Word "' . $phrase . '" has been submitted successfully.',
            'capturedWord' => $phrase
        ], 200);*/

        return response(200);

    }

    /**
     * Display the specified resource.
     */
    public function show(Card $card)
    {
        $card->example_sentence = preg_replace('/\[(.*?)\]/', '<span class="text-gray-300 font-bold">$1</span>', $card->example_sentence);

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
            ->orderBy('phrase')
            ->get(['cards.id', 'phrase', 'translation']);

        $card->load('language');
        $wordbox = $card->wordbox()->first();

        return view('cards.show', [
            'card' => $card,
            'theme' => $theme,
            'wordbox' => $wordbox,
            'linkedCards' => $linkedCards,
        ]);
    }

    /**
     * Search the user's cards (same language as the given card) to link, excluding
     * the card itself and any already-linked cards. Returns JSON for the link picker.
     */
    public function linkSearch(\Illuminate\Http\Request $request, Card $card)
    {
        abort_unless($card->user_id === Auth::id(), 403);

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $excludedIds = $card->linkedCards()->pluck('cards.id')->push($card->id)->all();

        $results = Auth::user()->cards()
            ->where('language_id', $card->language_id)
            ->whereNotIn('id', $excludedIds)
            ->where('phrase', 'like', '%'.$q.'%')
            ->orderBy('phrase')
            ->limit(10)
            ->get(['id', 'phrase', 'translation']);

        return response()->json(['results' => $results]);
    }

    /**
     * Manually link another (same-language) card to this one. Links are stored
     * bidirectionally in the `synonyms` pivot (mirrors the auto-link convention).
     */
    public function link(\Illuminate\Http\Request $request, Card $card)
    {
        abort_unless($card->user_id === Auth::id(), 403);

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

        return response()->json(['card' => $other->only('id', 'phrase', 'translation')]);
    }

    /**
     * Remove a manual link between two cards (drops both mirrored pivot rows).
     */
    public function unlink(Card $card, Card $other)
    {
        abort_unless($card->user_id === Auth::id(), 403);
        abort_unless($other->user_id === Auth::id(), 403);

        $card->linkedCards()->detach($other->id);
        $other->linkedCards()->detach($card->id);

        return response()->json(['unlinked' => true]);
    }

    /**
     * Save the user-editable note from the card detail page.
     */
    public function saveNote(\Illuminate\Http\Request $request, Card $card)
    {
        abort_unless($card->user_id === Auth::id(), 403);

        $data = $request->validate(['note' => ['nullable', 'string']]);

        $card->update(['note' => $data['note']]);

        return response()->json(['saved' => true]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Card $card)
    {
        return view('cards.edit', ['card' => $card]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCardRequest $request, Card $card)
    {
        // Ensure that the card belongs to the authenticated user
        if ($card->user_id !== Auth::id()) {
            return redirect()->back()->with('error', 'You do not have permission to update this card.');
        }

        // Validate and get the validated data from the request
        $validatedData = $request->validated([
            'phrase' => ['required', 'string'],
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            'question' => ['required', 'string'],
            'example_sentence' => ['required', 'string'],
            'id' => ['required'],
            'theme_id' => ['required'],
        ]);

        if ($validatedData['theme_id'] === '-1') {
            $validatedData['theme_id'] = null;
        }

        // Update the card with the validated data
        $card->update($validatedData);

        // Redirect back with a success message
        return redirect('/');
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

        foreach ($cards as $card) {
            $card->wordbox()->detach(); // drop pivot rows so none are orphaned
            $card->delete();
        }

        return response()->json(['deleted' => $cards->count()]);
    }

    /**
     * (Re)assign the selected cards to a wordbox (bulk row action from /cards).
     * A card is treated as belonging to a single wordbox, so we sync the pivot
     * to just the target — moving cards that were already in another wordbox.
     */
    public function assignWordbox(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'wordbox_id' => ['required', 'integer'],
        ]);

        $user = Auth::user();
        $wordbox = $user->wordboxes()->where('id', $data['wordbox_id'])->firstOrFail();

        // The list is single-language; guard defensively so a card can't be
        // moved into a wordbox of a different language.
        $cards = $user->cards()
            ->whereIn('id', $data['ids'])
            ->where('language_id', $wordbox->language_id)
            ->get();

        foreach ($cards as $card) {
            $card->wordbox()->sync([$wordbox->id]);
        }

        return response()->json(['assigned' => $cards->count(), 'wordbox' => $wordbox->name]);
    }

    /**
     * Display a form to create a resource.
     */
    public function create()
    {
        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        $themesArray = $themes->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();

        return view('cards.add', ['themes' => $themesArray]);
    }

    public function save(StoreCardRequest $request)
    {
        $request->validate([
            'phrase' => ['required', 'string'],
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            'example_sentence' => ['required', 'string'],
            'question' => ['required', 'string'],
            'theme_id' => ['required'],
        ]);

        $user = User::find($this->userId);

        $user->cards()->create([
            'phrase' => $request->phrase,
            'theme_id' => ($request->theme_id != -1 ? $request->theme_id : null),
            'level' => 1,
            'translation' => $request->translation,
            'example_sentence' => $request->sentence,
            'question' => $request->question,
            'definition' => $request->definition,
            'next_study_at' => now(),
        ]);
        logger('Card has been created for '.$this->phrase);
    }
}
