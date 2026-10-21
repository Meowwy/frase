<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One direction of a synonym link between two entries of one language's bases — base words,
 * fixed expressions or one of each. A link is two mirrored rows (see HasSynonyms), and links
 * are pairs only: A~B and B~C don't make A~C. See docs/cards.md "Synonym links".
 */
class EntrySynonym extends Model
{
    protected $guarded = [];

    /**
     * The synonyms of each of $entries, keyed by entry key ("word:12"), in three queries
     * whatever their number: the links, then the base words and the fixed expressions they
     * point at. Those go through their models' scope, so a deleted synonym drops out while
     * its link stays.
     *
     * @param  Collection<int, BaseWord|FixedExpression>  $entries
     * @return Collection<string, Collection<int, BaseWord|FixedExpression>>
     */
    public static function of(Collection $entries): Collection
    {
        $ids = fn (string $kind) => $entries->filter(fn ($entry) => $entry->entryKind() === $kind)->pluck('id');
        $links = self::where(fn ($q) => $q->where('kind', 'word')->whereIn('entry_id', $ids('word')))
            ->orWhere(fn ($q) => $q->where('kind', 'expression')->whereIn('entry_id', $ids('expression')))
            ->get();

        $found = BaseWord::with('language')->findMany($links->where('other_kind', 'word')->pluck('other_id'))
            ->concat(FixedExpression::findMany($links->where('other_kind', 'expression')->pluck('other_id')))
            ->keyBy(fn ($entry) => $entry->entryKey());

        return $links->toBase()->groupBy(fn ($link) => $link->kind.':'.$link->entry_id)
            ->map(fn ($links) => $links->map(fn ($link) => $found->get($link->other_kind.':'.$link->other_id))->filter()->values());
    }
}
