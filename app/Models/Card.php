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

    /**
     * Does the learner already have a card for this term? Matches case-insensitively
     * against **both** `phrase` and `word`.
     *
     * `word` is in there because it holds the form the learner actually met — and so
     * typically the form they type into the capture box. A card built around "vetting
     * candidates" with `word = "vetting"` IS their card for "vetting", and offering to
     * make a second one would split one term's review history in two. `word` is null on
     * word and expression cards, where `phrase` is the target and carries the match.
     */
    public function scopeMatchingTerm($query, string $term)
    {
        $needle = mb_strtolower(trim($term));

        return $query->where(fn ($q) => $q
            ->whereRaw('LOWER(phrase) = ?', [$needle])
            ->orWhereRaw('LOWER(word) = ?', [$needle]));
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
     * AI-assisted capture, step 1 of 2. Call 1 (`AI::analyzeTerm`) decides which of the
     * three card shapes the term needs and fixes the exact phrase the card is built
     * around; createFromAnalysis() then writes that shape's fields. Splitting the two
     * calls is what lets each shape have its own strict output schema, and it means
     * `phrase` is an INPUT to the content call, so the term can no longer drift into a
     * different one.
     *
     * The two steps are separately callable so the capture flow can run its duplicate
     * check against the CANONICAL phrase as well as the typed one. A learner who types
     * `vettting` or `vetting` for a `vet` card they already have looks like a new term
     * until call 1 corrects the spelling and the inflection — only this step knows they
     * collide. The result is handed straight on to createFromAnalysis() (or kept for
     * regenerate()), so catching a duplicate here costs nothing beyond the call that was
     * going to happen anyway.
     *
     * Returns null on a refusal, a failed request or an answer with no usable phrase, so
     * the caller can show a plain retry message rather than a 500 — see docs/overview.md's
     * AI-failure convention.
     */
    public static function analyze(Language $language, string $term, ?string $context = null): ?array
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

        return [
            'phrase' => $phrase,
            'kind' => $analysis['card_kind'] ?? 'word',
            'focus_word' => self::cleanOptional($analysis['word'] ?? null),
            'submitted_form' => self::cleanOptional($analysis['submitted_form'] ?? null),
        ];
    }

    /**
     * AI-assisted capture, step 2 of 2: call 2 + the write, for an analysis that has
     * already come back from analyze().
     */
    public static function createFromAnalysis(User $user, Language $language, array $analysis, ?string $context = null): ?self
    {
        $generated = self::generateContent($user, $language, $analysis, $context);

        if (is_null($generated)) {
            return null;
        }

        return self::persist(
            user: $user,
            language: $language,
            phrase: $generated['phrase'],
            word: $generated['word'],
            termType: $generated['term_type'],
            context: $context,
            content: $generated['content'],
        );
    }

    /**
     * Re-run the capture pipeline for a term the learner already has and overwrite this
     * card's generated content in place — the "Regenerate" option on the duplicate-term
     * dialog. Only the AI-written columns are rewritten, so everything the learner has
     * built up around the card (SRS level and schedule, the note, its wordbox, every
     * manual link) survives untouched.
     *
     * `$analysis` is call 1's answer from the capture that hit the duplicate, handed back
     * so "Regenerate" doesn't pay for the same call twice. It is only trusted when it
     * really describes THIS card's term; otherwise the card's own `phrase` goes back
     * through call 1, which is safe because it is already the canonical form call 1
     * settled on, so the regenerated card is built around the same thing. Either way
     * regeneration never runs the duplicate check itself, so it cannot bounce back into
     * the dialog it was launched from.
     *
     * Returns false if either call fails, leaving the card exactly as it was.
     */
    public function regenerate(?string $context = null, ?array $analysis = null): bool
    {
        if (is_null($analysis) || mb_strtolower($analysis['phrase']) !== mb_strtolower($this->phrase)) {
            $analysis = self::analyze($this->language, $this->phrase, $context);
        }

        if (is_null($analysis)) {
            return false;
        }

        $generated = self::generateContent($this->user, $this->language, $analysis, $context);

        if (is_null($generated)) {
            return false;
        }

        $this->update(self::contentColumns(
            phrase: $generated['phrase'],
            word: $generated['word'],
            termType: $generated['term_type'],
            context: $context,
            content: $generated['content'],
        ));

        // The content it was built from has changed, so the old embedding no longer
        // describes this card — see docs/search-and-linking.md.
        GenerateEmbeddingJob::dispatch($this);

        logger('Card '.$this->id.' has been regenerated for '.$this->phrase);

        return true;
    }

    /**
     * Call 2, without writing anything: takes an analyze() result and returns the
     * resolved phrase, focus word and term type alongside the raw content response, so
     * the same step can either create a new card or rewrite an existing one.
     */
    private static function generateContent(User $user, Language $language, array $analysis, ?string $context): ?array
    {
        $phrase = $analysis['phrase'];
        $kind = $analysis['kind'];
        $focusWord = $analysis['focus_word'];
        $submittedForm = $analysis['submitted_form'];

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

        return [
            'phrase' => $phrase,
            // Only a phrase card has a focus word inside it; a word card IS the word.
            'word' => $kind === 'phrase'
                ? self::resolveFocusWord($content['word'] ?? $focusWord, $phrase)
                : null,
            'term_type' => $kind === 'expression' ? self::TYPE_EXPRESSION : self::TYPE_LEXICAL,
            'content' => $content,
        ];
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
        $card = $user->cards()->create(
            self::contentColumns($phrase, $word, $termType, $context, $content) + [
                'language_id' => $language->id,
                'level' => 1,
                'next_study_at' => now(),
            ]
        );

        GenerateEmbeddingJob::dispatch($card);

        logger('Card has been created for '.$phrase);

        return $card;
    }

    /**
     * Map one generated card onto its columns. Deliberately covers ONLY what the AI
     * writes — no `level`, `next_study_at` or `note` — so regenerate() can hand the
     * result straight to update() without touching the learner's own progress.
     */
    private static function contentColumns(string $phrase, ?string $word, string $termType, ?string $context, array $content): array
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

        return [
            'phrase' => $phrase,
            'word' => $word,
            'term_type' => $termType,
            // '' for a native-language card, whose schema has no translation at all.
            'translation' => $content['translation'] ?? '',
            // These columns are NOT NULL, so coalesce to an empty string.
            'example_sentence' => $content['sentence'] ?? '',
            'definition' => $content['definition'] ?? '',
            'example_1' => $examples[0] ?? null,
            'example_2' => $examples[1] ?? null,
            'example_3' => $examples[2] ?? null,
            'context' => $context,
        ];
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
