<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Support\Facades\Log;

/**
 * What a new base word is stored with besides its lemma and part of speech: its translation,
 * dictionary form and grammar attributes. CALL 2 answers them at approval, and the lexicon's
 * deterministic values win wherever it knows the word. See docs/cards.md "The lexicon".
 */
class WordDetails
{
    /**
     * What CALL 2 still has to say about each new word: the dictionary form and grammar
     * attributes its language defines for the word's part of speech, minus every one the
     * lexicon answers with a single value. A noun the lexicon lists once is asked for nothing
     * but a translation; a homograph, a word it lacks, or a language with no lexicon is still
     * asked. Each word comes back with its list as `ask`.
     *
     * @param  array<int, array{lemma: string, part_of_speech: string}>  $newWords
     * @param  array<string, array<string, array<int, string>>>  $lexicon  see Lexicon::lookup()
     * @return array<int, array{lemma: string, part_of_speech: string, ask: array<int, string>}>
     */
    public static function fieldsToAsk(?LanguageGuideline $guideline, array $newWords, array $lexicon): array
    {
        return array_map(function (array $word) use ($guideline, $lexicon) {
            $known = $lexicon[self::lexiconKey($word['lemma'], $word['part_of_speech'])] ?? [];
            $fields = array_keys($guideline?->attributesFor($word['part_of_speech']) ?? []);

            if ($guideline?->wantsDictionaryForm($word['part_of_speech'])) {
                $fields[] = 'dictionary_form';
            }

            return $word + ['ask' => array_values(array_filter($fields, fn (string $field) => count($known[$field] ?? []) !== 1))];
        }, $newWords);
    }

    /**
     * The details of every new word, keyed "lemma|part_of_speech", read off CALL 2's answer
     * and the lexicon. Null when it left a word out or gave it no translation, so the caller
     * can fail the approval rather than save an entry that has nothing to show.
     *
     * @param  array<int, array{lemma: string, part_of_speech: string}>  $newWords
     * @param  array<int, array<string, string>>  $answered  CALL 2's `base_words`
     * @param  array<string, array<string, array<int, string>>>  $lexicon  see Lexicon::lookup()
     * @return array<string, array{translation: string, grammar_attributes: ?array, dictionary_form: ?string}>|null
     */
    public static function forNewWords(Language $language, array $newWords, array $answered, array $lexicon): ?array
    {
        $guideline = LanguageGuideline::for($language->code);
        $answers = collect($answered)->keyBy(fn (array $answer) => self::lexiconKey($answer['lemma'] ?? '', $answer['part_of_speech'] ?? ''));
        $details = [];

        foreach ($newWords as $word) {
            $key = self::lexiconKey($word['lemma'], $word['part_of_speech']);
            $answer = $answers->get($key, []);
            $translation = trim((string) ($answer['translation'] ?? ''));

            if ($translation === '') {
                Log::error('generateCard described no translation for the word '.$word['lemma'].' ('.$word['part_of_speech'].').');

                return null;
            }

            $details[$word['lemma'].'|'.$word['part_of_speech']] = ['translation' => $translation]
                + self::grammar($guideline, $word['part_of_speech'], $answer, $lexicon[$key] ?? []);
        }

        return $details;
    }

    /**
     * A word's grammar attributes and dictionary form: the answer, kept only where this
     * language's guideline defines them for this part of speech, and the lexicon's value
     * wherever it knows the word. `$lexicon` is its options per field, as Lexicon::lookup()
     * returns them; an empty `$answer` gives the lexicon's first option, so a caller that
     * wants only unambiguous values passes just those fields' options.
     *
     * @return array{grammar_attributes: ?array, dictionary_form: ?string}
     */
    public static function grammar(?LanguageGuideline $guideline, string $partOfSpeech, array $answer, array $lexicon): array
    {
        $attributes = [];

        foreach ($guideline?->attributesFor($partOfSpeech) ?? [] as $name => $definition) {
            $value = self::preferLexicon((string) ($answer[$name] ?? ''), $lexicon[$name] ?? []);

            if (in_array($value, $definition['values'], true)) {
                $attributes[$name] = $value;
            }
        }

        // Kept only when this language writes this part of speech in dictionary style: the
        // answer's schema is just as wide for a language without the convention.
        $dictionaryForm = $guideline?->wantsDictionaryForm($partOfSpeech)
            ? self::preferLexicon(trim((string) ($answer['dictionary_form'] ?? '')), $lexicon['dictionary_form'] ?? [])
            : '';

        return ['grammar_attributes' => $attributes ?: null, 'dictionary_form' => $dictionaryForm ?: null];
    }

    /**
     * The key Lexicon::lookup() files a word under.
     */
    public static function lexiconKey(string $lemma, string $partOfSpeech): string
    {
        return mb_strtolower($lemma).'|'.$partOfSpeech;
    }

    /**
     * The lexicon's value wherever it knows the word; the answer only as a fallback for a
     * word it doesn't, or as the tie-break between homographs ("ett plan" the plane, "en
     * plan" the plan) — the model knows which sense the Term means, but may only pick one
     * of the values the dictionary allows.
     */
    private static function preferLexicon(string $answer, array $options): string
    {
        if ($options === [] || in_array($answer, $options, true)) {
            return $answer;
        }

        return $options[0];
    }
}
