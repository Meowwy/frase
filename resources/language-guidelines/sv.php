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

    // Prose interpolated into CALL 1's prompt so the model applies this
    // language's own grammar correctly, beyond the per-attribute notes above.
    'prompt_note' => 'Swedish nouns carry a grammatical gender (see the noun.gender attribute); '
        .'verbs, adjectives and other parts of speech carry no additional attributes.',

];
