<?php

namespace App\Models;

use App\Support\LanguageGuideline;
use App\Support\Lexicon;
use App\Support\WordDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Card extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tag()
    {
        return $this->hasMany(Tag::class);
    }

    public function theme()
    {
        return $this->belongsTo(Theme::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * The vocabulary-base entries for this card's Term — which of the Term's words the
     * learner is actually collecting. See docs/cards.md "The vocabulary base".
     */
    public function baseWords(): BelongsToMany
    {
        return $this->belongsToMany(BaseWord::class, 'card_base_word')->withPivot('base_word_sense_id');
    }

    /**
     * The expression-base entries for the fixed expressions in this card's Term.
     */
    public function fixedExpressions(): BelongsToMany
    {
        return $this->belongsToMany(FixedExpression::class, 'card_fixed_expression');
    }

    /**
     * Stamp a correct recall of the whole Term on everything it links: producing the Term
     * is producing its base words and its fixed expressions. Expects both relations to be
     * loaded, since callers stamp a batch of cards.
     */
    public function stampRecall(): void
    {
        BaseWord::whereKey($this->baseWords->modelKeys())->update(['last_recalled_at' => now()]);
        FixedExpression::whereKey($this->fixedExpressions->modelKeys())->update(['last_recalled_at' => now()]);
    }

    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
    }

    protected static function booted(): void
    {
        // Kept in step with the Term, so the duplicate check is one indexed lookup.
        static::saving(fn (Card $card) => $card->term_key = self::termKey((string) $card->term));
    }

    /**
     * A Term reduced for comparison: lower case, punctuation dropped, spacing collapsed —
     * "Vill de titta?" and "vill de  titta" are the same Term.
     */
    public static function termKey(string $term): string
    {
        $key = preg_replace('/[\p{P}\p{S}]+/u', ' ', mb_strtolower($term));

        return trim(preg_replace('/\s+/u', ' ', $key));
    }

    /**
     * Does the learner already have a card for this Term? A match on termKey() within one
     * language, against the Term alone.
     *
     * Owning a base WORD of a Term is a different question and never blocks capturing the
     * card — staging's already-present notice is what tells them about the base.
     */
    public function scopeMatchingTerm($query, string $term)
    {
        return $query->where('term_key', self::termKey($term));
    }

    public function synonyms()
    {
        return $this->hasMany(Synonym::class);
    }

    /**
     * Cards the user has manually linked to this one. Stored in the `synonyms`
     * pivot as two mirrored rows per link, so the relation reads symmetrically.
     */
    public function linkedCards()
    {
        return $this->belongsToMany(Card::class, 'synonyms', 'card_id', 'synonym_card_id')
            ->withTimestamps();
    }

    public function relatedTerms()
    {
        return $this->hasMany(RelatedTerm::class);
    }

    /**
     * What this card teaches: its Term, always. There is no focus
     * word and nothing inside a Term is privileged — every generated field is about the
     * whole Term, and so is every learning mode's answer. See docs/cards.md.
     */
    public function target(): string
    {
        return $this->term;
    }

    /**
     * CALL 2: write the card's fields and, for the proposal's `$newWords`, their details,
     * without persisting anything — Proposal::approve() persists it once the transaction
     * opens. Returns the translation (unless native) and `words`: the details of each new word,
     * keyed "lemma|part_of_speech" (see WordDetails).
     *
     * Returns null on a refusal, a failed request or an unparseable answer, so the caller
     * can show a plain retry message rather than a 500.
     */
    public static function generateContent(User $user, Language $language, string $term, ?string $context, array $newWords = []): ?array
    {
        $nativeLanguage = self::nativeLanguageFor($user, $language);

        // A native-language card has no translation, so with no new words it has nothing to generate.
        if (is_null($nativeLanguage) && ! $newWords) {
            return [];
        }

        // The lexicon answers what it can for free; CALL 2 is only asked for the rest.
        $lexicon = Lexicon::lookup($language->code, $newWords);
        $asked = WordDetails::fieldsToAsk(LanguageGuideline::for($language->code), $newWords, $lexicon);

        $content = AI::generateCard($term, $language->name, $language->code, $nativeLanguage, $context, $asked);

        if (is_null($content) || ! $newWords) {
            return $content;
        }

        $words = WordDetails::forNewWords($language, $newWords, $content['base_words'] ?? [], $lexicon);

        return is_null($words) ? null : ['words' => $words] + $content;
    }

    /**
     * Write one generated card. No embedding is generated (switched off in v1.0, see
     * docs/search-and-linking.md).
     */
    public static function persist(User $user, Language $language, string $term, ?string $context, array $content): self
    {
        $card = $user->cards()->create(
            self::contentColumns($term, $context, $content) + [
                'language_id' => $language->id,
                // Cards no longer have a definition; the column is NOT NULL and kept for now.
                'definition' => '',
                'level' => 1,
                'next_study_at' => now(),
            ]
        );

        logger('Card has been created for '.$term);

        return $card;
    }

    /**
     * Map one generated card onto its columns: only what the AI writes; persist() adds the
     * schedule.
     */
    private static function contentColumns(string $term, ?string $context, array $content): array
    {
        return [
            'term' => $term,
            // '' for a native-language card, whose schema has no translation at all.
            'translation' => $content['translation'] ?? '',
            'context' => $context,
        ];
    }

    /**
     * Which native language to generate against, or null to generate MONOLINGUALLY (no
     * translation field at all): either because the card's language is the user's own
     * native language — the native-vocabulary opt-in, see docs/multi-language.md — or
     * because they have no native language configured, in which case there is nothing to
     * translate into anyway.
     */
    public static function nativeLanguageFor(User $user, Language $language): ?string
    {
        if ($user->native_language_id && (int) $language->id === (int) $user->native_language_id) {
            return null;
        }

        $name = trim((string) (optional($user->nativeLanguage)->name ?? $user->native_language));

        return $name === '' ? null : $name;
    }
}
