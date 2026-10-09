<?php

namespace App\Http\Controllers;

use App\Models\BaseWord;
use App\Models\FixedExpression;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * ORGANIZE: the vocabulary base's own page.
 *
 * The base is one entry per lemma and part of speech in one language. It carries no
 * card schedule and no generated content — its jobs are deduplication and coverage, so the page
 * is about what the learner has met and where, not about what is due.
 */
class BaseWordController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * The vocabulary base and the expression base, one language at a time, as a single list
     * with each entry's coverage — the cards using it, counted in the table and listed in the
     * side panel. The two are stored apart but learnt the same way, so the page doesn't tell
     * them apart.
     *
     * Like the /cards list, one endpoint serves both the page and the live search: an AJAX
     * request gets just the rows and pagination back. A `selected` entry (`word:<id>` or
     * `expression:<id>` — a card's chip links here with one) opens the page that row is on,
     * with the row picked and its cards in the side panel.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $languageId = $this->resolveLanguage($request);
        $search = trim((string) $request->query('search', ''));
        $translation = trim((string) $request->query('translation', ''));
        $partOfSpeech = (string) $request->query('part_of_speech', '');

        $filter = fn ($q, string $column) => $q->toBase()
            ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
            ->when($search !== '', fn ($q) => $q->where($column, 'like', '%'.$search.'%'))
            ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'));

        // Both bases in one sortable table: `lower()` matches the expressions' NOCASE form.
        // Expressions have no part of speech, so they take "expression" in its place.
        $entries = DB::query()->fromSub(
            $filter($user->baseWords(), 'lemma')->selectRaw("'word' as kind, id, lower(lemma) as sort_key, part_of_speech")
                ->unionAll($filter($user->fixedExpressions(), 'form')->selectRaw("'expression' as kind, id, lower(form) as sort_key, 'expression' as part_of_speech")),
            'entries'
        )->when($partOfSpeech !== '', fn ($q) => $q->where('part_of_speech', $partOfSpeech));

        $selected = $this->selected($request, $entries);
        $page = $entries->orderBy('sort_key')->orderBy('kind')->orderBy('id')
            ->paginate(self::PER_PAGE, page: $selected['page'])
            ->appends($request->except('selected'));

        $words = BaseWord::with(['language', 'cards:id,term'])->findMany($page->where('kind', 'word')->pluck('id'))->keyBy('id');
        $expressions = FixedExpression::with('cards:id,term')->findMany($page->where('kind', 'expression')->pluck('id'))->keyBy('id');
        $page->setCollection($page->getCollection()->map(fn ($row) => [
            'key' => $row->kind.':'.$row->id,
            'entry' => $row->kind === 'word' ? $words[$row->id] : $expressions[$row->id],
            'partOfSpeech' => $row->part_of_speech,
        ]));

        // The filter offers only the parts of speech this language's base actually has.
        $inLanguage = fn ($q) => $q->when($languageId, fn ($q) => $q->where('language_id', $languageId));
        $partsOfSpeech = $inLanguage($user->baseWords())->distinct()->orderBy('part_of_speech')->pluck('part_of_speech')
            ->when($inLanguage($user->fixedExpressions())->exists(), fn ($options) => $options->push('expression'));

        $view = [
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            'activeLanguageId' => $languageId,
            'search' => $search,
            'translation' => $translation,
            'partOfSpeech' => $partOfSpeech,
            'partsOfSpeech' => $partsOfSpeech,
            'entries' => $page,
            'selectedKey' => $selected['key'],
        ];

        if ($request->ajax()) {
            return response()->json([
                'rows' => view('base._rows', $view)->render(),
                'pagination' => $page->links()->toHtml(),
            ]);
        }

        return view('base.index', $view);
    }

    /**
     * The `selected` entry, if it is in this (filtered) list: its key, and the page it falls
     * on — counted from how many entries sort before it — unless a page was asked for.
     *
     * @return array{key: ?string, page: ?int}
     */
    private function selected(Request $request, Builder $entries): array
    {
        [$kind, $id] = array_pad(explode(':', (string) $request->query('selected', ''), 2), 2, null);
        $row = $id ? (clone $entries)->where('kind', $kind)->where('id', $id)->first() : null;

        if (! $row) {
            return ['key' => null, 'page' => null];
        }

        $before = (clone $entries)->where(fn ($q) => $q->where('sort_key', '<', $row->sort_key)
            ->orWhere(fn ($q) => $q->where('sort_key', $row->sort_key)->where('kind', '<', $row->kind))
            ->orWhere(fn ($q) => $q->where('sort_key', $row->sort_key)->where('kind', $row->kind)->where('id', '<', $row->id)))
            ->count();

        return ['key' => $kind.':'.$row->id, 'page' => $request->query('page') ? null : intdiv($before, self::PER_PAGE) + 1];
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
