<?php

namespace App\Models;

use App\Support\LanguageGuideline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One candidate in a proposal's strikeable chip tray: an extracted lexical word, already
 * reduced to its lemma and tagged, waiting to be carried onto `base_words` at approval.
 *
 * Nothing here is a vocabulary-base entry yet — striking it means it never becomes one.
 */
class ProposalBaseWord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'grammar_attributes' => 'array',
        'struck' => 'boolean',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The chip's label — a Swedish chip reads "ett hus", not bare "hus", and "komm|a -er",
     * not bare "komma". The proposal's language is the guideline to read, since the
     * candidate has no language of its own.
     */
    public function displayForm(): string
    {
        $guideline = LanguageGuideline::for($this->proposal->language?->code);

        return $guideline
            ? $guideline->displayForm($this->lemma, $this->part_of_speech, $this->grammar_attributes, $this->dictionary_form)
            : $this->lemma;
    }
}
