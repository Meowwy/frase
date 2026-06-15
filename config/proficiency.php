<?php

/*
|--------------------------------------------------------------------------
| Language Proficiency Levels (CEFR)
|--------------------------------------------------------------------------
|
| A global, hardcoded list of the proficiency levels a user can pick for
| each target language they learn. The keys (A1, A2, …) are stored on the
| `language_user.users_level` pivot column; the descriptions are fed into
| the AI prompts (card + gap-fill generation) to steer the difficulty of
| the language it produces.
|
| The descriptions are written in English but describe HOW the AI should
| write, so they apply no matter which target language it generates in.
|
*/

return [

    // Fallback used when a language has no level set yet.
    'default' => 'A1',

    // Short, human-friendly names shown in the level picker (e.g. "A1 - Beginner").
    'names' => [
        'A1' => 'Beginner',
        'A2' => 'Elementary',
        'B1' => 'Intermediate',
        'B2' => 'Upper-Intermediate',
        'C1' => 'Advanced',
        'C2' => 'Proficiency',
    ],

    'levels' => [
        'A1' => 'Beginner. Use only the most common, everyday words and very short, simple sentences (mostly present tense). Avoid idioms, phrasal expressions, complex grammar and subordinate clauses.',
        'A2' => 'Elementary. Use frequent everyday vocabulary and simple, short sentences. Basic past and future tenses are fine. Avoid idioms and complicated multi-clause sentences.',
        'B1' => 'Intermediate. Use everyday vocabulary plus some less common words. Sentences may be longer and use common connectors and simple subordinate clauses. Use idioms only sparingly.',
        'B2' => 'Upper-intermediate. Use a broad vocabulary including some abstract terms, with varied, longer sentences and subordinate clauses. Occasional common idioms are acceptable.',
        'C1' => 'Advanced. Use rich, precise vocabulary including less frequent and abstract words, complex sentence structures, idioms and nuanced expressions.',
        'C2' => 'Mastery. Write at a sophisticated, native-like level: rare and nuanced vocabulary, figurative language, idioms and complex, fluent sentence structures.',
    ],

];
