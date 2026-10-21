<?php

namespace App\Models;

use App\Models\Concerns\HasSynonyms;
use App\Support\LanguageGuideline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the learner's vocabulary base: a lemma, its part of speech, its native
 * translation, whatever grammatical attributes that language and part of speech carry,
 * and when it was last recalled.
 *
 * Part of speech is part of the identity, not a revisable property — *run* the verb and
 * *run* the noun are two rows, because they mean completely different things. Translation
 * and attributes are set once, at proposal time, and never revised: sense lives on cards,
 * and the base carries no sense finer than part of speech.
 *
 * It has no card schedule and no generated content. Its jobs are deduplication and
 * coverage, and it carries its own Frammenti schedule (docs/frammenti.md).
 *
 * A `deleted` entry is hidden by a global scope from every query — the base, Frammenti, card
 * chips, staging — while its card links, synonym links and schedule stay; resolve() brings
 * it back. See docs/cards.md "The vocabulary base" and "Deleting an entry".
 */
class BaseWord extends Model
{
    use HasSynonyms;

    protected $guarded = [];

    protected $casts = [
        'grammar_attributes' => 'array',
        'last_recalled_at' => 'datetime',
        'frammenti_level' => 'integer',
        'deleted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('notDeleted', fn (Builder $query) => $query->where($query->qualifyColumn('deleted'), false));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_base_word');
    }

    /**
     * The lemma as the learner is expected to learn it — "ett hus" for a Swedish neuter
     * noun, "komm|a -er" for a Swedish verb, bare "hus" for a language whose guideline
     * defines no display form. This is what every screen shows: staging chips, the base
     * list, Frammenti.
     */
    public function displayForm(): string
    {
        $guideline = LanguageGuideline::for($this->language?->code);

        return $guideline
            ? $guideline->displayForm($this->lemma, $this->part_of_speech, $this->grammar_attributes, $this->dictionary_form)
            : $this->lemma;
    }

    /**
     * Find or create the base entry for one proposed word. The dedup key is
     * lemma + part of speech, so two proposals for the same word approved in either order
     * both land on one row; `translation`/`grammar_attributes`/`dictionary_form` are only
     * written when the row is new, which is what makes them never-revised. A deleted row is
     * found too and comes back as it was, card links and Frammenti progress included.
     */
    public static function resolve(User $user, Language $language, string $lemma, string $partOfSpeech, ?array $grammarAttributes, string $translation, ?string $dictionaryForm = null): self
    {
        $baseWord = self::withoutGlobalScope('notDeleted')->firstOrCreate(
            [
                'user_id' => $user->id,
                'language_id' => $language->id,
                'lemma' => $lemma,
                'part_of_speech' => $partOfSpeech,
            ],
            [
                'grammar_attributes' => $grammarAttributes,
                'translation' => $translation,
                'dictionary_form' => $dictionaryForm,
            ]
        );

        if ($baseWord->deleted) {
            $baseWord->update(['deleted' => false]);
        }

        return $baseWord;
    }
}
