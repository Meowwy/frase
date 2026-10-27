<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BaseWord;
use App\Models\EntrySynonym;
use App\Models\FixedExpression;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The vocabulary base's table (views base._table, base._rows, base._panel), shared by /base
 * and a wordbox's page, which lists only that box's words. See docs/cards.md "The
 * vocabulary base".
 */
trait ListsBaseEntries
{
    private const PER_PAGE = 50;

    /**
     * One page of $words and, when given, $expressions as a single list with each entry's
     * coverage — the cards using it, counted in the table and listed in the side panel,
     * beside its synonyms. The two are stored apart but learnt the same way, so the table
     * doesn't tell them apart.
     *
     * One endpoint serves both the page and the live search: an AJAX request gets just the
     * rows and pagination back. A `selected` entry (`word:<id>` or `expression:<id>` — a
     * card's chip links to /base with one) opens the page that row is on, with the row
     * picked and its cards in the side panel.
     */
    private function baseTable(Request $request, $words, $expressions, ?int $languageId, string $view, array $data)
    {
        $search = trim((string) $request->query('search', ''));
        $translation = trim((string) $request->query('translation', ''));
        $partOfSpeech = (string) $request->query('part_of_speech', '');

        $filter = fn ($q, string $column) => (clone $q)->toBase()
            ->when($languageId, fn ($q) => $q->where('language_id', $languageId))
            ->when($search !== '', fn ($q) => $q->where($column, 'like', '%'.$search.'%'))
            ->when($translation !== '', fn ($q) => $q->where('translation', 'like', '%'.$translation.'%'));

        // Both bases in one sortable table: `lower()` matches the expressions' NOCASE form.
        // Expressions have no part of speech, so they take "expression" in its place.
        $entries = DB::query()->fromSub(
            $filter($words, 'lemma')->selectRaw("'word' as kind, id, lower(lemma) as sort_key, part_of_speech")
                ->when($expressions, fn ($q) => $q->unionAll($filter($expressions, 'form')->selectRaw("'expression' as kind, id, lower(form) as sort_key, 'expression' as part_of_speech"))),
            'entries'
        )->when($partOfSpeech !== '', fn ($q) => $q->where('part_of_speech', $partOfSpeech));

        $selected = $this->selected($request, $entries);
        $page = $entries->orderBy('sort_key')->orderBy('kind')->orderBy('id')
            ->paginate(self::PER_PAGE, page: $selected['page'])
            ->appends($request->except('selected'));

        $wordRows = BaseWord::with(['language', 'cards:id,term'])->findMany($page->where('kind', 'word')->pluck('id'))->keyBy('id');
        $expressionRows = FixedExpression::with('cards:id,term')->findMany($page->where('kind', 'expression')->pluck('id'))->keyBy('id');
        $synonyms = EntrySynonym::of($wordRows->concat($expressionRows));
        $page->setCollection($page->getCollection()->map(fn ($row) => [
            'key' => $row->kind.':'.$row->id,
            'entry' => $row->kind === 'word' ? $wordRows[$row->id] : $expressionRows[$row->id],
            'partOfSpeech' => $row->part_of_speech,
            'synonyms' => $synonyms->get($row->kind.':'.$row->id, collect()),
        ]));

        // The filter offers only the parts of speech these entries actually have.
        $inLanguage = fn ($q) => (clone $q)->when($languageId, fn ($q) => $q->where('language_id', $languageId));
        $partsOfSpeech = $inLanguage($words)->distinct()->orderBy('part_of_speech')->pluck('part_of_speech')
            ->when($expressions && $inLanguage($expressions)->exists(), fn ($options) => $options->push('expression'));

        $data += [
            'search' => $search,
            'translation' => $translation,
            'partOfSpeech' => $partOfSpeech,
            'partsOfSpeech' => $partsOfSpeech,
            'entries' => $page,
            'selectedKey' => $selected['key'],
        ];

        if ($request->ajax()) {
            return response()->json([
                'rows' => view('base._rows', $data)->render(),
                'pagination' => $page->links()->toHtml(),
            ]);
        }

        return view($view, $data);
    }

    /**
     * A row key ("word:12", "expression:7") split into its kind and id.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parse(string $key): array
    {
        return array_pad(explode(':', $key, 2), 2, null);
    }

    /**
     * The `selected` entry, if it is in this (filtered) list: its key, and the page it falls
     * on — counted from how many entries sort before it — unless a page was asked for.
     *
     * @return array{key: ?string, page: ?int}
     */
    private function selected(Request $request, Builder $entries): array
    {
        [$kind, $id] = $this->parse((string) $request->query('selected', ''));
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
}
