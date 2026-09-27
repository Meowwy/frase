<?php

/*
|--------------------------------------------------------------------------
| Language Guideline: English
|--------------------------------------------------------------------------
|
| Consulted by AI::analyzeTerm's prompt builder when it extracts base words
| for an English Term (see docs/ai-integration.md "Language guidelines" and
| docs/cards.md "The vocabulary base"). One file per supported language.
|
| A language with no file here still gets `part_of_speech` tagged on every
| base word (the model does this from general knowledge) — it just proposes
| no `attributes`, which is the correct default for most languages.
|
*/

return [

    'name' => 'English',

    // The parts of speech CALL 1 may tag an English base word with.
    'parts_of_speech' => [
        'noun', 'verb', 'adjective', 'adverb', 'pronoun',
        'preposition', 'conjunction', 'determiner', 'numeral', 'interjection',
    ],

    // Which parts of speech carry extra grammatical attributes, their valid
    // values, and how to display them. English has none: no grammatical
    // gender, no case, no article baked into the dictionary form of a word.
    'attributes' => [],

    // Prose interpolated into CALL 1's prompt so the model applies this
    // language's own grammar correctly.
    'prompt_note' => 'English nouns and verbs carry no grammatical gender, case, or noun class — '
        .'tag only the part of speech, and propose no additional attributes for English words.',

];
