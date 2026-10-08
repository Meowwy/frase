<?php

namespace App\Support;

/**
 * Reads one language's guideline file from `resources/language-guidelines/`.
 *
 * A guideline declares which parts of speech a language uses, which of them carry extra
 * grammatical attributes (with their valid values and how to display them), and prose
 * that CALL 1's prompt quotes so the model applies that language's own grammar. Adding a
 * language means adding its file — nothing here and nothing in the schema changes. See
 * docs/ai-integration.md "Language guidelines".
 *
 * A language with no file still gets `part_of_speech` tagged on every base word (the
 * model does that from general knowledge); it just proposes no attributes.
 */
class LanguageGuideline
{
    /**
     * Every part of speech the schema accepts, shared by all languages. A guideline may
     * narrow this for its own language but never extend it.
     */
    public const PARTS_OF_SPEECH = [
        'noun', 'verb', 'adjective', 'adverb', 'pronoun',
        'preposition', 'conjunction', 'determiner', 'numeral', 'interjection',
    ];

    /**
     * Parts of speech too basic to be worth a base word from B1 up — the proficiency
     * filter's list. See docs/cards.md "Two filters".
     */
    public const FUNCTION_WORD_PARTS = ['preposition', 'pronoun', 'conjunction', 'determiner'];

    private function __construct(private readonly array $data) {}

    /**
     * The guideline for a language code, or null when that language has no file.
     */
    public static function for(?string $languageCode): ?self
    {
        if (blank($languageCode)) {
            return null;
        }

        $path = resource_path('language-guidelines/'.strtolower($languageCode).'.php');

        return is_file($path) ? new self(require $path) : null;
    }

    /**
     * @return array<int, string>
     */
    public function partsOfSpeech(): array
    {
        return $this->data['parts_of_speech'] ?? self::PARTS_OF_SPEECH;
    }

    /**
     * The attribute definitions for one part of speech, keyed by attribute name.
     *
     * @return array<string, array{values: array<int, string>, display?: array<string, string>, prompt_note?: string}>
     */
    public function attributesFor(string $partOfSpeech): array
    {
        return $this->data['attributes'][$partOfSpeech] ?? [];
    }

    /**
     * Every attribute this language defines anywhere, keyed by name — used to build
     * CALL 1's schema, which cannot know in advance which part of speech a word will get.
     *
     * One name shared by two parts of speech is therefore one schema property, so its
     * `values` are UNIONED rather than first-one-wins: keeping only the first part of
     * speech's value set would make the other's legitimate answers fail validation in
     * AnalyzeProposalJob and be stored as null. Only the values are merged — `display` is
     * read per part of speech via attributesFor(), so it never needs flattening.
     *
     * @return array<string, array{values: array<int, string>, display?: array<string, string>, prompt_note?: string}>
     */
    public function allAttributes(): array
    {
        $merged = [];

        foreach ($this->data['attributes'] ?? [] as $attributes) {
            foreach ($attributes as $name => $definition) {
                $definition['values'] = array_values(array_unique(array_merge(
                    $merged[$name]['values'] ?? [],
                    $definition['values'],
                )));

                $merged[$name] = $definition;
            }
        }

        return $merged;
    }

    /**
     * Whether this language writes the given part of speech in dictionary style, i.e.
     * whether CALL 1 should be asked for a `dictionary_form` for such a word.
     *
     * This is separate from an attribute because it is not computable: "ett hus" follows
     * from {gender, lemma} by a fixed mapping, but a Swedish verb's "komm|a -er" depends
     * on where that individual verb's stem ends and which present ending it takes. The whole
     * string is stored — from the lexicon where it knows the verb, the model otherwise (see
     * docs/cards.md "The lexicon").
     */
    public function wantsDictionaryForm(string $partOfSpeech): bool
    {
        return in_array($partOfSpeech, $this->data['dictionary_form']['parts_of_speech'] ?? [], true);
    }

    /**
     * Whether this language asks for a dictionary form at all — CALL 1's schema needs to
     * know before it knows which part of speech any word will get.
     */
    public function usesDictionaryForms(): bool
    {
        return filled($this->data['dictionary_form']['parts_of_speech'] ?? []);
    }

    /**
     * This language's prose notes for CALL 1's prompt: the language-wide one, one per
     * attribute it defines, and its dictionary-style rule when it has one.
     */
    public function promptNote(): string
    {
        $notes = [$this->data['prompt_note'] ?? ''];

        foreach ($this->allAttributes() as $attribute) {
            $notes[] = $attribute['prompt_note'] ?? '';
        }

        $notes[] = $this->data['dictionary_form']['prompt_note'] ?? '';

        return trim(implode(' ', array_filter($notes)));
    }

    /**
     * The lemma as the learner is expected to learn it: a Swedish neuter noun's display
     * form is "ett hus", not bare "hus", and a Swedish verb's is "komm|a -er", not bare
     * "komma". Everywhere a base word is shown — staging chips, the vocabulary base,
     * Frammenti — shows this, never the bare lemma.
     *
     * A stored dictionary form stands in for the lemma and the article prefixes still
     * apply around it, so a language that wanted both would get both.
     */
    public function displayForm(string $lemma, string $partOfSpeech, ?array $grammarAttributes, ?string $dictionaryForm = null): string
    {
        $prefixes = [];

        foreach ($this->attributesFor($partOfSpeech) as $name => $definition) {
            $value = $grammarAttributes[$name] ?? null;
            $display = $definition['display'][$value] ?? null;

            if (filled($display)) {
                $prefixes[] = $display;
            }
        }

        return trim(implode(' ', [...$prefixes, filled($dictionaryForm) ? $dictionaryForm : $lemma]));
    }
}
