<?php

namespace App\Models;

use App\Jobs\GenerateEmbeddingJob;
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
     * learner is actually collecting. The pivot carries the surface form the Term spells
     * each one in. See docs/cards.md "The vocabulary base".
     */
    public function baseWords(): BelongsToMany
    {
        return $this->belongsToMany(BaseWord::class, 'card_base_word')->withPivot('surface_form');
    }

    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
    }

    /**
     * Does the learner already have a card for this Term? A case-insensitive match within
     * one language, against the Term alone.
     *
     * Owning a base WORD of a Term is a different question and never blocks capturing the
     * card — the learner decides for themselves whether a card of its own is worth it, and
     * staging's already-present notice is what tells them about the base.
     */
    public function scopeMatchingTerm($query, string $term)
    {
        return $query->whereRaw('LOWER(term) = ?', [mb_strtolower(trim($term))]);
    }

    public function wordbox()
    {
        return $this->belongsToMany(Wordbox::class, 'wordbox_card', 'card_id', 'wordbox_id');
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
     * CALL 2: write the card's fields, without persisting anything. Shared by a
     * proposal's approval (Proposal::approve) and regenerate(), so the native-language and
     * level resolution lives in one place.
     *
     * Returns null on a refusal, a failed request or an unparseable answer, so the caller
     * can show a plain retry message rather than a 500.
     */
    public static function generateContent(User $user, Language $language, string $term, ?string $context): ?array
    {
        $nativeLanguage = self::nativeLanguageFor($user, $language);
        // A learner is not "learning" their own language, so no CEFR steering there.
        $level = is_null($nativeLanguage) ? null : $user->levelForLanguage($language);

        return AI::generateCard($term, $language->name, $nativeLanguage, $context, $level);
    }

    /**
     * Rewrite this card's generated content in place. Everything the learner built up
     * around it — SRS level and schedule, the note, its wordbox, its base-word links,
     * every manual link — survives untouched, because contentColumns() covers only what
     * the AI writes.
     *
     * Only CALL 2 runs: the Term is already settled on the card, so there is nothing left
     * for CALL 1 to decide. `$context` falls back to the
     * card's own, so a regeneration keeps the sense it was captured in.
     *
     * Returns false if the call fails, leaving the card exactly as it was.
     */
    public function regenerate(?string $context = null): bool
    {
        $context ??= $this->context;

        $content = self::generateContent($this->user, $this->language, $this->term, $context);

        if (is_null($content)) {
            return false;
        }

        $this->update(self::contentColumns($this->term, $context, $content));

        // The content it was built from has changed, so the old embedding no longer
        // describes this card — see docs/search-and-linking.md.
        GenerateEmbeddingJob::dispatch($this);

        logger('Card '.$this->id.' has been regenerated for '.$this->term);

        return true;
    }

    /**
     * Write one generated card and queue its embedding.
     */
    public static function persist(User $user, Language $language, string $term, ?string $context, array $content): self
    {
        $card = $user->cards()->create(
            self::contentColumns($term, $context, $content) + [
                'language_id' => $language->id,
                'level' => 1,
                'next_study_at' => now(),
            ]
        );

        GenerateEmbeddingJob::dispatch($card);

        logger('Card has been created for '.$term);

        return $card;
    }

    /**
     * Map one generated card onto its columns. Deliberately covers ONLY what the AI
     * writes — no `level`, `next_study_at` or `note` — so regenerate() can hand the
     * result straight to update() without touching the learner's own progress.
     */
    private static function contentColumns(string $term, ?string $context, array $content): array
    {
        return [
            'term' => $term,
            // '' for a native-language card, whose schema has no translation at all.
            'translation' => $content['translation'] ?? '',
            // These columns are NOT NULL, so coalesce to an empty string.
            'example_sentence' => $content['sentence'] ?? '',
            'definition' => $content['definition'] ?? '',
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
