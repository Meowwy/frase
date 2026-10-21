<?php

namespace App\Models\Concerns;

use App\Models\BaseWord;
use App\Models\EntrySynonym;
use App\Models\FixedExpression;
use Illuminate\Support\Collection;

/**
 * Synonym links on a base word or fixed expression, stored as two mirrored entry_synonyms
 * rows per link. See docs/cards.md "Synonym links".
 */
trait HasSynonyms
{
    /** "word" or "expression": the kind an entry goes by in a link and on /base. */
    public function entryKind(): string
    {
        return $this instanceof BaseWord ? 'word' : 'expression';
    }

    /** The "kind:id" key /base and a link know an entry by ("word:12"). */
    public function entryKey(): string
    {
        return $this->entryKind().':'.$this->id;
    }

    /**
     * The entries linked to this one; a deleted one is left out until it comes back.
     *
     * @return Collection<int, BaseWord|FixedExpression>
     */
    public function synonyms(): Collection
    {
        return EntrySynonym::of(collect([$this]))->get($this->entryKey(), collect());
    }

    /** Link this entry and $other both ways; linking them again changes nothing. */
    public function linkSynonym(BaseWord|FixedExpression $other): void
    {
        foreach ($this->synonymRows($other) as $row) {
            EntrySynonym::firstOrCreate($row, ['user_id' => $this->user_id, 'language_id' => $this->language_id]);
        }
    }

    public function unlinkSynonym(BaseWord|FixedExpression $other): void
    {
        foreach ($this->synonymRows($other) as $row) {
            EntrySynonym::where($row)->delete();
        }
    }

    /** The two mirrored rows a link between this entry and $other is stored as. */
    private function synonymRows(BaseWord|FixedExpression $other): array
    {
        return [
            ['kind' => $this->entryKind(), 'entry_id' => $this->id, 'other_kind' => $other->entryKind(), 'other_id' => $other->id],
            ['kind' => $other->entryKind(), 'entry_id' => $other->id, 'other_kind' => $this->entryKind(), 'other_id' => $this->id],
        ];
    }
}
