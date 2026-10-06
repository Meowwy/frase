<?php

namespace App\Http\Controllers;

use App\Models\BaseWord;
use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * ORGANIZE and LEARN, vocabulary-base side: the base's own page, and Refresher.
 *
 * The base is one entry per lemma and part of speech in one language. It carries no
 * schedule and no generated content — its jobs are deduplication and coverage, so the page
 * is about what the learner has met and where, not about what is due.
 */
class BaseWordController extends Controller
{
    /**
     * How many words one Refresher sitting deals. Refresher has no session scope — this is
     * just a page size, so that "the whole vocabulary base, most stale first" stays a
     * finite deck.
     */
    private const REFRESHER_BATCH = 30;

    /**
     * The vocabulary base, one language at a time, with each entry's coverage — the cards
     * using the word, counted in the table and listed in the side panel. Its second tab is
     * the expression base, laid out the same way.
     *
     * Like the /cards list, one endpoint serves both the page and the live search: an AJAX
     * request gets just the rows and pagination back.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $languageId = $this->resolveLanguage($request);
        $search = trim((string) $request->query('search', ''));
        $translation = trim((string) $request->query('translation', ''));
        $view = [
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            'activeLanguageId' => $languageId,
            'search' => $search,
            'translation' => $translation,
        ];

        if ($request->query('tab') === 'expressions') {
            $expressions = $user->fixedExpressions()
                ->with('cards:id,term')
                ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
                ->when($search !== '', fn ($q) => $q->where('form', 'like', '%'.$search.'%'))
                ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'))
                ->orderBy('form')
                ->paginate(50)
                ->appends($request->query());

            return $this->respond($request, 'base.expressions', 'base._expression-rows', $view + ['expressions' => $expressions], $expressions);
        }

        $partOfSpeech = (string) $request->query('part_of_speech', '');

        $baseWords = $user->baseWords()
            ->with(['language', 'cards:id,term'])
            ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
            ->when($search !== '', fn ($q) => $q->where('lemma', 'like', '%'.$search.'%'))
            ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'))
            ->when($partOfSpeech !== '', fn ($q) => $q->where('part_of_speech', $partOfSpeech))
            ->orderBy('lemma')
            ->paginate(50)
            ->appends($request->query());

        return $this->respond($request, 'base.index', 'base._word-rows', $view + [
            'baseWords' => $baseWords,
            'partOfSpeech' => $partOfSpeech,
            // The filter offers only the parts of speech this language's base actually has.
            'partsOfSpeech' => $user->baseWords()
                ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
                ->distinct()->orderBy('part_of_speech')->pluck('part_of_speech'),
        ], $baseWords);
    }

    /**
     * The full page, or for the live search just the rows and pagination.
     */
    private function respond(Request $request, string $page, string $rows, array $view, LengthAwarePaginator $paginator)
    {
        if ($request->ajax()) {
            return response()->json([
                'rows' => view($rows, $view)->render(),
                'pagination' => $paginator->links()->toHtml(),
            ]);
        }

        return view($page, $view);
    }

    /**
     * Refresher: free-form practice over the base, ordered by staleness. A correct answer
     * stamps that word's last recall and nothing else — no schedule, and it never clears a
     * card, which is what separates it from Words mode.
     *
     * It reuses the flashcard view in its word-dealing shape, and posts its results through
     * /saveLearning's per-word array with no card array at all — "stamp last recall and
     * touch nothing else" is exactly what that endpoint then does.
     */
    public function refresher(Request $request)
    {
        $languageId = $this->resolveLanguage($request);

        $baseWords = Auth::user()->baseWords()
            ->with(['language', 'cards' => fn ($q) => $q->select('cards.id', 'example_sentence')])
            ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
            // Never recalled is as stale as it gets, so nulls come first.
            ->orderByRaw('last_recalled_at is not null, last_recalled_at asc')
            ->limit(self::REFRESHER_BATCH)
            ->get();

        // Refresher has no session scope, so make sure the completion page it shares with
        // the learning modes doesn't offer to "continue another set" of whatever mode and
        // filter were left in the session by an earlier, unrelated session.
        session(['learning_mode' => 'refresher', 'more_cards_available' => false]);

        $deck = $baseWords->map(function (BaseWord $baseWord) {
            $sentence = (string) ($baseWord->cards->first()?->example_sentence ?? '');

            return Learning::wordEntry($baseWord, preg_replace('/\[.*?\]/', '...', $sentence)) + ['wordbox' => ''];
        })->all();

        return Learning::renderDeck($deck, 'refresher');
    }

    /**
     * Which language the page is scoped to: an explicit (owned) one, else the learner's
     * default. Null only when they have no languages set up yet.
     */
    private function resolveLanguage(Request $request): ?int
    {
        $user = Auth::user();
        $requested = $request->query('language_id');

        if ($requested && $user->languages()->whereKey($requested)->exists()) {
            return (int) $requested;
        }

        return $user->currentSaveLanguage()?->id;
    }
}
