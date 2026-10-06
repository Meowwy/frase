<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\Language;
use App\Models\Proposal;
use App\Support\LanguageGuideline;
use App\Support\Lexicon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Resolves CALL 1 against a freshly captured proposal: language detection, the Term with
 * its typos fixed, the candidate base words and fixed expressions and, for an ambiguous lone
 * word, its senses — or, for a Term that is a real word in more than one of the learner's
 * languages, only the languages to pick from.
 *
 * Capture writes the proposal row and returns immediately, so this runs on the queue and
 * the staging list resolves its skeleton row once the status flips to `completed` (the
 * same shape as GenerateGapFillJob — see docs/gap-fill.md).
 */
class AnalyzeProposalJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Proposal $proposal
    ) {}

    public function handle(): void
    {
        try {
            $this->proposal->update(['status' => Proposal::STATUS_PROCESSING]);

            $user = $this->proposal->user;

            // A language already set means the learner corrected a wrong detection in
            // staging, so this re-run is pinned to it: offering the model the whole set
            // again would just let it drift back to its original guess.
            $languages = $user->languages()
                ->when($this->proposal->language_id, fn ($q) => $q->whereKey($this->proposal->language_id))
                ->orderBy('name')
                ->get();

            if ($languages->isEmpty()) {
                $this->proposal->update(['status' => Proposal::STATUS_FAILED]);

                return;
            }

            $analysis = AI::analyzeTerm(
                $this->proposal->raw_input,
                $languages->map(fn (Language $l) => ['code' => $l->code, 'name' => $l->name])->all(),
                optional($user->nativeLanguage)->name ?? $user->native_language,
                $this->proposal->context,
            );

            $term = trim((string) ($analysis['term'] ?? ''));
            $language = $analysis ? $languages->firstWhere('name', $analysis['language'] ?? null) : null;

            if ($term === '' || ! $language) {
                Log::error('analyzeTerm returned nothing usable for proposal '.$this->proposal->id);
                $this->proposal->update(['status' => Proposal::STATUS_FAILED]);

                return;
            }

            // A Term that is at home in several of the learner's languages asks which one
            // first. Nothing else is stored: words, expressions and senses all depend on the
            // language, and picking one re-runs this job pinned to it.
            if ($options = $this->languageOptions((array) ($analysis['other_languages'] ?? []), $language, $languages)) {
                $this->proposal->update([
                    'term' => $term,
                    'language_options' => $options,
                    'status' => Proposal::STATUS_COMPLETED,
                ]);

                return;
            }

            $this->proposal->update([
                'language_id' => $language->id,
                'term' => $term,
                'senses' => $this->senses((array) ($analysis['senses'] ?? []), $term),
                'status' => Proposal::STATUS_COMPLETED,
            ]);

            $this->storeCandidates(
                (array) ($analysis['base_words'] ?? []),
                $language,
                $user->levelForLanguage($language),
            );
            $this->storeFixedExpressions((array) ($analysis['fixed_expressions'] ?? []));
        } catch (\Throwable $e) {
            Log::error('Proposal analysis failed for proposal '.$this->proposal->id.': '.$e->getMessage());
            $this->proposal->update(['status' => Proposal::STATUS_FAILED]);
        }
    }

    /**
     * The language picker's options, as language ids with CALL 1's own pick first — or null
     * when the Term belongs to just the one language. Only an unpinned run is offered more
     * than one language, so a re-run after the learner picked never asks again.
     */
    private function languageOptions(array $others, Language $language, Collection $languages): ?array
    {
        $ids = $languages->whereIn('name', $others)->where('id', '!=', $language->id)->modelKeys();

        return $ids ? [$language->id, ...$ids] : null;
    }

    /**
     * The senses for the sense picker, kept only where one can apply: a single-word Term
     * captured without a Context, with at least two senses to choose between. The prompt
     * asks for exactly that, but a stray answer would block approval, so it is enforced
     * here too.
     */
    private function senses(array $senses, string $term): ?array
    {
        $senses = array_values(array_filter($senses, fn ($sense) => trim((string) ($sense['gloss'] ?? '')) !== ''));

        if (! is_null($this->proposal->context) || str_contains($term, ' ') || count($senses) < 2) {
            return null;
        }

        return array_map(fn ($sense) => [
            'part_of_speech' => (string) ($sense['part_of_speech'] ?? ''),
            'gloss' => trim((string) $sense['gloss']),
            'translation' => trim((string) ($sense['translation'] ?? '')),
        ], array_slice($senses, 0, 4));
    }

    /**
     * Write the chip tray, applying the proficiency filter on the way in.
     *
     * The already-present and known groups are deliberately NOT applied here: they are
     * computed live at render time (Proposal::groupOf) so a word the learner acquires or
     * strikes between capture and approval is still recognised, and so the chip can say so
     * rather than vanishing. Every candidate stays on the proposal, which is what lets
     * un-knowing a word bring its chip back.
     */
    private function storeCandidates(array $candidates, Language $language, ?string $level): void
    {
        $guideline = LanguageGuideline::for($language->code);

        $candidates = array_filter($candidates, fn ($candidate) => trim((string) ($candidate['lemma'] ?? '')) !== ''
            && in_array($candidate['part_of_speech'] ?? '', LanguageGuideline::PARTS_OF_SPEECH, true));

        $lexicon = Lexicon::lookup($language->code, array_map(fn ($candidate) => [
            'lemma' => trim((string) $candidate['lemma']),
            'part_of_speech' => $candidate['part_of_speech'],
        ], $candidates));

        $clean = [];

        foreach ($candidates as $candidate) {
            $lemma = trim((string) $candidate['lemma']);
            $partOfSpeech = $candidate['part_of_speech'];
            $key = mb_strtolower($lemma).'|'.$partOfSpeech;

            // One chip per lemma + part of speech: the card/base-word link is unique per
            // pair, so a Term that repeats a word must not propose it twice.
            $clean[$key] ??= [
                'lemma' => $lemma,
                'part_of_speech' => $partOfSpeech,
                'grammar_attributes' => $this->grammarAttributes($guideline, $partOfSpeech, $candidate, $lexicon[$key] ?? []),
                'dictionary_form' => $this->dictionaryForm($guideline, $partOfSpeech, $candidate, $lexicon[$key] ?? []),
                'translation' => trim((string) ($candidate['translation'] ?? '')),
            ];
        }

        foreach ($this->applyProficiencyFilter($clean, $level) as $candidate) {
            $this->proposal->baseWords()->create($candidate);
        }
    }

    /**
     * Write the fixed-expression chips: at most 3, one per form. Whether each is already in
     * the expression base is decided live at render, like the words' groups.
     */
    private function storeFixedExpressions(array $expressions): void
    {
        $clean = [];

        foreach ($expressions as $expression) {
            $form = trim((string) ($expression['form'] ?? ''));

            if ($form !== '') {
                $clean[mb_strtolower($form)] ??= [
                    'form' => $form,
                    'translation' => trim((string) ($expression['translation'] ?? '')),
                ];
            }
        }

        foreach (array_slice($clean, 0, 3) as $expression) {
            $this->proposal->fixedExpressions()->create($expression);
        }
    }

    /**
     * The proficiency filter: from B1 up, prepositions, pronouns and other very basic
     * function words aren't worth a vocabulary entry of their own. It always applies, even
     * when it leaves nothing: a card may link no base words at all ("in spite of" at C1).
     */
    private function applyProficiencyFilter(array $candidates, ?string $level): array
    {
        if (! in_array($level, ['B1', 'B2', 'C1', 'C2'], true)) {
            return $candidates;
        }

        return array_filter(
            $candidates,
            fn ($candidate) => ! in_array($candidate['part_of_speech'], LanguageGuideline::FUNCTION_WORD_PARTS, true)
        );
    }

    /**
     * The dictionary-style form, kept only when this language writes this part of speech
     * that way. CALL 1's schema carries the property across the learner's languages, so a
     * Swedish verb's "komm|a -er" can come back on an English verb — dropping it here is
     * what keeps a language without the convention showing plain lemmas.
     */
    private function dictionaryForm(?LanguageGuideline $guideline, string $partOfSpeech, array $candidate, array $lexicon): ?string
    {
        if (! $guideline?->wantsDictionaryForm($partOfSpeech)) {
            return null;
        }

        return $this->preferLexicon(trim((string) ($candidate['dictionary_form'] ?? '')), $lexicon['dictionary_form'] ?? []) ?: null;
    }

    /**
     * The lexicon's value wherever it knows the word; CALL 1's answer only as a fallback
     * for a word it doesn't, or as the tie-break between homographs ("ett plan" the plane,
     * "en plan" the plan) — the model knows which sense the Term means, but may only pick
     * one of the values the dictionary allows. See docs/cards.md "The lexicon".
     */
    private function preferLexicon(string $answer, array $options): string
    {
        if ($options === [] || in_array($answer, $options, true)) {
            return $answer;
        }

        return $options[0];
    }

    /**
     * Keep only the attributes this language's guideline actually defines for this part of
     * speech. CALL 1's schema carries the union across the learner's languages (it decides
     * the language in the same answer), so a Swedish `gender` can come back on an English
     * noun — discarding it here is what keeps the stored attributes truthful.
     */
    private function grammarAttributes(?LanguageGuideline $guideline, string $partOfSpeech, array $candidate, array $lexicon): ?array
    {
        if (! $guideline) {
            return null;
        }

        $attributes = [];

        foreach ($guideline->attributesFor($partOfSpeech) as $name => $definition) {
            $value = $this->preferLexicon((string) ($candidate[$name] ?? ''), $lexicon[$name] ?? []);

            if (in_array($value, $definition['values'], true)) {
                $attributes[$name] = $value;
            }
        }

        return $attributes ?: null;
    }
}
