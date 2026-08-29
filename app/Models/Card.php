<?php

namespace App\Models;

use App\Jobs\GenerateEmbeddingJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Card extends Model
{
    use HasFactory;

    /**
     * A naming unit — it has a meaning you can define ("X means ..."). Covers single
     * words, collocations and idioms alike: cabinet, traffic jam, under the weather.
     *
     * An idiom belongs here only if one ordinary word could stand in its place ("under
     * the weather" = ill). A fixed phrase anchored to the speaker — "not my cup of tea" —
     * names nothing and is an expression, however idiomatic it looks.
     */
    public const TYPE_LEXICAL = 'lexical';

    /**
     * A ready-made utterance or utterance frame — it performs a communicative function
     * ("you say X when you want to ..."): I'd rather not, can you hand me the ...,
     * not my cup of tea. A fragment with no finite verb still qualifies.
     */
    public const TYPE_EXPRESSION = 'expression';

    public const TERM_TYPES = [self::TYPE_LEXICAL, self::TYPE_EXPRESSION];

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

    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
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
     * A lexical card built around a single word: it is the only shape that carries usage
     * fragments, which the UI offers as "learn it in a phrase instead" suggestions.
     */
    public function suggestedPhrases(): array
    {
        if ($this->term_type === self::TYPE_EXPRESSION) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', [$this->example_1, $this->example_2, $this->example_3]),
            fn ($example) => $example !== ''
        ));
    }

    /**
     * What this card actually teaches: `word ?? phrase`.
     *
     * `phrase` is what gets DISPLAYED (the whole collocation, so the word is seen in the
     * structure that makes it memorable), but on a focused phrase card it is the `word`
     * that translation, definition and the sentence's brackets are all about — so it is
     * also the answer every learning mode has to accept. A null `word` means the phrase
     * itself is the target, which is the case for word cards and expressions too, so this
     * is safe to call on any card. See docs/cards.md, "What the card is built around".
     */
    public function target(): string
    {
        return filled($this->word) ? $this->word : $this->phrase;
    }

    /**
     * The card's `phrase` as HTML, with the focus word (`word`) wrapped for emphasis so
     * the learner keeps seeing which word they originally wanted inside the phrase they
     * ended up learning.
     *
     * `word` is always stored in the exact form `phrase` spells it, which is what lets
     * this be a plain substring match rather than any kind of stemming.
     *
     * The phrase is escaped FIRST and only the <span> below is trusted, because every
     * caller renders the result with `{!! !!}` — same discipline as the bracketed
     * example sentence in CardController@show. Never drop the e().
     */
    public function phraseHtml(string $emphasis = 'font-bold'): string
    {
        $phrase = e($this->phrase);

        if (blank($this->word)) {
            return $phrase;
        }

        $highlighted = preg_replace(
            '/'.preg_quote(e($this->word), '/').'/iu',
            '<span class="'.$emphasis.'">$0</span>',
            $phrase,
            1
        );

        return $highlighted ?? $phrase;
    }

    /**
     * AI-assisted capture: the full two-call pipeline.
     *
     * Call 1 (`AI::analyzeTerm`) decides which of the three card shapes the term needs
     * and fixes the exact phrase the card is built around; call 2 writes that shape's
     * fields. Splitting them is what lets each shape have its own strict output schema,
     * and it means `phrase` is an INPUT to the content call, so the term can no longer
     * drift into a different one.
     *
     * Returns null if either call fails, so the caller can show a plain retry message
     * rather than a 500 — see docs/overview.md's AI-failure convention.
     */
    public static function createFromTerm(User $user, Language $language, string $term, ?string $context = null): ?self
    {
        $analysis = AI::analyzeTerm($term, $language->name, $context);

        if (is_null($analysis)) {
            return null;
        }

        $phrase = self::cleanOptional($analysis['phrase'] ?? null);

        if (is_null($phrase)) {
            Log::error('analyzeTerm returned no phrase for "'.$term.'"');

            return null;
        }

        $kind = $analysis['card_kind'] ?? 'word';
        $focusWord = self::cleanOptional($analysis['word'] ?? null);
        $submittedForm = self::cleanOptional($analysis['submitted_form'] ?? null);

        $nativeLanguage = self::nativeLanguageFor($user, $language);
        // A learner is not "learning" their own language, so no CEFR steering there.
        $level = is_null($nativeLanguage) ? null : $user->levelForLanguage($language);

        $content = match ($kind) {
            'expression' => AI::generateExpressionCard($phrase, $language->name, $nativeLanguage, $context, $level),
            'phrase' => AI::generatePhraseCard($phrase, $focusWord, $language->name, $nativeLanguage, $context, $level),
            default => AI::generateWordCard($phrase, $submittedForm, $language->name, $nativeLanguage, $context, $level),
        };

        if (is_null($content)) {
            return null;
        }

        return self::persist(
            user: $user,
            language: $language,
            phrase: $phrase,
            // Only a phrase card has a focus word inside it; a word card IS the word.
            word: $kind === 'phrase'
                ? self::resolveFocusWord($content['word'] ?? $focusWord, $phrase)
                : null,
            termType: $kind === 'expression' ? self::TYPE_EXPRESSION : self::TYPE_LEXICAL,
            context: $context,
            content: $content,
        );
    }

    /**
     * Build a card around a phrase whose shape is already known — the "learn it in a
     * phrase instead" path, where the learner clicked one of a word card's suggestions.
     * Skips call 1 entirely, so upgrading a card costs one model call, not two.
     *
     * `$focusWord` is the learner's original single word ("vet"). It is what the new
     * card is BUILT AROUND — translation, definition and the sentence's brackets are all
     * about it, with the phrase supplying the sense and the structure (see docs/cards.md,
     * "What the card is built around"). The word actually stored comes back from the call
     * in the form THIS phrase uses ("vetting" for "vetting candidates"), which is the
     * invariant phraseHtml() depends on.
     */
    public static function createFromPhrase(User $user, Language $language, string $phrase, ?string $focusWord = null, ?string $context = null): ?self
    {
        $nativeLanguage = self::nativeLanguageFor($user, $language);
        $level = is_null($nativeLanguage) ? null : $user->levelForLanguage($language);

        $content = AI::generatePhraseCard($phrase, $focusWord, $language->name, $nativeLanguage, $context, $level);

        if (is_null($content)) {
            return null;
        }

        return self::persist(
            user: $user,
            language: $language,
            phrase: $phrase,
            word: self::resolveFocusWord($content['word'] ?? $focusWord, $phrase),
            termType: self::TYPE_LEXICAL,
            context: $context,
            content: $content,
        );
    }

    /**
     * Write one generated card and queue its embedding. Shared by both capture paths so
     * the AI-response -> column mapping lives in exactly one place.
     */
    private static function persist(User $user, Language $language, string $phrase, ?string $word, string $termType, ?string $context, array $content): self
    {
        // Only a word card has these. Drop blanks so a stray [""] from the model doesn't
        // become an empty example box on the card page.
        //
        // Square brackets are stripped rather than trusted: they belong to
        // `example_sentence` alone, where the /\[.*?\]/ blanking regex uses them (see
        // docs/learning-flow.md), and the model has been observed leaking them into a
        // fragment. These fragments are click targets that become another card's
        // `phrase`, so a bracket here would travel into a sentence that must bracket
        // exactly once — and quietly break that card's Sentences modes.
        $examples = array_values(array_filter(
            array_map(
                fn ($example) => trim(str_replace(['[', ']'], '', (string) $example)),
                (array) ($content['examples'] ?? [])
            ),
            fn ($example) => $example !== ''
        ));

        $card = $user->cards()->create([
            'phrase' => $phrase,
            'word' => $word,
            'term_type' => $termType,
            'language_id' => $language->id,
            'level' => 1,
            // '' for a native-language card, whose schema has no translation at all.
            'translation' => $content['translation'] ?? '',
            // These columns are NOT NULL, so coalesce to an empty string.
            'example_sentence' => $content['sentence'] ?? '',
            'definition' => $content['definition'] ?? '',
            'example_1' => $examples[0] ?? null,
            'example_2' => $examples[1] ?? null,
            'example_3' => $examples[2] ?? null,
            'context' => $context,
            'next_study_at' => now(),
        ]);

        GenerateEmbeddingJob::dispatch($card);

        logger('Card has been created for '.$phrase);

        return $card;
    }

    /**
     * Which native language to generate against, or null to generate MONOLINGUALLY (no
     * translation field at all): either because the save destination is the user's own
     * native language — the native-vocabulary opt-in, see docs/multi-language.md — or
     * because they have no native language configured, in which case there is nothing to
     * translate into anyway.
     */
    private static function nativeLanguageFor(User $user, Language $language): ?string
    {
        if ($user->native_language_id && (int) $language->id === (int) $user->native_language_id) {
            return null;
        }

        return self::cleanOptional(optional($user->nativeLanguage)->name ?? $user->native_language);
    }

    /**
     * Keep a focus word only when it genuinely occurs inside the phrase and is not the
     * whole phrase. phraseHtml() bolds it with a plain substring match, so a word that
     * isn't there would silently highlight nothing — better to store null than to leave
     * a field that quietly lies about the card.
     */
    private static function resolveFocusWord(?string $word, string $phrase): ?string
    {
        $word = self::cleanOptional($word);

        if (is_null($word) || mb_strtolower($word) === mb_strtolower($phrase)) {
            return null;
        }

        return mb_stripos($phrase, $word) === false ? null : $word;
    }

    /**
     * Trim a value the model may have returned as an empty string to mean "not applicable".
     */
    private static function cleanOptional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
