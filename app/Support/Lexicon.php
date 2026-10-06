<?php

namespace App\Support;

use App\Models\LexiconEntry;
use Illuminate\Support\Collection;

/**
 * Deterministic grammatical facts from a downloaded dictionary (the `lexicon_entries`
 * table), preferred over CALL 1's answer wherever the dictionary knows the word.
 *
 * Only Swedish is imported (from SALDO, `lexicon:import-saldo`). A language with no rows
 * gets no options back, which is all it takes for it to keep the AI-only pipeline. See
 * docs/cards.md "The lexicon".
 */
class Lexicon
{
    /**
     * Shortest suffix / remaining prefix the compound fallback will consider, so "sommarhus"
     * can resolve through "hus" without a two-letter tail matching by accident.
     */
    private const COMPOUND_MIN_SUFFIX = 3;

    private const COMPOUND_MIN_PREFIX = 2;

    /**
     * The values the dictionary allows for each (lemma, part of speech) pair, keyed
     * "lemma|part_of_speech" (lemma lower-cased) and then by field — `gender` and
     * `dictionary_form` — each a list of allowed values. A pair the dictionary doesn't know
     * is simply absent.
     *
     * More than one value means homographs ("ett plan" the plane, "en plan" the plan): the
     * dictionary can't tell the senses apart, so the caller lets CALL 1's sense-aware answer
     * choose among them — but never step outside them.
     *
     * A noun not found whole falls back to its longest known suffix: a Swedish compound
     * takes the gender of its last element ("sommarhus" → "hus" → ett). All of it is one
     * query, since it runs once per analysed proposal.
     *
     * @param  array<int, array{lemma: string, part_of_speech: string}>  $pairs
     * @return array<string, array<string, array<int, string>>>
     */
    public static function lookup(string $languageCode, array $pairs): array
    {
        $lemmas = [];

        foreach ($pairs as $pair) {
            $lemma = mb_strtolower($pair['lemma']);
            $lemmas[] = $lemma;

            if ($pair['part_of_speech'] === 'noun') {
                array_push($lemmas, ...self::compoundTails($lemma));
            }
        }

        if ($lemmas === []) {
            return [];
        }

        $entries = LexiconEntry::where('language_code', $languageCode)
            ->whereIn('lemma', array_unique($lemmas))
            ->whereIn('part_of_speech', array_unique(array_column($pairs, 'part_of_speech')))
            ->get()
            ->groupBy(fn (LexiconEntry $entry) => $entry->lemma.'|'.$entry->part_of_speech);

        $options = [];

        foreach ($pairs as $pair) {
            $lemma = mb_strtolower($pair['lemma']);
            $key = $lemma.'|'.$pair['part_of_speech'];
            $found = $entries->get($key);

            if (! $found && $pair['part_of_speech'] === 'noun') {
                $tail = collect(self::compoundTails($lemma))->first(fn ($tail) => $entries->has($tail.'|noun'));
                $found = $tail ? $entries->get($tail.'|noun') : null;
            }

            if ($found) {
                $options[$key] = self::optionsFrom($found);
            }
        }

        return $options;
    }

    /**
     * A Swedish verb's dictionary form ("komm|a -er") from its infinitive and present
     * tense — the rule resources/language-guidelines/sv.php states for CALL 1, applied
     * here to the dictionary's own present form so it no longer depends on the model.
     */
    public static function swedishDictionaryForm(string $infinitive, string $present): string
    {
        $stem = mb_substr($infinitive, 0, -1);

        // A stem with no vowel left ("ha" → "h") is no stem at all: such a verb is
        // written whole, like "bo -r", rather than as "h|a -ar".
        if (str_ends_with($infinitive, 'a') && preg_match('/[aeiouyåäö]/u', $stem)) {
            foreach (['ar', 'er'] as $ending) {
                if ($present === $stem.$ending) {
                    return "{$stem}|a -{$ending}";
                }
            }

            // Irregular present, written out in full: "var|a är", "kunn|a kan".
            return "{$stem}|a {$present}";
        }

        // The whole infinitive is the stem: "bo -r", "gå -r".
        if ($present === $infinitive.'r') {
            return "{$infinitive} -r";
        }

        return "{$infinitive} {$present}";
    }

    /**
     * Candidate last elements of a possible compound, longest first.
     *
     * @return array<int, string>
     */
    private static function compoundTails(string $lemma): array
    {
        $tails = [];
        $length = mb_strlen($lemma);

        for ($start = self::COMPOUND_MIN_PREFIX; $length - $start >= self::COMPOUND_MIN_SUFFIX; $start++) {
            $tails[] = mb_substr($lemma, $start);
        }

        return $tails;
    }

    /**
     * Collapse one pair's dictionary rows into the allowed values per field. A noun row
     * with no gender is one the dictionary lets take either article (SALDO's "v" class),
     * so it allows both rather than none.
     */
    private static function optionsFrom(Collection $entries): array
    {
        $options = [];

        foreach ($entries as $entry) {
            if ($entry->part_of_speech === 'noun') {
                foreach ($entry->gender ? [$entry->gender] : ['common', 'neuter'] as $gender) {
                    $options['gender'][] = $gender;
                }
            }

            if (filled($entry->dictionary_form)) {
                $options['dictionary_form'][] = $entry->dictionary_form;
            }
        }

        return array_map(fn ($values) => array_values(array_unique($values)), $options);
    }
}
