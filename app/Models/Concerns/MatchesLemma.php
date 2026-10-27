<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Looks a lemma up regardless of its capitalisation, for the models that hold one.
 *
 * The stored spelling is kept as the AI gave it (a German noun is "Haus"), so "Hus" and
 * "hus" are matched here rather than normalised away. SQLite can't fold case beyond ASCII,
 * so the lookup tries the spellings a lemma can come in — as given, lower-cased, and
 * first-letter capitalised — and the caller compares the rest with lemmaKey().
 */
trait MatchesLemma
{
    /**
     * @param  string|array<int, string>  $lemmas
     */
    public function scopeWhereLemma(Builder $query, string|array $lemmas): void
    {
        $query->whereIn('lemma', self::lemmaSpellings((array) $lemmas));
    }

    /**
     * @param  array<int, string>  $lemmas
     * @return array<int, string>
     */
    public static function lemmaSpellings(array $lemmas): array
    {
        $spellings = [];

        foreach ($lemmas as $lemma) {
            $lower = mb_strtolower($lemma);

            array_push($spellings, $lemma, $lower, mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1));
        }

        return array_values(array_unique($spellings));
    }

    /** What two spellings of one lemma share. */
    public static function lemmaKey(string $lemma): string
    {
        return mb_strtolower($lemma);
    }
}
