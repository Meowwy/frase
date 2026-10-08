<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * ORGANIZE: the vocabulary base's own page.
 *
 * The base is one entry per lemma and part of speech in one language. It carries no
 * card schedule and no generated content — its jobs are deduplication and coverage, so the page
 * is about what the learner has met and where, not about what is due.
 */
class BaseWordController extends Controller
{
    /** Rows per page on both /base tabs. */
    private const PER_PAGE = 50;

    /**
     * The vocabulary base, one language at a time, with each entry's coverage — the cards
     * using the word, counted in the table and listed in the side panel. Its second tab is
     * the expression base, laid out the same way.
     *
     * Like the /cards list, one endpoint serves both the page and the live search: an AJAX
     * request gets just the rows and pagination back. A `selected` id (a card's word or
     * expression chip links here with one) opens the page that row is on, with the row
     * picked and its cards in the side panel.
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
            $query = $user->fixedExpressions()
                ->with('cards:id,term')
                ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
                ->when($search !== '', fn ($q) => $q->where('form', 'like', '%'.$search.'%'))
                ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'));
            $selected = $this->selected($request, $query, 'form');
            $expressions = $query->orderBy('form')->orderBy('id')
                ->paginate(self::PER_PAGE, page: $selected['page'])
                ->appends($request->except('selected'));

            return $this->respond($request, 'base.expressions', 'base._expression-rows', $view + [
                'expressions' => $expressions,
                'selectedId' => $selected['id'],
            ], $expressions);
        }

        $partOfSpeech = (string) $request->query('part_of_speech', '');

        $query = $user->baseWords()
            ->with(['language', 'cards:id,term'])
            ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
            ->when($search !== '', fn ($q) => $q->where('lemma', 'like', '%'.$search.'%'))
            ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'))
            ->when($partOfSpeech !== '', fn ($q) => $q->where('part_of_speech', $partOfSpeech));
        $selected = $this->selected($request, $query, 'lemma');
        $baseWords = $query->orderBy('lemma')->orderBy('id')
            ->paginate(self::PER_PAGE, page: $selected['page'])
            ->appends($request->except('selected'));

        return $this->respond($request, 'base.index', 'base._word-rows', $view + [
            'baseWords' => $baseWords,
            'selectedId' => $selected['id'],
            'partOfSpeech' => $partOfSpeech,
            // The filter offers only the parts of speech this language's base actually has.
            'partsOfSpeech' => $user->baseWords()
                ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
                ->distinct()->orderBy('part_of_speech')->pluck('part_of_speech'),
        ], $baseWords);
    }

    /**
     * The `selected` row, if it is in this (filtered) table: its id, and the page it falls
     * on — counted from how many rows sort before it — unless a page was asked for.
     *
     * @return array{id: ?int, page: ?int}
     */
    private function selected(Request $request, Relation $query, string $column): array
    {
        $row = $request->query('selected') ? (clone $query)->find($request->query('selected')) : null;

        if (! $row) {
            return ['id' => null, 'page' => null];
        }

        $before = (clone $query)->where(fn ($q) => $q->where($column, '<', $row->{$column})
            ->orWhere(fn ($q) => $q->where($column, $row->{$column})->where('id', '<', $row->id)))
            ->count();

        return ['id' => $row->id, 'page' => $request->query('page') ? null : intdiv($before, self::PER_PAGE) + 1];
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
