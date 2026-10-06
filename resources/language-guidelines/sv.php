<?php

/*
|--------------------------------------------------------------------------
| Language Guideline: Swedish
|--------------------------------------------------------------------------
|
| Consulted by AI::analyzeTerm's prompt builder when it extracts base words
| for a Swedish Term (see docs/ai-integration.md "Language guidelines" and
| docs/cards.md "The vocabulary base"). One file per supported language.
|
*/

return [

    'name' => 'Swedish',

    // The parts of speech CALL 1 may tag a Swedish base word with.
    'parts_of_speech' => [
        'noun', 'verb', 'adjective', 'adverb', 'pronoun',
        'preposition', 'conjunction', 'determiner', 'numeral', 'interjection',
    ],

    // Which parts of speech carry extra grammatical attributes, their valid
    // values, and how to display them.
    'attributes' => [
        'noun' => [
            'gender' => [
                'values' => ['common', 'neuter'],

                // How to render {gender, lemma} as the word's canonical
                // display form — the form the learner is shown and expected
                // to learn (docs/learning-flow.md "Words mode").
                'display' => [
                    'common' => 'en',
                    'neuter' => 'ett',
                ],

                // Prose interpolated into CALL 1's prompt for this one
                // attribute, so the model knows exactly what's being asked.
                'prompt_note' => 'Every Swedish noun is either a common-gender ("en") word or a '
                    .'neuter ("ett") word — this is fixed per noun, not a free choice, and does '
                    .'not appear as a separate word before the indefinite singular form the way '
                    .'"a"/"an" does in English. Tag it as "common" or "neuter".',
            ],
        ],
    ],

    // Swedish verbs are shown in dictionary style rather than as a bare
    // infinitive. Unlike the noun gender above this cannot be computed from
    // {attribute, lemma}: where the stem ends and which present-tense ending
    // the verb takes are facts about the individual verb, so the whole string
    // is stored on the base word. The lexicon (SALDO) supplies it — and the
    // noun gender — wherever it knows the word; CALL 1's answer, asked for by
    // the notes below, is the fallback (docs/cards.md "The lexicon").
    'dictionary_form' => [
        'parts_of_speech' => ['verb'],

        'prompt_note' => 'A Swedish verb is learned in dictionary style, not as a bare infinitive: '
            .'write the infinitive with a vertical bar "|" marking off the ending that inflection '
            .'replaces, then a space, then the present-tense ending with a leading hyphen — '
            .'"komm|a -er" (kommer), "tal|a -ar" (talar), "köp|a -er" (köper), "skriv|a -er" '
            .'(skriver). Use no bar when the whole infinitive is the stem: "bo -r" (bor), "gå -r" '
            .'(går). When the present tense is irregular, write that form out in full with no '
            .'hyphen instead of an ending: "var|a är" (är), "kunn|a kan" (kan), "vet|a vet" (vet). '
            .'The part before the bar plus the part after it must spell the lemma exactly.',
    ],

    // Prose interpolated into CALL 1's prompt so the model applies this
    // language's own grammar correctly, beyond the per-attribute notes above.
    'prompt_note' => 'Swedish nouns carry a grammatical gender (see the noun.gender attribute); '
        .'verbs are written in dictionary style (see dictionary_form); adjectives and other '
        .'parts of speech carry no additional attributes.',

];
